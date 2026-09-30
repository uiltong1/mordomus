import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import {
  DARK_SCHEME_QUERY,
  applyTheme,
  nextPreference,
  readStoredPreference,
  resolveTheme,
  storePreference,
  systemPrefersDark,
  type ResolvedTheme,
  type ThemePreference,
} from './theme'

export interface ThemeContextValue {
  /** O que o morador escolheu — `system` quando ele não escolheu nada. */
  preference: ThemePreference
  /** O tema visível, já resolvido a partir da preferência do sistema. */
  theme: ResolvedTheme
  setPreference: (preference: ThemePreference) => void
  /** Alterna claro → escuro → sistema, o mesmo caminho do botão. */
  cyclePreference: () => void
}

const ThemeContext = createContext<ThemeContextValue | null>(null)

/**
 * Tema da interface (fecha a pendência 4 da T4.1).
 *
 * A paleta vive em `index.css` e a escolha vira o atributo `data-theme` no
 * `<html>`; aqui só mora a decisão e o efeito colateral. O atributo é aplicado
 * por um snippet inline no `index.html` antes da primeira pintura — se este
 * provider esperasse o React para aplicar, o morador veria o tema errado
 * piscando a cada abertura do app.
 */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const [preference, setPreferenceState] = useState<ThemePreference>(readStoredPreference)
  const [systemDark, setSystemDark] = useState(systemPrefersDark)

  // o aparelho muda de tema enquanto o app está aberto (o SO muda, o amanhece
  // acontece); com a preferência em `system` a tela tem de acompanhar
  useEffect(() => {
    if (typeof window.matchMedia !== 'function') return
    const query = window.matchMedia(DARK_SCHEME_QUERY)
    const onChange = (event: MediaQueryListEvent) => setSystemDark(event.matches)

    if (typeof query.addEventListener === 'function') {
      query.addEventListener('change', onChange)
      return () => query.removeEventListener('change', onChange)
    }
    query.addListener(onChange)
    return () => query.removeListener(onChange)
  }, [])

  const theme = resolveTheme(preference, systemDark)

  useEffect(() => {
    applyTheme(theme)
  }, [theme])

  const setPreference = useCallback((next: ThemePreference) => {
    storePreference(next)
    setPreferenceState(next)
  }, [])

  const cyclePreference = useCallback(() => {
    setPreferenceState((current) => {
      const next = nextPreference(current)
      storePreference(next)
      return next
    })
  }, [])

  const value = useMemo<ThemeContextValue>(
    () => ({ preference, theme, setPreference, cyclePreference }),
    [cyclePreference, preference, setPreference, theme],
  )

  return <ThemeContext value={value}>{children}</ThemeContext>
}

export function useTheme(): ThemeContextValue {
  const context = useContext(ThemeContext)
  if (!context) throw new Error('useTheme precisa estar dentro de <ThemeProvider>')
  return context
}
