import { useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ApiError } from '@/shared/api/errors'
import { resourceKeys } from '@/shared/api/queryKeys'
import type { Room } from '@/shared/api/types'
import { Button, Field, Input, Modal, useToast } from '@/shared/ui'
import { useAuth } from '@/features/auth/AuthProvider'
import { useTenant } from '@/tenants/TenantProvider'
import { createRoom, updateRoom } from './api'

export interface RoomFormModalProps {
  open: boolean
  onClose: () => void
  room?: Room | null
}

/**
 * Cadastro de cômodo. O mesmo formulário cria e edita — a diferença está em
 * quais campos o `PATCH` leva: renomear um cômodo não precisa reenviar `icon`
 * e `sort_order`, e o backend recusa corpo vazio.
 *
 * Abrir de novo desmonta e remonta o corpo em vez de reescrever cada campo por
 * efeito: o estado inicial nasce uma vez, e reaproveitar o componente com o
 * `key` da regra garante que editando A e depois B nenhum valor de A reste.
 */
export function RoomFormModal({ open, onClose, room = null }: RoomFormModalProps) {
  if (!open) return null
  return <RoomFormBody key={room?.id ?? 'novo'} onClose={onClose} room={room} />
}

function RoomFormBody({ onClose, room }: { onClose: () => void; room: Room | null }) {
  const [name, setName] = useState(room?.name ?? '')
  const [icon, setIcon] = useState(room?.icon ?? '')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const { activeTenantId } = useTenant()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()

  const save = useMutation({
    mutationFn: () => {
      const payload = { name: name.trim(), icon: icon.trim() === '' ? null : icon.trim() }
      return room ? updateRoom(room.id, payload) : createRoom(payload)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: resourceKeys.rooms(activeTenantId) })
    },
  })

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()

    if (name.trim() === '') {
      setErrors({ name: ['Dê um nome para o cômodo.'] })
      return
    }

    setSubmitting(true)
    setErrors({})
    setFormError(null)
    try {
      await save.mutateAsync()
      toast.success(room ? 'Cômodo atualizado.' : 'Cômodo criado.')
      onClose()
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(error.fieldErrors)
        setFormError(error.message)
      } else {
        setFormError('Não foi possível salvar o cômodo.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal
      open
      title={room ? 'Editar cômodo' : 'Novo cômodo'}
      description="Cômodos organizam o inventário e as manutenções da casa."
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" form="room-form" loading={submitting}>
            {room ? 'Salvar' : 'Criar cômodo'}
          </Button>
        </>
      }
    >
      <form id="room-form" onSubmit={onSubmit} className="space-y-4" noValidate>
        {formError ? (
          <p role="alert" className="rounded-control bg-danger/15 px-3 py-2 text-xs text-danger">
            {formError}
          </p>
        ) : null}

        {!can('rooms.manage') ? (
          <p className="rounded-control bg-warning/15 px-3 py-2 text-xs text-warning">
            Sua permissão nesta residência é de leitura. O envio será recusado pelo servidor.
          </p>
        ) : null}

        <Field label="Nome do cômodo" errors={errors.name}>
          {({ id, describedBy, invalid }) => (
            <Input
              id={id}
              name="name"
              value={name}
              maxLength={80}
              placeholder="Cozinha"
              autoFocus
              aria-describedby={describedBy}
              invalid={invalid}
              onChange={(event) => setName(event.target.value)}
            />
          )}
        </Field>

        <Field label="Ícone" hint="Opcional. Um emoji como identificador rápido no painel.">
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="icon"
              value={icon}
              maxLength={40}
              placeholder="🍳"
              aria-describedby={describedBy}
              onChange={(event) => setIcon(event.target.value)}
            />
          )}
        </Field>
      </form>
    </Modal>
  )
}
