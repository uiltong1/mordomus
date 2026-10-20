export function isPushSupported(): boolean {
  return (
    typeof window !== 'undefined' &&
    'Notification' in window &&
    'serviceWorker' in navigator &&
    'PushManager' in window
  )
}

export function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
  const padding = '='.repeat((4 - (base64.length % 4)) % 4)
  const normalized = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = window.atob(normalized)
  const bytes = new Uint8Array(new ArrayBuffer(raw.length))
  for (let index = 0; index < raw.length; index += 1) {
    bytes[index] = raw.charCodeAt(index)
  }
  return bytes
}

export function currentPermission(): NotificationPermission {
  return isPushSupported() ? Notification.permission : 'denied'
}

export async function askPermission(): Promise<NotificationPermission> {
  if (!isPushSupported()) return 'denied'
  return Notification.requestPermission()
}

async function registration(): Promise<ServiceWorkerRegistration> {
  const ready = await navigator.serviceWorker.ready
  return ready
}

export async function currentSubscription(): Promise<PushSubscription | null> {
  if (!isPushSupported()) return null
  const reg = await registration()
  return reg.pushManager.getSubscription()
}

export async function subscribePush(vapidPublicKey: string): Promise<PushSubscription> {
  const reg = await registration()
  const existing = await reg.pushManager.getSubscription()
  if (existing) return existing

  const permission = await askPermission()
  if (permission !== 'granted') throw new Error('Permissão de notificação negada.')

  return reg.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
  })
}

export async function unsubscribePush(): Promise<void> {
  const subscription = await currentSubscription()
  await subscription?.unsubscribe()
}
