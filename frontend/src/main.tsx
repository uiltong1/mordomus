import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { AppRouter } from './app/AppRouter'
import './index.css'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <AppRouter />
  </StrictMode>,
)

// O registro do service worker mora no `<UpdateBanner>` (dentro da área logada),
// e não aqui: a troca de versão precisa do consentimento do morador, e recarregar
// a página por baixo apagaria o formulário de regra que ele estiver preenchendo.
