import { api } from '@/shared/api/client'
import type { DeviceRegistration } from './types'

export function registerDevice(subscription: PushSubscription): Promise<DeviceRegistration> {
  const json = subscription.toJSON()
  return api.post<DeviceRegistration>('/notification/devices', {
    platform: 'web',
    endpoint: json.endpoint ?? subscription.endpoint,
    p256dh: json.keys?.p256dh ?? '',
    auth: json.keys?.auth ?? '',
  })
}

export function removeDevice(endpoint: string): Promise<void> {
  return api.delete('/notification/devices', { query: { endpoint } })
}
