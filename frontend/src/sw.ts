/// <reference lib="webworker" />
import { clientsClaim } from 'workbox-core'
import {
  cleanupOutdatedCaches,
  createHandlerBoundToURL,
  precacheAndRoute,
} from 'workbox-precaching'
import { NavigationRoute, registerRoute } from 'workbox-routing'
import type { PushPayload } from './features/notifications/pushPayload'

/**
 * Service worker do PWA (ADR-001). É `injectManifest` — e não `generateSW` —
 * porque o Web Push precisa do handler de `push` ao lado do precache do shell.
 *
 * Só o shell da aplicação tem rota. A navegação responde o `index.html`
 * precacheado, que é o que faz o app abrir offline e o que faz `/convite/abc`
 * funcionar sem passar pelo servidor. Não há rota para `/api/`, então nenhum
 * dado de residência sobrevive no cache do worker — o mesmo cuidado da R6, que
 * é o mesmo motivo pelo qual as query keys levam o tenant.
 */
declare const self: ServiceWorkerGlobalScope & {
  __WB_MANIFEST: Parameters<typeof precacheAndRoute>[0]
}

precacheAndRoute(self.__WB_MANIFEST)
cleanupOutdatedCaches()
clientsClaim()

registerRoute(
  new NavigationRoute(createHandlerBoundToURL('/index.html'), { denylist: [/^\/api\//] }),
)

self.addEventListener('push', (event: PushEvent) => {
  const payload = readPayload(event.data)
  const options: NotificationOptions = {
    body: payload.body ?? 'Você tem uma novidade na casa.',
    icon: payload.icon ?? '/icon-192.png',
    badge: payload.badge ?? '/icon-192.png',
    tag: payload.tag ?? 'mordomus',
    data: { url: payload.url ?? '/', payload: payload.data ?? null },
  }
  event.waitUntil(self.registration.showNotification(payload.title ?? 'Mordomus', options))
})

self.addEventListener('notificationclick', (event: NotificationEvent) => {
  event.notification.close()
  const target = (event.notification.data as { url?: string } | null)?.url ?? '/'
  event.waitUntil(openWindow(target))
})

self.addEventListener('message', (event: ExtendableMessageEvent) => {
  // o registro é `prompt`: quem troca a versão é o app, com o morador avisado
  if (event.data === 'SKIP_WAITING') void self.skipWaiting()
})

/** Reaproveita a aba que já está na rota pedida; senão abre uma nova. */
async function openWindow(path: string): Promise<void> {
  const url = new URL(path, self.location.origin)
  const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
  for (const client of windows) {
    if (new URL(client.url).pathname === url.pathname) {
      await client.focus()
      return
    }
  }
  await self.clients.openWindow(url.href)
}

function readPayload(data: PushMessageData | null): PushPayload {
  if (!data) return {}
  try {
    return data.json() as PushPayload
  } catch {
    // payload em texto puro: a mensagem vira o corpo da notificação
    return { body: data.text() }
  }
}
