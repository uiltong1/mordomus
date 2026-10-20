import { execFileSync } from 'node:child_process'
import { expect, type APIRequestContext, type Page } from '@playwright/test'
import { apiUrl, password } from './env'

export type Session = {
  accessToken: string
  refreshToken: string
  tenantId: string
  userId: string
  userName: string
}

type Json = Record<string, unknown>

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

export async function invite(
  request: APIRequestContext,
  owner: Session,
  email: string,
): Promise<string> {
  const body = await call(request, 'post', `/identity/tenants/${owner.tenantId}/invitations`, {
    token: owner.accessToken,
    data: { email },
  })

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

export function artisan(command: string, args: string[] = []): void {
  execFileSync(
    'docker',
    ['compose', 'exec', '-T', 'api', 'php', 'artisan', command, ...args],
    { stdio: 'pipe', encoding: 'utf8' },
  )
}

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

export async function signIn(page: Page, email: string, secret = password): Promise<void> {
  await page.goto('/entrar')
  await page.getByLabel('E-mail').fill(email)
  await page.getByLabel('Senha').fill(secret)
  await page.getByRole('button', { name: 'Entrar' }).click()
  await page.waitForURL('/')
}
