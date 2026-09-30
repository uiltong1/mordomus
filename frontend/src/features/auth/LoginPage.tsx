import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { ApiError } from '@/shared/api/errors'
import { Button, Field, Input } from '@/shared/ui'
import { useAuth } from './AuthProvider'
import { AuthLayout, type FormErrors } from './AuthLayout'

/**
 * Login (T4.1.6). Sem seletor de residência: quem acabou de autenticar ainda
 * não conhece as casas do usuário, e o backend entra na primeira residência
 * ativa. A escolha entre casas acontece no `TenantSwitcher`, depois do login.
 */
export function LoginPage() {
  const { login, adoptSession } = useAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [errors, setErrors] = useState<FormErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setSubmitting(true)
    setErrors({})
    setFormError(null)
    try {
      adoptSession(await login({ email: email.trim(), password }))
      navigate('/', { replace: true })
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(error.fieldErrors)
        setFormError(error.message)
      } else {
        setFormError('Não foi possível entrar. Tente novamente.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthLayout
      title="Entrar na sua casa"
      subtitle="Gerencie manutenção, agenda e contas em um lugar só."
      footer={
        <>
          Ainda não tem conta?{' '}
          <Link to="/registro" className="text-brand hover:underline">
            Criar agora
          </Link>
        </>
      }
    >
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        {formError ? (
          <p role="alert" className="rounded-control bg-danger/15 px-3 py-2 text-xs text-danger">
            {formError}
          </p>
        ) : null}

        <Field label="E-mail" errors={errors.email}>
          {({ id, describedBy, invalid }) => (
            <Input
              id={id}
              type="email"
              name="email"
              autoComplete="email"
              required
              value={email}
              invalid={invalid}
              aria-describedby={describedBy}
              onChange={(event) => setEmail(event.target.value)}
            />
          )}
        </Field>

        <Field label="Senha" errors={errors.password}>
          {({ id, describedBy, invalid }) => (
            <Input
              id={id}
              type="password"
              name="password"
              autoComplete="current-password"
              required
              value={password}
              invalid={invalid}
              aria-describedby={describedBy}
              onChange={(event) => setPassword(event.target.value)}
            />
          )}
        </Field>

        <Button type="submit" block size="lg" loading={submitting}>
          Entrar
        </Button>
      </form>
    </AuthLayout>
  )
}
