/// <reference lib="webworker" />
import { clientsClaim } from 'workbox-core'
import {
  cleanupOutdatedCaches,
  createHandlerBoundToURL,
  precacheAndRoute,
} from 'workbox-precaching'
import { NavigationRoute, registerRoute } from 'workbox-routing'
import type { PushPayload } from './features/notifications/pushPayload'

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
  if (event.data === 'SKIP_WAITING') void self.skipWaiting()
})

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
    return { body: data.text() }
  }
}
