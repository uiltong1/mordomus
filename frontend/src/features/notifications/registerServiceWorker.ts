import { registerSW } from 'virtual:pwa-register'
import type { PushPayload } from '@/features/notifications/pushPayload'

/**
 * Registro do service worker (T4.1.7).
 *
 * O registro fica em `main.tsx` (e não no plugin) para que a UI possa avisar
 * que há versão nova e pedir o aceite do morador — com `registerType: 'prompt'`
 * o worker novo espera, então a troca é sempre deliberada.
 */
export interface ServiceWorkerHandle {
  /** Aplica a versão que está esperando. */
  applyUpdate: () => void
}

export function registerServiceWorker(handlers: {
  onNeedRefresh?: () => void
  onOfflineReady?: () => void
  onError?: (error: unknown) => void
}): ServiceWorkerHandle {
  const update = registerSW({
    immediate: true,
    onNeedRefresh: handlers.onNeedRefresh,
    onOfflineReady: handlers.onOfflineReady,
    onRegisterError: handlers.onError,
  })

  return { applyUpdate: () => void update(true) }
}

/**
 * Mensagens que o app mostra dentro do sino. A notificação do sistema já é do
 * worker; o sino cobre o app aberto e o caso da permissão negada.
 */
const INBOX_KEY = 'mordomus.inbox'

export interface InboxMessage {
  id: string
  title: string
  body: string
  receivedAt: string
}

export function readInbox(): InboxMessage[] {
  try {
    const raw = window.localStorage.getItem(INBOX_KEY)
    return raw ? (JSON.parse(raw) as InboxMessage[]) : []
  } catch {
    return []
  }
}

export function pushToInbox(payload: PushPayload): InboxMessage[] {
  const message: InboxMessage = {
    id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    title: payload.title ?? 'Mordomus',
    body: payload.body ?? 'Você tem uma novidade na casa.',
    receivedAt: new Date().toISOString(),
  }
  const next = [message, ...readInbox()].slice(0, 20)
  try {
    window.localStorage.setItem(INBOX_KEY, JSON.stringify(next))
  } catch {
    // sem storage o sino mostra apenas o que está em memória
  }
  window.dispatchEvent(new CustomEvent('mordomus:inbox', { detail: next }))
  return next
}

export function clearInbox(): void {
  try {
    window.localStorage.removeItem(INBOX_KEY)
  } catch {
    // idem
  }
  window.dispatchEvent(new CustomEvent('mordomus:inbox', { detail: [] }))
}
