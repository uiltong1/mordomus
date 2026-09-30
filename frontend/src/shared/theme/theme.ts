export type ThemePreference = 'light' | 'dark' | 'system'
export type ResolvedTheme = 'light' | 'dark'

export const THEME_STORAGE_KEY = 'mordomus.theme'
export const THEME_ATTRIBUTE = 'data-theme'
export const DARK_SCHEME_QUERY = '(prefers-color-scheme: dark)'
/**
 * A preferência é `system` por padrão: o tema segue o aparelho, e o morador só
 * escolhe quando quiser um tema fixo.
 */
export const PREFERENCE_LABEL: Record<ThemePreference, string> = {
  light: 'claro',
  dark: 'escuro',
  system: 'automático',
}

/**
 * Cor que o navegador pinta na barra do PWA. O `theme_color` do manifesto é
 * único e não acompanha a troca, então quem responde é o `<meta>`.
 */
export const BROWSER_CHROME_COLOR: Record<ResolvedTheme, string> = {
  light: '#f8fafc',
  dark: '#0f172a',
}

const PREFERENCES: readonly ThemePreference[] = ['light', 'dark', 'system']

export function isThemePreference(value: unknown): value is ThemePreference {
  return typeof value === 'string' && (PREFERENCES as readonly string[]).includes(value)
}

/**
 * `localStorage` pode estar indisponível (aba anônima, cookies bloqueados) e
 * guardar lixo de uma versão antiga do app: nos dois casos a preferência volta
 * para `system` em vez de a aplicação quebrar na primeira tela.
 */
export function readStoredPreference(): ThemePreference {
  try {
    const stored = window.localStorage.getItem(THEME_STORAGE_KEY)
    return isThemePreference(stored) ? stored : 'system'
  } catch {
    return 'system'
  }
}

export function storePreference(preference: ThemePreference): void {
  try {
    window.localStorage.setItem(THEME_STORAGE_KEY, preference)
  } catch {
    // sem storage o tema continua valendo nesta aba, só não sobrevive ao reload
  }
}

export function systemPrefersDark(): boolean {
  return typeof window.matchMedia === 'function'
    ? window.matchMedia(DARK_SCHEME_QUERY).matches
    : false
}

export function resolveTheme(preference: ThemePreference, systemDark: boolean): ResolvedTheme {
  if (preference === 'system') return systemDark ? 'dark' : 'light'
  return preference
}

/**
 * Aplica o tema resolvido no `<html>`. É aqui que a paleta troca: os utilitários
 * do Tailwind leem `var(--color-*)`, então o atributo é a única coisa que a
 * interface precisa observar. O `color-scheme` fica com o CSS, derivado do mesmo
 * atributo — dois lugares escrevendo a mesma coisa só cria divergeência. O
 * `theme-color` vai por aqui porque a barra do navegador não é do React.
 */
export function applyTheme(resolved: ResolvedTheme): void {
  document.documentElement.setAttribute(THEME_ATTRIBUTE, resolved)

  const meta = document.querySelector<HTMLMetaElement>('meta[name="theme-color"]')
  if (meta) meta.content = BROWSER_CHROME_COLOR[resolved]
}

export function nextPreference(preference: ThemePreference): ThemePreference {
  const index = PREFERENCES.indexOf(preference)
  return PREFERENCES[(index + 1) % PREFERENCES.length]!
}
