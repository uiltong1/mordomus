/**
 * Contrato da mensagem de Web Push (ADR-001). Fica fora do service worker
 * porque os dois lados precisam concordar: o worker mostra a notificação, o
 * app monta o payload ao assinar.
 */
export interface PushPayload {
  title?: string
  body?: string
  icon?: string
  badge?: string
  /** substitui a notificação anterior com a mesma tag em vez de acumular. */
  tag?: string
  /** rota interna aberta ao tocar na notificação. */
  url?: string
  data?: unknown
}
