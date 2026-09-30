import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { act, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ThemeProvider, useTheme } from './ThemeProvider'
import { ThemeToggle } from './ThemeToggle'
import {
  DARK_SCHEME_QUERY,
  THEME_ATTRIBUTE,
  BROWSER_CHROME_COLOR,
  THEME_STORAGE_KEY,
  applyTheme,
  isThemePreference,
  nextPreference,
  readStoredPreference,
  resolveTheme,
  storePreference,
  type ResolvedTheme,
  type ThemePreference,
} from './theme'

type MediaListener = (event: MediaQueryListEvent) => void

/**
 * jsdom não implementa `matchMedia`, então o dublê também é o meio de provoke a
 * mudança de preferência do sistema: o provider só reage ao evento `change`.
 */
function stubMatchMedia(initialDark: boolean) {
  const listeners = new Set<MediaListener>()
  const query = {
    media: DARK_SCHEME_QUERY,
    matches: initialDark,
    onchange: null,
    addEventListener: (_type: string, listener: MediaListener) => listeners.add(listener),
    removeEventListener: (_type: string, listener: MediaListener) => listeners.delete(listener),
    addListener: (listener: MediaListener) => listeners.add(listener),
    removeListener: (listener: MediaListener) => listeners.delete(listener),
    dispatchEvent: () => false,
  }

  const original = window.matchMedia
  window.matchMedia = vi.fn(() => query) as unknown as typeof window.matchMedia

  return {
    set(matches: boolean) {
      query.matches = matches
      for (const listener of listeners) listener({ matches } as MediaQueryListEvent)
    },
    restore() {
      window.matchMedia = original
    },
  }
}

function currentTheme(): ResolvedTheme | null {
  return document.documentElement.getAttribute(THEME_ATTRIBUTE) as ResolvedTheme | null
}

function Probe() {
  const { preference, theme } = useTheme()
  return <span data-testid="probe">{`${preference}:${theme}`}</span>
}

describe('resolução do tema', () => {
  it('segue a preferência do sistema quando o morador não escolheu', () => {
    expect(resolveTheme('system', true)).toBe('dark')
    expect(resolveTheme('system', false)).toBe('light')
  })

  it('a escolha explícita vence a preferência do sistema', () => {
    expect(resolveTheme('light', true)).toBe('light')
    expect(resolveTheme('dark', false)).toBe('dark')
  })

  it('reconhece só as três preferências que o app entende', () => {
    expect(isThemePreference('light')).toBe(true)
    expect(isThemePreference('system')).toBe(true)
    expect(isThemePreference('roxo')).toBe(false)
    expect(isThemePreference(null)).toBe(false)
  })

  it('o ciclo do botão fecha em si mesmo: claro → escuro → sistema → claro', () => {
    expect(nextPreference('light')).toBe('dark')
    expect(nextPreference('dark')).toBe('system')
    expect(nextPreference('system')).toBe('light')
  })
})

describe('preferência guardada', () => {
  it('lê o que foi gravado na chave do app', () => {
    storePreference('dark')
    expect(window.localStorage.getItem(THEME_STORAGE_KEY)).toBe('dark')
    expect(readStoredPreference()).toBe('dark')
  })

  it('ignora valor que não é preferência — resto de versão antiga ou lixo', () => {
    window.localStorage.setItem(THEME_STORAGE_KEY, 'neon')
    expect(readStoredPreference()).toBe('system')
  })

  it('sem storage disponível responde system em vez de quebrar a tela', () => {
    const original = window.localStorage.getItem
    window.localStorage.getItem = () => {
      throw new Error('storage bloqueado')
    }
    expect(readStoredPreference()).toBe('system')

    window.localStorage.setItem = () => {
      throw new Error('storage bloqueado')
    }
    expect(() => storePreference('light')).not.toThrow()
    window.localStorage.getItem = original
  })
})

describe('aplicação no documento', () => {
  afterEach(() => {
    document.documentElement.removeAttribute(THEME_ATTRIBUTE)
  })

  it('troca a paleta pelo atributo do html', () => {
    applyTheme('light')
    expect(currentTheme()).toBe('light')
    applyTheme('dark')
    expect(currentTheme()).toBe('dark')
  })

  it('acompanha a barra do navegador, que não é do React', () => {
    const meta = document.createElement('meta')
    meta.name = 'theme-color'
    document.head.append(meta)

    applyTheme('light')
    expect(meta.content).toBe(BROWSER_CHROME_COLOR.light)
    applyTheme('dark')
    expect(meta.content).toBe(BROWSER_CHROME_COLOR.dark)

    meta.remove()
  })

  it('não quebra quando a página não tem meta theme-color', () => {
    expect(document.querySelector('meta[name="theme-color"]')).toBeNull()
    expect(() => applyTheme('dark')).not.toThrow()
  })
})

describe('ThemeProvider', () => {
  let media: ReturnType<typeof stubMatchMedia>

  beforeEach(() => {
    media = stubMatchMedia(false)
  })

  afterEach(() => {
    media.restore()
    document.documentElement.removeAttribute(THEME_ATTRIBUTE)
  })

  const renderProvider = (ui: React.ReactNode) => render(<ThemeProvider>{ui}</ThemeProvider>)

  it('sem escolha gravada, assume o tema do sistema', () => {
    renderProvider(<Probe />)
    expect(currentTheme()).toBe('light')
    expect(screen.getByTestId('probe')).toHaveTextContent('system:light')
  })

  it('retoma a preferência gravada em vez de voltar ao padrão', () => {
    storePreference('dark')
    renderProvider(<Probe />)
    expect(currentTheme()).toBe('dark')
    expect(screen.getByTestId('probe')).toHaveTextContent('dark:dark')
  })

  it('grava a escolha e a aplica na hora', async () => {
    const user = userEvent.setup()
    function Switcher() {
      const { setPreference } = useTheme()
      return (
        <button type="button" onClick={() => setPreference('dark')}>
          escuro
        </button>
      )
    }
    renderProvider(<Switcher />)

    await user.click(screen.getByRole('button', { name: 'escuro' }))

    expect(window.localStorage.getItem(THEME_STORAGE_KEY)).toBe('dark')
    expect(currentTheme()).toBe('dark')
  })

  it('no tema do sistema, a virada do aparelho troca a tela', () => {
    renderProvider(<Probe />)
    expect(currentTheme()).toBe('light')

    act(() => media.set(true))

    expect(currentTheme()).toBe('dark')
    expect(screen.getByTestId('probe')).toHaveTextContent('system:dark')
  })

  it('com tema escolhido, a virada do aparelho não mexe na tela', () => {
    storePreference('light')
    renderProvider(<Probe />)

    act(() => media.set(true))

    expect(currentTheme()).toBe('light')
  })

  it('usaTheme fora do provider diz qual provider falta', () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
    expect(() => render(<Probe />)).toThrow(/ThemeProvider/)
    consoleError.mockRestore()
  })
})

describe('ThemeToggle', () => {
  afterEach(() => {
    document.documentElement.removeAttribute(THEME_ATTRIBUTE)
  })

  it('anuncia o estado atual e para onde o próximo clique vai', () => {
    render(
      <ThemeProvider>
        <ThemeToggle />
      </ThemeProvider>,
    )

    const button = screen.getByRole('button', { name: 'Tema: automático. Próximo tema: claro' })
    expect(button).toBeInTheDocument()
    expect(button).toHaveAttribute('title', 'Tema: automático')
  })

  it('cada clique avança a preferência e deixa o atributo no lugar', async () => {
    const user = userEvent.setup()
    render(
      <ThemeProvider>
        <ThemeToggle />
      </ThemeProvider>,
    )

    await user.click(screen.getByRole('button', { name: /Próximo tema: claro/ }))
    expect(window.localStorage.getItem(THEME_STORAGE_KEY)).toBe('light')
    expect(currentTheme()).toBe('light')

    await user.click(screen.getByRole('button', { name: /Próximo tema: escuro/ }))
    expect(window.localStorage.getItem(THEME_STORAGE_KEY)).toBe('dark')
    expect(currentTheme()).toBe('dark')

    await user.click(screen.getByRole('button', { name: /Próximo tema: automático/ }))
    expect(window.localStorage.getItem(THEME_STORAGE_KEY)).toBe('system')
  })
})

/**
 * O snippet inline do `index.html` é a única parte do tema que roda antes do
 * React — e não dá para importar um arquivo dele. O teste abaixo executa o
 * snippet de verdade e compara com `resolveTheme`: se alguém renomear a chave,
 * esquecer de normalizar o valor lido ou trocar a consulta de sistema, a
 * primeira pintura deixa de bater com o resto do app e este teste quebra.
 */
describe('snippet de primeira pintura', () => {
  // ler o arquivo do repo é o que faz este teste valer: `?raw` não resolve em
  // HTML no Vite, e o projeto de TS dos testes é o que tem tipos de Node
  const readIndexHtml = () => readFileSync(resolve(process.cwd(), 'index.html'), 'utf8')

  const bootScript = (() => {
    const match = readIndexHtml().match(/<script>([\s\S]*?)<\/script>/)
    if (!match) throw new Error('index.html deixou de ter o script inline do tema')
    return match[1]!
  })()

  function boot(stored: string | null, systemDark: boolean): ResolvedTheme | null {
    window.localStorage.clear()
    if (stored !== null) window.localStorage.setItem(THEME_STORAGE_KEY, stored)
    document.documentElement.removeAttribute(THEME_ATTRIBUTE)

    const media = stubMatchMedia(systemDark)
    try {
      new Function('window', 'document', bootScript)(window, document)
    } finally {
      media.restore()
    }
    return currentTheme()
  }

  afterEach(() => {
    document.documentElement.removeAttribute(THEME_ATTRIBUTE)
  })

  const cases: Array<{ stored: string | null; systemDark: boolean }> = [
    { stored: 'light', systemDark: true },
    { stored: 'light', systemDark: false },
    { stored: 'dark', systemDark: false },
    { stored: 'dark', systemDark: true },
    { stored: 'system', systemDark: true },
    { stored: 'system', systemDark: false },
    { stored: null, systemDark: true },
    { stored: null, systemDark: false },
    { stored: 'neon', systemDark: true },
    { stored: 'neon', systemDark: false },
  ]

  for (const { stored, systemDark } of cases) {
    it(`aplica o mesmo tema do módulo com ${stored ?? 'sem escolha'} e sistema ${
      systemDark ? 'escuro' : 'claro'
    }`, () => {
      const preference: ThemePreference = isThemePreference(stored) ? stored : 'system'
      expect(boot(stored, systemDark)).toBe(resolveTheme(preference, systemDark))
    })
  }

  it('declara que o app aceita os dois esquemas de cor', () => {
    expect(readIndexHtml()).toContain('<meta name="color-scheme" content="light dark" />')
  })
})
