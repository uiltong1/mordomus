import { useCallback, useEffect, useRef, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { Button } from './Button'

export interface ModalProps {
  open: boolean
  title: string
  description?: string
  onClose: () => void
  children?: ReactNode
  footer?: ReactNode
}

/**
 * Diálogo modal. Fica em portal, prende o Tab dentro dele, devolve o foco ao
 * elemento de origem e fecha com Esc — o conjunto mínimo para não aprisionar
 * quem navega por teclado ou leitor de tela.
 *
 * O clique fora **não** fecha: os diálogos desta aplicação são formulários, e
 * um clique no vazio perdia o que o morador tinha digitado sem nenhum aviso. A
 * saída é deliberada — o X, o Cancelar do rodapé ou o envio. O fundo continua
 * lá, para escurecer a tela e engolir o clique, que é o que impede o clique de
 * alcançar a página de trás.
 */
export function Modal({ open, title, description, onClose, children, footer }: ModalProps) {
  const panelRef = useRef<HTMLDivElement>(null)
  const restoreFocusRef = useRef<HTMLElement | null>(null)

  const focusables = useCallback((): HTMLElement[] => {
    if (!panelRef.current) return []
    return Array.from(
      panelRef.current.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
      ),
    )
  }, [])

  useEffect(() => {
    if (!open) return
    restoreFocusRef.current = document.activeElement as HTMLElement | null
    const first = focusables()[0]
    first?.focus()

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        onClose()
        return
      }
      if (event.key !== 'Tab') return

      const items = focusables()
      if (items.length === 0) return
      const firstItem = items[0]!
      const lastItem = items[items.length - 1]!
      if (event.shiftKey && document.activeElement === firstItem) {
        event.preventDefault()
        lastItem.focus()
      } else if (!event.shiftKey && document.activeElement === lastItem) {
        event.preventDefault()
        firstItem.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown, true)
    return () => {
      document.removeEventListener('keydown', onKeyDown, true)
      restoreFocusRef.current?.focus()
    }
  }, [focusables, onClose, open])

  if (!open) return null

  return createPortal(
    // O fundo é o que rola quando a tela é menor que o diálogo. `my-auto` no
    // painel substitui o `items-center`: com centralização por flex, a parte que
    // sobrava acima do topo ficava fora do alcance da rolagem — o título e o X
    // sumiam justamente nos modais mais longos, que são os que precisam do
    // formulário inteiro.
    <div className="fixed inset-0 z-50 flex overflow-y-auto overscroll-contain p-4">
      <div className="absolute inset-0 bg-scrim/70 backdrop-blur-sm" aria-hidden="true" />
      {/* `max-h-full` limita o painel à altura da tela e o corpo é que rola: é o
          que mantém título e ações sempre à vista, sem a faixa de conteúdo que
          um rodapé grudado deixaria escapar por baixo. `min-h-0` é o que
          autoriza o corpo a encolher abaixo do próprio conteúdo. */}
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className="relative mx-auto my-auto flex max-h-full w-full max-w-lg flex-col rounded-card border border-line bg-surface shadow-pop"
      >
        <header className="flex shrink-0 items-start justify-between gap-4 border-b border-line px-5 py-4">
          <div className="min-w-0">
            <h2 className="text-sm font-semibold text-ink">{title}</h2>
            {description ? <p className="mt-1 text-xs text-ink-muted">{description}</p> : null}
          </div>
          <Button variant="ghost" size="sm" onClick={onClose} aria-label="Fechar">
            ✕
          </Button>
        </header>
        {children ? (
          <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-4">
            {children}
          </div>
        ) : null}
        {footer ? (
          <footer className="flex shrink-0 justify-end gap-2 border-t border-line px-5 py-3">
            {footer}
          </footer>
        ) : null}
      </div>
    </div>,
    document.body,
  )
}
