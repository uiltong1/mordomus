import { useState, type FormEvent } from 'react'
import { ApiError } from '@/shared/api/errors'
import { Button, Card, Field, Input } from '@/shared/ui'
import { useAuth } from '@/features/auth/AuthProvider'
import { createTenant } from '@/features/auth/api'
import { useQueryClient } from '@tanstack/react-query'

/**
 * Criação de residência — o único caminho para sair do estado "sem residência
 * ativa". `POST /tenants` devolve tokens novos já apontados para a casa criada,
 * então a sessão precisa ser adotada antes de qualquer nova leitura.
 */
export function CreateTenantPage({ onDone }: { onDone: () => void }) {
  const { adoptSession, user } = useAuth()
  const queryClient = useQueryClient()
  const [name, setName] = useState('')
  const [timezone, setTimezone] = useState('America/Sao_Paulo')
  const [preferredHour, setPreferredHour] = useState('09:00')
  const [formError, setFormError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setSubmitting(true)
    setFormError(null)
    try {
      const created = await createTenant({
        name: name.trim(),
        timezone,
        preferred_hour: preferredHour,
      })
      adoptSession({ ...created, user: user ?? undefined })
      // a lista de casas mudou: a próxima leitura já traz a nova
      void queryClient.invalidateQueries({ queryKey: ['mordomus', 'profile'] })
      onDone()
    } catch (error) {
      setFormError(
        error instanceof ApiError ? error.message : 'Não foi possível criar a residência.',
      )
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card className="mx-auto max-w-md">
      <form onSubmit={onSubmit} className="space-y-4 p-5" noValidate>
        <div>
          <h1 className="text-sm font-semibold text-ink">Criar residência</h1>
          <p className="mt-1 text-xs text-ink-muted">
            Cômodos, contas e agenda ficam preso a esta casa.
          </p>
        </div>

        {formError ? (
          <p role="alert" className="rounded-control bg-danger/15 px-3 py-2 text-xs text-danger">
            {formError}
          </p>
        ) : null}

        <Field label="Nome da residência">
          {({ id }) => (
            <Input
              id={id}
              name="name"
              required
              maxLength={120}
              value={name}
              placeholder="Casa Principal"
              onChange={(event) => setName(event.target.value)}
            />
          )}
        </Field>

        <Field label="Fuso horário" hint="Usado para os horários de aviso.">
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="timezone"
              value={timezone}
              maxLength={64}
              aria-describedby={describedBy}
              onChange={(event) => setTimezone(event.target.value)}
            />
          )}
        </Field>

        <Field label="Horário preferido" hint="Janela em que os avisos chegam.">
          {({ id, describedBy }) => (
            <Input
              id={id}
              type="time"
              name="preferred_hour"
              value={preferredHour}
              aria-describedby={describedBy}
              onChange={(event) => setPreferredHour(event.target.value)}
            />
          )}
        </Field>

        <Button type="submit" block loading={submitting}>
          Criar residência
        </Button>
      </form>
    </Card>
  )
}
