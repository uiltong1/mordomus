import { beforeEach, describe, expect, it, vi } from 'vitest'
import { registerSW } from '@/test/stubs/pwaRegister'
import { clearInbox, pushToInbox, readInbox, registerServiceWorker } from './registerServiceWorker'

describe('registro do service worker', () => {
  beforeEach(() => {
    vi.mocked(registerSW).mockClear()
  })

  it('registra imediatamente e entrega os handlers de versão e erro ao plugin', () => {
    const onNeedRefresh = vi.fn()
    const onError = vi.fn()

    registerServiceWorker({ onNeedRefresh, onError })

    expect(registerSW).toHaveBeenCalledOnce()
    const config = vi.mocked(registerSW).mock.calls[0]![0] as {
      immediate: boolean
      onNeedRefresh: () => void
      onRegisterError: (error: unknown) => void
    }
    expect(config.immediate).toBe(true)
    expect(config.onNeedRefresh).toBe(onNeedRefresh)
    expect(config.onRegisterError).toBe(onError)
  })

  it('applyUpdate pede a troca da versão ao plugin', async () => {
    const update = vi.fn().mockResolvedValue(undefined)
    vi.mocked(registerSW).mockReturnValueOnce(update as unknown as () => Promise<void>)

    const handle = registerServiceWorker({})
    await handle.applyUpdate()

    expect(update).toHaveBeenCalledWith(true)
  })
})

describe('inbox do sino in-app', () => {
  beforeEach(() => {
    window.localStorage.clear()
  })

  it('guarda a mensagem mais recente primeiro e respeita o limite', () => {
    for (let index = 0; index < 22; index += 1) {
      pushToInbox({ title: `Aviso ${index}`, body: 'conteúdo' })
    }

    const inbox = readInbox()
    expect(inbox).toHaveLength(20)
    expect(inbox[0]!.title).toBe('Aviso 21')
  })

  it('usa os textos padrão quando o push vem sem título nem corpo', () => {
    const [message] = pushToInbox({})

    expect(message?.title).toBe('Mordomus')
    expect(message?.body).toBe('Você tem uma novidade na casa.')
  })

  it('avisa os assinantes para que o sino reaja sem recarregar', () => {
    const listener = vi.fn()
    window.addEventListener('mordomus:inbox', listener)

    pushToInbox({ title: 'Filtro limpo' })

    expect(listener).toHaveBeenCalledOnce()
    expect((listener.mock.calls[0]![0] as CustomEvent).detail).toHaveLength(1)
    window.removeEventListener('mordomus:inbox', listener)
  })

  it('limpa a inbox quando o morador dispensa', () => {
    pushToInbox({ title: 'Aviso' })
    clearInbox()

    expect(readInbox()).toEqual([])
  })

  it('sobrevive a um storage corrompido em vez de quebrar a tela', () => {
    window.localStorage.setItem('mordomus.inbox', '{quebrado')
    expect(readInbox()).toEqual([])
  })
})
