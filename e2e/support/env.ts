/**
 * Endereços e senha do ambiente de teste.
 *
 * O padrão é o compose local: painel no Vite em 5173, API atrás do gateway em
 * 8080. O CI pode apontar o runner para outra máquina sem tocar no teste.
 */
export const appUrl = process.env.E2E_APP_URL ?? 'http://localhost:5173'

export const apiUrl = process.env.E2E_API_URL ?? 'http://localhost:8080/api/v1'

export const gatewayUrl = process.env.E2E_GATEWAY_URL ?? 'http://localhost:8080'

/** Senha que satisfaz a política do cadastro em qualquer jornada. */
export const password = 'senha-e2e-123'

/**
 * E-mail novo por jornada.
 *
 * O e-mail é a chave natural da conta: repetir sobrescreveria a casa da rodada
 * anterior e a asserção passaria por cima de estado velho.
 */
export function uniqueEmail(prefix: string): string {
  return `${prefix}.${Date.now()}.${Math.floor(Math.random() * 10_000)}@mordomus.test`
}
