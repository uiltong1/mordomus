import { expect, test } from '@playwright/test'
import {
  invite,
  materialize,
  notificationLogs,
  register,
  setNotificationPreference,
  signIn,
  switchTenant,
} from '../support/api'
import { uniqueEmail } from '../support/env'

test('convite leva à casa, a regra vira agenda e o morador novo é notificado', async ({
  page,
  request,
  browser,
}) => {
  const ownerEmail = uniqueEmail('dono')
  const guestEmail = uniqueEmail('convidado')

  const owner = await register(request, {
    name: 'Dona da Casa',
    email: ownerEmail,
    homeName: 'Casa da Jornada',
  })

  const invitation = await invite(request, owner, guestEmail)

  const guest = await register(request, {
    name: 'Morador Convidado',
    email: guestEmail,
    homeName: 'Casa Antes do Convite',
  })

  const guestContext = await browser.newContext()
  const guestPage = await guestContext.newPage()
  await signIn(guestPage, guestEmail)
  await guestPage.goto(`/convite/${invitation}`)

  await expect(guestPage.getByRole('heading', { level: 1 })).toHaveText('Casa da Jornada')

  const neighbour = await switchTenant(request, guest, owner.tenantId)

  await setNotificationPreference(request, neighbour, {
    digest: 'daily',
    preferredHour: '10:00',
  })

  await signIn(page, ownerEmail)

  await page.getByRole('button', { name: 'Novo cômodo' }).click()
  const roomDialog = page.getByRole('dialog', { name: 'Novo cômodo' })
  await roomDialog.getByLabel('Nome do cômodo').fill('Cozinha')
  await roomDialog.getByLabel('Ícone').fill('🍳')
  await roomDialog.getByRole('button', { name: 'Criar cômodo' }).click()
  await expect(roomDialog).toBeHidden()

  await page
    .getByTestId('room-cards')
    .getByRole('listitem')
    .filter({ hasText: 'Cozinha' })
    .getByRole('link', { name: 'Abrir cômodo' })
    .click()

  await page
    .getByRole('region', { name: 'Inventário' })
    .getByRole('button', { name: 'Adicionar item' })
    .click()
  const assetDialog = page.getByRole('dialog', { name: 'Novo item' })
  await assetDialog.getByLabel('Nome do item').fill('Geladeira')
  await assetDialog.getByLabel('Cômodo').selectOption({ label: '🍳 Cozinha' })
  await assetDialog.getByLabel('Marca').fill('Brastemp')
  await assetDialog.getByLabel('Modelo').fill('Frost Free 300')
  await assetDialog.getByRole('button', { name: 'Adicionar' }).click()
  await expect(assetDialog).toBeHidden()

  const assetRow = page
    .getByTestId('room-assets')
    .getByRole('listitem')
    .filter({ hasText: 'Geladeira' })

  await assetRow.getByRole('button', { name: 'Regra' }).click()
  const ruleDialog = page.getByRole('dialog', { name: 'Nova regra de manutenção' })
  await ruleDialog.getByLabel('Repetir a cada X dias').check()
  await ruleDialog.getByLabel('Nome da regra').fill('Limpeza das serpentinas')
  await ruleDialog.getByLabel('De quantos em quantos').fill('21')
  await ruleDialog.getByLabel('Avisar quantos dias antes').fill('3')
  await ruleDialog.getByRole('button', { name: 'Criar regra' }).click()
  await expect(ruleDialog).toBeHidden()

  await expect(assetRow).toContainText('A cada 21 dias')

  await materialize(
    request,
    owner,
    (rows) => rows.some((row) => row.title === 'Limpeza das serpentinas'),
  )

  await guestPage.goto('/agenda')

  const row = guestPage
    .getByTestId('occurrence-row')
    .filter({ hasText: 'Limpeza das serpentinas' })

  await expect(row).toBeVisible()
  await row.getByRole('button', { name: 'Concluir' }).click()

  await expect(guestPage.getByRole('status').filter({ hasText: 'Tarefa concluída.' })).toBeVisible()
  await expect(row).toHaveAttribute('data-status', 'completed')

  await expect
    .poll(
      async () => {
        const logs = await notificationLogs(request, neighbour)

        return logs.some((log) => log.template === 'mordomus::tenant.member_added')
      },
      { timeout: 30_000, intervals: [500, 1000, 2000, 3000] },
    )
    .toBe(true)

  await guestContext.close()
})
