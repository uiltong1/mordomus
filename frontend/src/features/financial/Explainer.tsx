import type { ReactNode } from 'react'

export interface ExplainerItem {
  title: string
  body: ReactNode
}

export interface ExplainerProps {
  /** A frase que fica sempre à vista: o morador que já entendeu não lê o resto. */
  summary: ReactNode
  items: readonly ExplainerItem[]
  label?: string
}

/**
 * Explicação do funcionamento da tela.
 *
 * A frase curta fica sempre visível e o detalhe vem num `<details>` nativo: quem
 * já usa a tela há meses paga só uma linha, e quem chegou agora tem o "por quê"
 * a um clique. A alternativa — texto expandido — empurrava a lista de contas
 * para baixo da dobra em toda visita; esconder tudo atrás de um "Saiba mais"
 * deixaria o moradorympouco sem resposta para a dúvida que ele teve.
 *
 * O texto é explicação, não legenda de componente: nada aqui repete o rótulo
 * de um botão que está logo abaixo.
 */
export function Explainer({ summary, items, label = 'Como isto funciona' }: ExplainerProps) {
  return (
    <aside className="rounded-card border border-line bg-surface-2/50 px-4 py-3">
      <p className="text-sm text-ink">{summary}</p>

      <details className="mt-2 group">
        <summary className="cursor-pointer list-none text-xs font-medium text-brand marker:content-none">
          <span className="group-open:hidden">{label}</span>
          <span className="hidden group-open:inline">Menos explicação</span>
        </summary>

        <dl className="mt-2 space-y-2 border-t border-line pt-2">
          {items.map((item) => (
            <div key={item.title}>
              <dt className="text-xs font-medium text-ink">{item.title}</dt>
              <dd className="mt-0.5 text-xs text-ink-muted">{item.body}</dd>
            </div>
          ))}
        </dl>
      </details>
    </aside>
  )
}
