import { expect, test } from '@playwright/test'
import {
  acceptInvitation,
  artisan,
  billOccurrences,
  createBill,
  invite,
  materialize,
  payOccurrence,
  register,
  saveSplitRule,
  splitOf,
} from '../support/api'
import { uniqueEmail } from '../support/env'

/**
 * Jornada 2 — conta e divisão de custo.
 *
 * Cadastro de conta, morador na divisão, cota que fecha em centavos e baixa do
 * vencimento. É o caminho do dono da casa: a conta chega, ele combina quem paga
 * quanto, e a cota de cada um aparece no vencimento antes de qualquer tela
 * abrir.
 *
 * A jornada é inteira pela API porque o módulo financeiro ainda não tem tela —
 * o que ela prova é o contrato HTTP through the gateway, que é o que o
 * aplicativo e qualquer outro cliente vão usar.
 */
test('conta dividida por peso fecha em centavos e a baixa quita o vencimento', async ({
  request,
}) => {
  const ownerEmail = uniqueEmail('dono')
  const tenant = await register(request, {
    name: 'Dona da Casa',
    email: ownerEmail,
    homeName: 'Casa da Divisão',
  })

  const invited = [
    { name: 'Ana Ribeiro', email: uniqueEmail('ana') },
    { name: 'Bruno Lima', email: uniqueEmail('bruno') },
  ]

  const residents: { name: string; userId: string }[] = []

  for (const resident of invited) {
    const invitation = await invite(request, tenant, resident.email)
    const session = await register(request, {
      name: resident.name,
      email: resident.email,
      homeName: 'Casa Antes do Convite',
    })

    await acceptInvitation(request, session, invitation)

    residents.push({ name: resident.name, userId: session.userId })
  }

  // ------------------------------------------------------------- a conta
  // Vencimento no dia de hoje: a cadência mensal cai dentro da janela do
  // materializador sem depender de qual dia do mês o teste rodar.
  const billId = await createBill(request, tenant, {
    name: 'Energia elétrica',
    kind: 'fixed',
    amount: 300,
    dueDay: new Date().getDate(),
    advanceNoticeDays: 3,
  })

  // --------------------------------------------------------- a divisão
  // Peso 2 para quem mora sozinha e 1 para cada um dos outros: 50% da conta
  // para a dona, 25% para cada morador.
  await saveSplitRule(request, tenant, {
    billId,
    mode: 'WEIGHTED',
    entries: [
      { userId: tenant.userId, weight: 2 },
      ...residents.map((resident) => ({ userId: resident.userId, weight: 1 })),
    ],
  })

  await materialize(
    request,
    tenant,
    (rows) => rows.some((row) => row.title === 'Energia elétrica'),
  )
  artisan('financial:project')

  // ------------------------------------------------------------ a cota
  const open = await billOccurrences(request, tenant, '?status=open')
  const occurrence = open.find((row) => row.bill_id === billId)

  expect(occurrence, 'o vencimento da conta deveria existir').toBeTruthy()

  const occurrenceId = String(occurrence?.id)
  const split = await splitOf(request, tenant, occurrenceId)
  const shares = split.shares as { user_id: string; share_amount: string }[]

  expect(shares).toHaveLength(3)
  expect(split.mode).toBe('WEIGHTED')

  const ownerShare = shares.find((share) => share.user_id === tenant.userId)

  expect(ownerShare?.share_amount).toBe('150.00')
  expect(shares.filter((share) => share.user_id !== tenant.userId).map((share) => share.share_amount)).toEqual([
    '75.00',
    '75.00',
  ])

  // A soma fecha no centavo: é o que separa "um terço" de "33,33% de 100,00".
  const sum = shares.reduce((total, share) => total + Number(share.share_amount), 0)

  expect(sum.toFixed(2)).toBe(Number(split.total).toFixed(2))
  expect(sum.toFixed(2)).toBe('300.00')

  // ------------------------------------------------------------ a baixa
  await payOccurrence(request, tenant, occurrenceId, 'pix')

  const settled = await splitOf(request, tenant, occurrenceId)

  expect(settled.status).toBe('paid')

  const openAgain = await billOccurrences(request, tenant, '?status=open')

  expect(openAgain.some((row) => row.id === occurrenceId)).toBe(false)
})
