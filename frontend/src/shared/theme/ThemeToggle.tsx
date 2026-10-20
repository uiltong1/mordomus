import { PREFERENCE_LABEL, nextPreference, type ThemePreference } from './theme'
import { useTheme } from './ThemeProvider'

const ICON: Record<ThemePreference, string> = {
  light: '☀',
  dark: '☾',
  system: '◐',
}

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
