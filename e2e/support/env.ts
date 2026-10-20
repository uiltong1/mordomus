export const appUrl = process.env.E2E_APP_URL ?? 'http://localhost:5173'

export const apiUrl = process.env.E2E_API_URL ?? 'http://localhost:8080/api/v1'

export const gatewayUrl = process.env.E2E_GATEWAY_URL ?? 'http://localhost:8080'

export const password = 'senha-e2e-123'

export function uniqueEmail(prefix: string): string {
  return `${prefix}.${Date.now()}.${Math.floor(Math.random() * 10_000)}@mordomus.test`
}
