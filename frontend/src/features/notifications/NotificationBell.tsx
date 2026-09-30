import { useEffect, useState } from 'react'
import { Button } from '@/shared/ui'
import { clearInbox, readInbox, type InboxMessage } from './registerServiceWorker'
import { usePushSubscription } from './usePushSubscription'

/**
 * Sino in-app (T4.1.8). O push do sistema cobre o app fechado; o sino cobre o
 * app aberto e a mensagem que chegou enquanto ninguém olhava.
 */
export function NotificationBell() {
  const [open, setOpen] = useState(false)
  const [messages, setMessages] = useState<InboxMessage[]>(() => readInbox())
  const push = usePushSubscription()

  useEffect(() => {
    const onMessage = (event: Event) => {
      const detail = (event as CustomEvent<InboxMessage[]>).detail
      if (Array.isArray(detail)) setMessages(detail)
    }
    window.addEventListener('mordomus:inbox', onMessage)
    return () => window.removeEventListener('mordomus:inbox', onMessage)
  }, [])

  const unread = messages.length

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        aria-haspopup="dialog"
        aria-label={unread > 0 ? `Notificações (${unread} não lidas)` : 'Notificações'}
        className="relative flex size-9 items-center justify-center rounded-control text-ink-muted transition-colors hover:bg-surface-2 hover:text-ink"
      >
        <span aria-hidden="true">🔔</span>
        {unread > 0 ? (
          <span className="absolute right-0 top-0 flex min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold text-canvas">
            {unread > 9 ? '9+' : unread}
          </span>
        ) : null}
      </button>

      {open ? (
        <div
          role="dialog"
          aria-label="Notificações"
          className="absolute right-0 z-40 mt-2 w-80 rounded-card border border-line bg-surface p-3 shadow-pop"
        >
          <div className="mb-2 flex items-center justify-between">
            <p className="text-xs font-semibold text-ink">Notificações</p>
            {unread > 0 ? (
              <button
                type="button"
                onClick={() => {
                  clearInbox()
                  setMessages([])
                }}
                className="text-[11px] text-ink-subtle hover:text-ink"
              >
                limpar
              </button>
            ) : null}
          </div>

          {messages.length === 0 ? (
            <p className="px-1 py-4 text-center text-xs text-ink-subtle">Nada por aqui ainda.</p>
          ) : (
            <ul className="max-h-64 space-y-1 overflow-y-auto">
              {messages.map((message) => (
                <li key={message.id} className="rounded-control bg-surface-2 px-3 py-2">
                  <p className="text-xs font-medium text-ink">{message.title}</p>
                  <p className="mt-0.5 text-xs text-ink-muted">{message.body}</p>
                </li>
              ))}
            </ul>
          )}

          <div className="mt-3 border-t border-line pt-3">
            {push.state === 'unsupported' ? (
              <p className="text-[11px] text-ink-subtle">Este navegador não recebe notificações.</p>
            ) : push.isSubscribed ? (
              <div className="space-y-2">
                <p className="text-[11px] text-ink-muted">Notificações ativas neste aparelho.</p>
                {push.isBackendMissing ? (
                  <p className="text-[11px] text-warning">
                    Ativas no aparelho, ainda não sincronizadas com o servidor.
                  </p>
                ) : null}
                <Button variant="ghost" size="sm" onClick={push.disable} loading={push.isPending}>
                  Desativar
                </Button>
              </div>
            ) : (
              <div className="space-y-2">
                <p className="text-[11px] text-ink-muted">
                  Receba lembretes de manutenção no celular.
                </p>
                {push.error ? (
                  <p role="alert" className="text-[11px] text-warning">
                    {push.error}
                  </p>
                ) : null}
                <Button size="sm" onClick={push.enable} loading={push.isPending}>
                  Ativar notificações
                </Button>
              </div>
            )}
          </div>
        </div>
      ) : null}
    </div>
  )
}
