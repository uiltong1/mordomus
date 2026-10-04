import { execFileSync } from 'node:child_process'
import { expect, type APIRequestContext, type Page } from '@playwright/test'
import { apiUrl, password } from './env'

/**
 * Cliente da API pelo gateway.
 *
 * O contexto de requisição do Playwright é o mesmo do navegador: as duas coisas
 * saem por `localhost:8080`, então o rate limit, o `X-Request-Id` e o descarte
 * de `X-Tenant-ID` do gateway valem para as duas pontas da jornada.
 */

export type Session = {
  accessToken: string
  refreshToken: string
  tenantId: string
  userId: string
  userName: string
}

type Json = Record<string, unknown>

/**
 * Chamada com repetição em 429.
 *
 * O gateway limita a 10 req/s com burst de 30 por IP, e uma jornada dispara
 * leitura de tela, assertion e chamada ao mesmo tempo. Insistir em 429 é o
 * comportamento de um cliente com retry, não de um teste que finge que o limite
 * não existe: o que não pode passar é o 4xx de verdade.
 */
async function call(
  request: APIRequestContext,
  method: 'get' | 'post' | 'put' | 'patch' | 'delete',
  path: string,
  options: { token?: string; data?: unknown } = {},
): Promise<Json> {
  const headers: Record<string, string> = { Accept: 'application/json' }

  if (options.token) headers.Authorization = `Bearer ${options.token}`

  for (let attempt = 0; attempt < 6; attempt += 1) {
    const response = await request[method](`${apiUrl}${path}`, {
      headers,
      data: options.data,
      failOnStatusCode: false,
    })

    if (response.status() === 429) {
      await new Promise((resolve) => setTimeout(resolve, 500 * (attempt + 1)))
      continue
    }

    const body = (await response.json().catch(() => ({}))) as Json

    expect(
      response.status(),
      `${method.toUpperCase()} ${path} respondeu ${response.status()}: ${JSON.stringify(body)}`,
    ).toBeLessThan(400)

    return body
  }

  throw new Error(`${method.toUpperCase()} ${path} continuou em 429 depois das tentativas`)
}

function sessionOf(body: Json): Session {
  return {
    accessToken: String(body.access_token),
    refreshToken: String(body.refresh_token),
    tenantId: String(body.active_tenant),
    userId: String((body.user as Json).id),
    userName: String((body.user as Json).name),
  }
}

/** Conta nova com residência própria, como o formulário de cadastro faz. */
export async function register(
  request: APIRequestContext,
  person: { name: string; email: string; homeName: string },
): Promise<Session> {
  const body = await call(request, 'post', '/identity/auth/register', {
    data: {
      name: person.name,
      email: person.email,
      password,
      password_confirmation: password,
      home_name: person.homeName,
    },
  })

  return sessionOf(body)
}

export async function login(
  request: APIRequestContext,
  email: string,
  secret = password,
): Promise<Session> {
  return sessionOf(
    await call(request, 'post', '/identity/auth/login', {
      data: { email, password: secret },
    }),
  )
}

/** Convite do owner; devolve o token puro, que é o que vai na URL `/convite/`. */
export async function invite(
  request: APIRequestContext,
  owner: Session,
  email: string,
): Promise<string> {
  const body = await call(request, 'post', `/identity/tenants/${owner.tenantId}/invitations`, {
    token: owner.accessToken,
    data: { email },
  })

  // O token é irmão de `data` na resposta, não campo do convite: ele só existe
  // enquanto não há envio por e-mail.
  return String(body.token)
}

export async function acceptInvitation(
  request: APIRequestContext,
  session: Session,
  token: string,
): Promise<Session> {
  return sessionOf(
    await call(request, 'post', `/identity/invitations/${token}/accept`, {
      token: session.accessToken,
    }),
  )
}

/**
 * Token com a residência ativa trocada.
 *
 * Depois que um convite é aceito no navegador, a sessão do teste continua
 * apontando para a casa anterior do usuário: trocar de residência pela API é o
 * mesmo caminho que o seletor do painel usa.
 */
export async function switchTenant(
  request: APIRequestContext,
  session: Session,
  tenantId: string,
): Promise<Session> {
  return sessionOf(
    await call(request, 'post', '/identity/auth/switch-tenant', {
      token: session.accessToken,
      data: { tenant_id: tenantId },
    }),
  )
}

export async function createRoom(
  request: APIRequestContext,
  session: Session,
  room: { name: string; icon?: string },
): Promise<string> {
  const body = await call(request, 'post', '/maintenance/rooms', {
    token: session.accessToken,
    data: room,
  })

  return String((body.data as Json).id)
}

export async function createAsset(
  request: APIRequestContext,
  session: Session,
  asset: { roomId: string; name: string; category?: string; brand?: string; model?: string },
): Promise<string> {
  const body = await call(request, 'post', '/maintenance/assets', {
    token: session.accessToken,
    data: {
      room_id: asset.roomId,
      name: asset.name,
      category: asset.category,
      brand: asset.brand,
      model: asset.model,
    },
  })

  return String((body.data as Json).id)
}

export async function createBill(
  request: APIRequestContext,
  session: Session,
  bill: { name: string; kind: 'fixed' | 'variable'; amount?: number; dueDay?: number; advanceNoticeDays?: number },
): Promise<string> {
  const body = await call(request, 'post', '/financial/bills', {
    token: session.accessToken,
    data: {
      name: bill.name,
      kind: bill.kind,
      amount: bill.amount,
      category: 'utilidades',
      due_day: bill.dueDay,
      advance_notice_days: bill.advanceNoticeDays ?? 3,
    },
  })

  return String((body.data as Json).id)
}

export async function saveSplitRule(
  request: APIRequestContext,
  session: Session,
  rule: { billId: string; mode: 'EQUAL' | 'WEIGHTED' | 'PERCENT' | 'CUSTOM'; entries: { userId: string; weight?: number }[] },
): Promise<void> {
  await call(request, 'put', '/financial/split-rules', {
    token: session.accessToken,
    data: {
      bill_id: rule.billId,
      mode: rule.mode,
      entries: rule.entries.map((entry) => ({ user_id: entry.userId, weight: entry.weight })),
    },
  })
}

export async function occurrences(
  request: APIRequestContext,
  session: Session,
  query = '',
): Promise<Json[]> {
  const body = await call(request, 'get', `/scheduling/occurrences${query}`, {
    token: session.accessToken,
  })

  return body.data as Json[]
}

export async function billOccurrences(
  request: APIRequestContext,
  session: Session,
  query = '',
): Promise<Json[]> {
  const body = await call(request, 'get', `/financial/occurrences${query}`, {
    token: session.accessToken,
  })

  return body.data as Json[]
}

export async function splitOf(
  request: APIRequestContext,
  session: Session,
  occurrenceId: string,
): Promise<Json> {
  const body = await call(request, 'get', `/financial/occurrences/${occurrenceId}/split`, {
    token: session.accessToken,
  })

  return body.data as Json
}

export async function payOccurrence(
  request: APIRequestContext,
  session: Session,
  occurrenceId: string,
  method = 'pix',
): Promise<void> {
  await call(request, 'post', `/financial/occurrences/${occurrenceId}/paid`, {
    token: session.accessToken,
    data: { method },
  })
}

export async function notificationLogs(
  request: APIRequestContext,
  session: Session,
): Promise<Json[]> {
  const body = await call(request, 'get', '/notification/logs', { token: session.accessToken })

  return body.data as Json[]
}

/**
 * Preferência de entrega do morador.
 *
 * `daily` é o caminho que produz linha de notificação sem serviço de push: o
 * consumidor só registra aviso de push para quem tem assinatura, e o ambiente
 * de desenvolvimento não tem provedor.
 */
export async function setNotificationPreference(
  request: APIRequestContext,
  session: Session,
  preference: { digest?: 'instant' | 'daily'; preferredHour?: string; quietStart?: string; quietEnd?: string },
): Promise<void> {
  await call(request, 'put', '/notification/preferences', {
    token: session.accessToken,
    data: {
      digest: preference.digest,
      preferred_hour: preference.preferredHour,
      quiet_start: preference.quietStart,
      quiet_end: preference.quietEnd,
    },
  })
}

/**
 * Comando de console do monólito.
 *
 * A agenda é materializada pelo scheduler a cada dia e o aviso sai no varrimento
 * de 15 minutos. Uma jornada não pode esperar o relógio, e esperar deixaria o
 * teste passar sem provar o caminho real — então o comando roda na hora, no
 * mesmo container que o `api-worker` usa.
 */
export function artisan(command: string, args: string[] = []): void {
  execFileSync(
    'docker',
    ['compose', 'exec', '-T', 'api', 'php', 'artisan', command, ...args],
    { stdio: 'pipe', encoding: 'utf8' },
  )
}

/**
 * Materializa e espera a fila responder.
 *
 * `scheduling:materialize` enfileira uma tarefa por residência: a agenda existe
 * quando o `api-worker` consome, não quando o comando volta. Insistir no
 * polling é o que fecha a lacuna entre "comando rodou" e "a casa tem o que
 * fazer".
 */
export async function materialize(
  request: APIRequestContext,
  session: Session,
  predicate: (rows: Json[]) => boolean,
  options: { command?: string; args?: string[]; timeout?: number } = {},
): Promise<void> {
  artisan(options.command ?? 'scheduling:materialize', options.args ?? ['--days=45'])

  await expect
    .poll(async () => predicate(await occurrences(request, session)), {
      timeout: options.timeout ?? 30_000,
      intervals: [500, 1000, 2000, 3000],
    })
    .toBe(true)
}

/** Entra pelo formulário de verdade: o que a jornada prova começa no navegador. */
export async function signIn(page: Page, email: string, secret = password): Promise<void> {
  await page.goto('/entrar')
  await page.getByLabel('E-mail').fill(email)
  await page.getByLabel('Senha').fill(secret)
  await page.getByRole('button', { name: 'Entrar' }).click()
  await page.waitForURL('/')
}
