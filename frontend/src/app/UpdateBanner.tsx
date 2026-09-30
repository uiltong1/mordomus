import { useEffect, useRef, useState } from 'react'
import {
  registerServiceWorker,
  type ServiceWorkerHandle,
} from '@/features/notifications/registerServiceWorker'
import { Button } from '@/shared/ui'

type UpdateState = 'idle' | 'ready' | 'applying'

/**
 * Aviso de "há versão nova" no lugar do reload por baixo.
 *
 * O bundle antigo recarregava a página assim que o worker novo aparecia. Com
 * o formulário de regra de manutenção na tela, isso apagaria tudo que o morador
 * tinha digitado — e perder trabalho é pior do que rodar uma versão a menos
 * por um dia. Aqui a troca é sempre deliberada: quem recusa continua na versão
 * antiga, com o que preenchido, até a próxima abertura.
 */
export function UpdateBanner() {
  const [state, setState] = useState<UpdateState>('idle')
  const handleRef = useRef<ServiceWorkerHandle | null>(null)

  useEffect(() => {
    // em desenvolvimento o worker serviria um bundle velho e cada HMR exigiria
    // recarregar. O guarda é o modo do CLI e não `import.meta.env.PROD`, que o
    // NODE_ENV do container deixaria falso mesmo em `vite build`.
    if (import.meta.env.MODE !== 'production') return

    let cancelled = false

    try {
      // o handle fica guardado: `registerSW` é quem devolve a função que aplica
      // a versão esperando, e registrar de novo criaria um segundo listener
      handleRef.current = registerServiceWorker({
        onNeedRefresh: () => {
          if (!cancelled) setState('ready')
        },
        onError: (error) => console.error('pwa.register_error', error),
      })
    } catch {
      // sem service worker o app segue funcionando, só não avisa de atualização
    }

    return () => {
      cancelled = true
    }
  }, [])

  if (state === 'idle') return null

  if (state === 'applying') {
    return (
      <div
        role="status"
        className="border-b border-brand/40 bg-brand/15 px-4 py-2 text-center text-xs text-brand"
      >
        Atualizando o aplicativo...
      </div>
    )
  }

  return (
    <div
      role="status"
      data-testid="update-banner"
      className="flex flex-wrap items-center justify-center gap-3 border-b border-brand/40 bg-brand/15 px-4 py-2"
    >
      <p className="text-xs text-brand">
        Há uma versão nova do aplicativo. Se você atualizar agora, o que está preenchendo nesta tela
        pode ser perdido.
      </p>
      <div className="flex items-center gap-2">
        <Button
          size="sm"
          variant="ghost"
          onClick={() => setState('idle')}
          title="Continuar na versão atual e atualizar depois"
        >
          Agora não
        </Button>
        <Button
          size="sm"
          onClick={() => {
            setState('applying')
            // a troca recarrega a página; recarregar por baixo era justamente o
            // que se queria evitar, então aqui é o morador quem apertou
            handleRef.current?.applyUpdate()
          }}
        >
          Atualizar agora
        </Button>
      </div>
    </div>
  )
}
