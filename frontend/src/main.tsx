import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { AppRouter } from './app/AppRouter'
import './index.css'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <AppRouter />
  </StrictMode>,
)

// O registro só faz sentido com build de produção: em dev o worker serviria um
// bundle velho e cada HMR exigiria recarregar. O guarda é o modo do CLI e não
// `import.meta.env.PROD`, que o NODE_ENV do container (`development`) deixaria
// falso mesmo em `vite build` — e o bloco inteiro sumiria sem erro nenhum.
if (import.meta.env.MODE === 'production') {
  void import('./features/notifications/registerServiceWorker')
    .then(({ registerServiceWorker }) => {
      let updating = false
      registerServiceWorker({
        onNeedRefresh: () => {
          // uma vez por sessão: recarregar em loop derrubaria o morador no meio
          // de um formulário a cada atualização do bundle
          if (updating) return
          updating = true
          window.location.reload()
        },
        onError: (error) => console.error('pwa.register_error', error),
      })
    })
    .catch(() => {
      // PWA offline é opcional: sem service worker o app segue funcionando
    })
}
