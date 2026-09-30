/**
 * Web Push no navegador (ADR-001). O canal de envio é do monólito; aqui ficam
 * só a permissão, a inscrição e a assinatura que o backend vai usar.
 */
export function isPushSupported(): boolean {
  return (
    typeof window !== 'undefined' &&
    'Notification' in window &&
    'serviceWorker' in navigator &&
    'PushManager' in window
  )
}

/** A chave VAPID vem em base64url; a Push API quer bytes. */
export function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
  const padding = '='.repeat((4 - (base64.length % 4)) % 4)
  const normalized = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = window.atob(normalized)
  // buffer próprio: a Push API recusa view sobre ArrayBuffer compartilhado
  const bytes = new Uint8Array(new ArrayBuffer(raw.length))
  for (let index = 0; index < raw.length; index += 1) {
    bytes[index] = raw.charCodeAt(index)
  }
  return bytes
}

export function currentPermission(): NotificationPermission {
  return isPushSupported() ? Notification.permission : 'denied'
}

/** A permissão só é pedida depois de ação explícita do morador (TECHSPEC §4.7). */
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

  // Safari exige a assinatura dentro do gesto do usuário; a tela do sino já
  // é um clique, então a permissão é pedida antes de chegar aqui
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
