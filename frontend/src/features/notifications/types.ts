export interface DeviceRegistration {
  id: string
  platform: 'web' | 'android' | 'ios'
  endpoint: string
  p256dh: string
  auth: string
  last_seen_at: string | null
}
