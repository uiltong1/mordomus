import { Component, type ErrorInfo, type ReactNode } from 'react'

interface State {
  error: Error | null
}

/**
 * Rede de segurança da árvore de render. Um `catch` em volta do app é melhor
 * que tela branca: o morador precisa de um caminho de volta, e o erro precisa
 * de uma referência para virar issue.
 */
export class ErrorBoundary extends Component<{ children: ReactNode }, State> {
  state: State = { error: null }

  static getDerivedStateFromError(error: Error): State {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    console.error('app.render_error', error, info.componentStack)
  }

  render(): ReactNode {
    if (!this.state.error) return this.props.children

    return (
      <div className="flex min-h-full items-center justify-center px-6">
        <div className="max-w-md text-center">
          <p className="text-sm font-semibold text-danger">A tela quebrou</p>
          <p className="mt-2 text-xs text-ink-muted">
            Algo falhou ao desenhar esta página. Recarregando costuma resolver.
          </p>
          <pre className="mt-4 overflow-x-auto rounded-control bg-surface-2 p-3 text-left font-mono text-[11px] text-ink-subtle">
            {this.state.error.message}
          </pre>
          <button
            type="button"
            onClick={() => window.location.reload()}
            className="mt-4 rounded-control border border-line px-3 py-1.5 text-xs text-ink hover:bg-surface-2"
          >
            Recarregar
          </button>
        </div>
      </div>
    )
  }
}
