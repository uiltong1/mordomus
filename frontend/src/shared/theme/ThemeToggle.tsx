import { PREFERENCE_LABEL, nextPreference, type ThemePreference } from './theme'
import { useTheme } from './ThemeProvider'

const ICON: Record<ThemePreference, string> = {
  light: '☀',
  dark: '☾',
  system: '◐',
}

/**
 * Alternador de tema. Um botão só, ciclando claro → escuro → sistema: três
 * estados cabem num botão de 36 px, e o rótulo diz em texto qual é o estado e
 * para onde o próximo clique vai — ícone sozinho não informa nada a quem navega
 * por leitor de tela.
 */
export function ThemeToggle({ className = '' }: { className?: string }) {
  const { preference, cyclePreference } = useTheme()
  const next = nextPreference(preference)

  return (
    <button
      type="button"
      onClick={cyclePreference}
      aria-label={`Tema: ${PREFERENCE_LABEL[preference]}. Próximo tema: ${PREFERENCE_LABEL[next]}`}
      title={`Tema: ${PREFERENCE_LABEL[preference]}`}
      className={[
        'flex size-9 items-center justify-center rounded-control text-ink-muted',
        'transition-colors hover:bg-surface-2 hover:text-ink',
        className,
      ]
        .filter(Boolean)
        .join(' ')}
    >
      <span aria-hidden="true">{ICON[preference]}</span>
    </button>
  )
}
