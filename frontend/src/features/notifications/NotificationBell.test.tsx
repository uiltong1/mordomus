import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ApiError } from '@/shared/api/errors'
import { renderWithProviders } from '@/test/utils'
import { NotificationBell } from './NotificationBell'
import { registerDevice, removeDevice } from './devicesApi'

vi.mock('./devicesApi', () => ({ registerDevice: vi.fn(), removeDevice: vi.fn() }))

const subscription = {
  endpoint: 'https://push.example/abc',
  toJSON: () => ({
    endpoint: 'https://push.example/abc',
    keys: { p256dh: 'chave-publica', auth: 'segredo' },
  }),
  unsubscribe: vi.fn(),
} as unknown as PushSubscription

function installPushApi(permission: NotificationPermission = 'granted') {
  Object.defineProperty(window, 'Notification', {
    configurable: true,
    value: { permission, requestPermission: vi.fn().mockResolvedValue(permission) },
  })
  Object.defineProperty(window, 'PushManager', { configurable: true, value: class {} })
  Object.defineProperty(navigator, 'serviceWorker', {
    configurable: true,
    value: {
      ready: Promise.resolve({
        pushManager: {
          subscribe: vi.fn().mockResolvedValue(subscription),
          getSubscription: vi.fn().mockResolvedValue(null),
        },
      }),
    },
  })
}

async function openBell() {
  await userEvent.click(screen.getByRole('button', { name: /Notificações/ }))
}

describe('NotificationBell', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('não pede permissão sozinho: o push só começa no clique', async () => {
    installPushApi('default')
    const requestPermission = vi.fn().mockResolvedValue('granted')
    Object.defineProperty(window, 'Notification', {
      configurable: true,
      value: { permission: 'default', requestPermission },
    })

    renderWithProviders(<NotificationBell />)
    await openBell()
    await screen.findByRole('button', { name: 'Ativar notificações' })

    expect(requestPermission).not.toHaveBeenCalled()
  })

  it('assina o push e avisa quando o backend ainda não tem a rota', async () => {
    installPushApi('granted')
    // a rota nasce na T6.1; até lá o POST responde 404 pelo gateway
    vi.mocked(registerDevice).mockRejectedValue(
      new ApiError(404, {
        code: 'not_found',
        message: 'Rota não encontrada no gateway do Mordomus',
      }),
    )

    renderWithProviders(<NotificationBell />)
    await openBell()
    await userEvent.click(screen.getByRole('button', { name: 'Ativar notificações' }))

    expect(registerDevice).toHaveBeenCalledWith(subscription)
    expect(
      await screen.findByText('Ativas no aparelho, ainda não sincronizadas com o servidor.'),
    ).toBeInTheDocument()
  })

  it('tira a assinatura do backend ao desativar, não só do aparelho', async () => {
    // aparelho que já está inscrito: o sino mostra "Desativar" direto
    installPushApi('granted')
    Object.defineProperty(navigator, 'serviceWorker', {
      configurable: true,
      value: {
        ready: Promise.resolve({
          pushManager: {
            getSubscription: vi.fn().mockResolvedValue(subscription),
            subscribe: vi.fn(),
          },
        }),
      },
    })
    vi.mocked(removeDevice).mockResolvedValue(undefined)

    renderWithProviders(<NotificationBell />)
    await openBell()
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Desativar' })).toBeInTheDocument(),
    )

    await userEvent.click(screen.getByRole('button', { name: 'Desativar' }))

    await waitFor(() => expect(removeDevice).toHaveBeenCalledWith(subscription.endpoint))
    expect(subscription.unsubscribe).toHaveBeenCalled()
  })

  it('volta a oferecer ativar quando o device é desinscrito', async () => {
    installPushApi('granted')
    Object.defineProperty(navigator, 'serviceWorker', {
      configurable: true,
      value: {
        ready: Promise.resolve({
          pushManager: {
            getSubscription: vi.fn().mockResolvedValue(subscription),
            subscribe: vi.fn(),
          },
        }),
      },
    })
    vi.mocked(removeDevice).mockResolvedValue(undefined)

    renderWithProviders(<NotificationBell />)
    await openBell()
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Desativar' })).toBeInTheDocument(),
    )
    await userEvent.click(screen.getByRole('button', { name: 'Desativar' }))

    expect(await screen.findByRole('button', { name: 'Ativar notificações' })).toBeInTheDocument()
  })

  it('explica a recusa em vez de falhar em silêncio', async () => {
    installPushApi('denied')
    Object.defineProperty(window, 'Notification', {
      configurable: true,
      value: { permission: 'denied', requestPermission: vi.fn().mockResolvedValue('denied') },
    })

    renderWithProviders(<NotificationBell />)
    await openBell()
    await userEvent.click(screen.getByRole('button', { name: 'Ativar notificações' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Permissão de notificação negada.')
  })

  it('avisa que o navegador não suporta push em vez de sumir', async () => {
    // @ts-expect-error removendo a API para simular o navegador antigo
    delete window.Notification

    renderWithProviders(<NotificationBell />)
    await openBell()

    await waitFor(() =>
      expect(screen.getByText('Este navegador não recebe notificações.')).toBeInTheDocument(),
    )
  })
})
