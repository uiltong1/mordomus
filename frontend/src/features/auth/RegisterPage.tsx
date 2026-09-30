import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { ApiError } from '@/shared/api/errors'
import { Button, Field, Input } from '@/shared/ui'
import { useAuth } from './AuthProvider'
import { AuthLayout, type FormErrors } from './AuthLayout'

/**
 * Registro (T4.1.6). O backend já cria a primeira residência junto com a conta,
 * então o morador entra direto no painel — não há passo de "criar casa" aqui.
 */
export function RegisterPage() {
  const { register } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    home_name: '',
  })
  const [errors, setErrors] = useState<FormErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const update = (field: keyof typeof form) => (value: string) =>
    setForm((current) => ({ ...current, [field]: value }))

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setSubmitting(true)
    setErrors({})
    setFormError(null)
    try {
      await register({
        name: form.name.trim(),
        email: form.email.trim(),
        password: form.password,
        password_confirmation: form.password_confirmation,
        ...(form.home_name.trim() ? { home_name: form.home_name.trim() } : {}),
      })
      navigate('/', { replace: true })
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(error.fieldErrors)
        setFormError(Object.keys(error.fieldErrors).length > 0 ? null : error.message)
      } else {
        setFormError('Não foi possível criar a conta. Tente novamente.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthLayout
      title="Criar conta"
      subtitle="Sua primeira residência é criada junto com a conta."
      footer={
        <>
          Já tem conta?{' '}
          <Link to="/entrar" className="text-brand hover:underline">
            Entrar
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

        <Field label="Como podemos te chamar" errors={errors.name}>
          {({ id, describedBy, invalid }) => (
            <Input
              id={id}
              name="name"
              autoComplete="name"
              required
              value={form.name}
              invalid={invalid}
              aria-describedby={describedBy}
              onChange={(event) => update('name')(event.target.value)}
            />
          )}
        </Field>

        <Field label="E-mail" errors={errors.email}>
          {({ id, describedBy, invalid }) => (
            <Input
              id={id}
              type="email"
              name="email"
              autoComplete="email"
              required
              value={form.email}
              invalid={invalid}
              aria-describedby={describedBy}
              onChange={(event) => update('email')(event.target.value)}
            />
          )}
        </Field>

        <Field
          label="Nome da residência"
          hint="Opcional — dá para mudar depois."
          errors={errors.home_name}
        >
          {({ id, describedBy, invalid }) => (
            <Input
              id={id}
              name="home_name"
              value={form.home_name}
              invalid={invalid}
              aria-describedby={describedBy}
              placeholder="Casa Principal"
              onChange={(event) => update('home_name')(event.target.value)}
            />
          )}
        </Field>

        <Field label="Senha" hint="Mínimo de 8 caracteres." errors={errors.password}>
          {({ id, describedBy, invalid }) => (
            <Input
              id={id}
              type="password"
              name="password"
              autoComplete="new-password"
              required
              minLength={8}
              value={form.password}
              invalid={invalid}
              aria-describedby={describedBy}
              onChange={(event) => update('password')(event.target.value)}
            />
          )}
        </Field>

        <Field label="Repita a senha" errors={errors.password_confirmation}>
          {({ id, describedBy, invalid }) => (
            <Input
              id={id}
              type="password"
              name="password_confirmation"
              autoComplete="new-password"
              required
              value={form.password_confirmation}
              invalid={invalid}
              aria-describedby={describedBy}
              onChange={(event) => update('password_confirmation')(event.target.value)}
            />
          )}
        </Field>

        <Button type="submit" block size="lg" loading={submitting}>
          Criar conta
        </Button>
      </form>
    </AuthLayout>
  )
}
