import { api } from '@/shared/api/client'
import type { DeviceRegistration } from './types'

/**
 * Registro do device no backend (T4.1.8).
 *
 * O par de rotas `/api/v1/notification/devices` é escopo da T6.1 e ainda não
 * existe no monólito: enquanto lá, estas chamadas respondem 404. Elas ficam
 * isoladas neste módulo — trocar o contrato quando a rota nascer é mexer só
 * aqui, e a interface trata a falha como "push indisponível", não como erro de
 * tela.
 */
export function registerDevice(subscription: PushSubscription): Promise<DeviceRegistration> {
  const json = subscription.toJSON()
  return api.post<DeviceRegistration>('/notification/devices', {
    platform: 'web',
    endpoint: json.endpoint ?? subscription.endpoint,
    p256dh: json.keys?.p256dh ?? '',
    auth: json.keys?.auth ?? '',
  })
}

/** O backend identifica o device pelo endpoint; é a chave natural da tabela. */
export function removeDevice(endpoint: string): Promise<void> {
  return api.delete('/notification/devices', { query: { endpoint } })
}
