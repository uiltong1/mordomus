import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ApiError } from '@/shared/api/errors'
import { Button, Spinner } from '@/shared/ui'
import { useAuth } from './AuthProvider'
import { AuthLayout } from './AuthLayout'

const MESSAGES: Record<string, string> = {
  not_found: 'Este convite não existe. Confira o link que você recebeu.',
  invitation_already_used: 'Este convite já foi usado. Entre com a sua conta.',
  invitation_expired: 'Este convite expirou. Peça um novo para quem convidou.',
  invitation_email_mismatch: 'Este convite foi enviado para outro e-mail.',
  unauthenticated: 'Sua sessão expirou. Entre novamente para aceitar o convite.',
  rate_limited: 'Muitas tentativas. Aguarde um instante.',
}

export function AcceptInvitationPage() {
  const { token = '' } = useParams()
  const { acceptInvitation, isAuthenticated } = useAuth()
  const navigate = useNavigate()
  const [error, setError] = useState<string | null>(null)
  const attempted = useRef(false)

  useEffect(() => {
    if (attempted.current || !token) return
    attempted.current = true

    void acceptInvitation(token)
      .then(() => navigate('/', { replace: true }))
      .catch((cause: unknown) => {
        if (cause instanceof ApiError) setError(MESSAGES[cause.code] ?? cause.message)
        else setError('Não foi possível aceitar o convite.')
      })
  }, [acceptInvitation, navigate, token])

  if (error) {
    return (
      <AuthLayout
        title="Convite não concluído"
        subtitle={error}
        footer={
          <Link to="/entrar" className="text-brand hover:underline">
            Voltar para o login
          </Link>
        }
      >
        <Button
          block
          variant="secondary"
          onClick={() => {
            if (isAuthenticated) navigate('/', { replace: true })
            else navigate('/entrar', { replace: true })
          }}
        >
          {isAuthenticated ? 'Ir para o painel' : 'Entrar'}
        </Button>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout title="Aceitando o convite" subtitle="Só um instante.">
      <div className="flex items-center justify-center gap-3 py-6 text-sm text-ink-muted">
        <Spinner className="size-4" />
        Entrando na residência...
      </div>
    </AuthLayout>
  )
}
