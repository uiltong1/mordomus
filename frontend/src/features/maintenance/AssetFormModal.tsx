import { useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ApiError } from '@/shared/api/errors'
import { resourceKeys } from '@/shared/api/queryKeys'
import type { Asset, Room } from '@/shared/api/types'
import { todayInputValue } from '@/shared/datetime'
import { Button, DatePicker, Field, Input, Modal, Select, useToast } from '@/shared/ui'
import { useTenant } from '@/tenants/TenantProvider'
import { createAsset, updateAsset } from './api'

export interface AssetFormModalProps {
  open: boolean
  onClose: () => void
  rooms: Room[]
  /** Cômodo já escolhido ao abrir pela tela do cômodo. */
  defaultRoomId?: string | null
  asset?: Asset | null
  onSaved?: (asset: Asset) => void
}

/**
 * Cadastro de ativo. `category` é texto livre no contrato (`varchar(40)`, sem
 * enum), e é isso que aparece aqui: uma lista fechada obrigaria o morador a
 * guardar em "Outros" o filtro de ar, e o inventário perderia o nome que ele usa
 * em casa.
 *
 * O corpo só existe enquanto o modal está aberto: o `key` remonta na troca de
 * item, então editar A e depois B não deixa nenhum valor de A para trás.
 */
export function AssetFormModal({
  open,
  onClose,
  rooms,
  defaultRoomId = null,
  asset = null,
  onSaved,
}: AssetFormModalProps) {
  if (!open) return null
  return (
    <AssetFormBody
      key={asset?.id ?? 'novo'}
      onClose={onClose}
      rooms={rooms}
      defaultRoomId={defaultRoomId}
      asset={asset}
      onSaved={onSaved}
    />
  )
}

function AssetFormBody({
  onClose,
  rooms,
  defaultRoomId,
  asset,
  onSaved,
}: {
  onClose: () => void
  rooms: Room[]
  defaultRoomId: string | null
  asset: Asset | null
  onSaved?: (asset: Asset) => void
}) {
  const [roomId, setRoomId] = useState(asset?.room_id ?? defaultRoomId ?? rooms[0]?.id ?? '')
  const [name, setName] = useState(asset?.name ?? '')
  const [category, setCategory] = useState(asset?.category ?? '')
  const [brand, setBrand] = useState(asset?.brand ?? '')
  const [model, setModel] = useState(asset?.model ?? '')
  const [acquiredAt, setAcquiredAt] = useState(dateOnly(asset?.acquired_at))
  const [warrantyUntil, setWarrantyUntil] = useState(dateOnly(asset?.warranty_until))
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const { activeTenantId } = useTenant()
  const queryClient = useQueryClient()
  const toast = useToast()

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        room_id: roomId,
        name: name.trim(),
        category: category.trim() === '' ? null : category.trim(),
        brand: brand.trim() === '' ? null : brand.trim(),
        model: model.trim() === '' ? null : model.trim(),
        acquired_at: acquiredAt === '' ? null : acquiredAt,
        warranty_until: warrantyUntil === '' ? null : warrantyUntil,
      }
      return asset ? updateAsset(asset.id, payload) : createAsset(payload)
    },
    onSuccess: (saved: Asset) => {
      void queryClient.invalidateQueries({ queryKey: resourceKeys.assets(activeTenantId) })
      void queryClient.invalidateQueries({ queryKey: resourceKeys.rooms(activeTenantId) })
      onSaved?.(saved)
    },
  })

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()

    const local: Record<string, string[]> = {}
    if (name.trim() === '') local.name = ['Dê um nome para o item.']
    if (roomId === '') local.room_id = ['Escolha o cômodo.']
    setErrors(local)
    if (Object.keys(local).length > 0) return

    setSubmitting(true)
    setFormError(null)
    try {
      await save.mutateAsync()
      toast.success(asset ? 'Item atualizado.' : 'Item adicionado ao inventário.')
      onClose()
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(error.fieldErrors)
        setFormError(error.message)
      } else {
        setFormError('Não foi possível salvar o item.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal
      open
      title={asset ? 'Editar item' : 'Novo item'}
      description="O que precisa de manutenção entra aqui."
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" form="asset-form" loading={submitting}>
            {asset ? 'Salvar' : 'Adicionar'}
          </Button>
        </>
      }
    >
      <form id="asset-form" onSubmit={onSubmit} className="space-y-4" noValidate>
        {formError ? (
          <p role="alert" className="rounded-control bg-danger/15 px-3 py-2 text-xs text-danger">
            {formError}
          </p>
        ) : null}

        <Field label="Nome do item" errors={errors.name}>
          {({ id, describedBy, invalid }) => (
            <Input
              id={id}
              name="name"
              value={name}
              maxLength={120}
              placeholder="Filtro do ar-condicionado"
              autoFocus
              aria-describedby={describedBy}
              invalid={invalid}
              onChange={(event) => setName(event.target.value)}
            />
          )}
        </Field>

        <Field label="Cômodo" errors={errors.room_id}>
          {({ id, describedBy, invalid }) => (
            <Select
              id={id}
              name="room_id"
              value={roomId}
              aria-describedby={describedBy}
              invalid={invalid}
              onChange={(event) => setRoomId(event.target.value)}
            >
              <option value="">Escolha o cômodo</option>
              {rooms
                .filter((room) => !room.archived)
                .map((room) => (
                  <option key={room.id} value={room.id}>
                    {room.icon ? `${room.icon} ` : ''}
                    {room.name}
                  </option>
                ))}
            </Select>
          )}
        </Field>

        <Field label="Tipo" hint="Opcional. Serve para agrupar o inventário.">
          {({ id, describedBy }) => (
            <Input
              id={id}
              name="category"
              value={category}
              maxLength={40}
              placeholder="Eletrodoméstico"
              aria-describedby={describedBy}
              onChange={(event) => setCategory(event.target.value)}
            />
          )}
        </Field>

        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Marca">
            {({ id }) => (
              <Input
                id={id}
                name="brand"
                value={brand}
                maxLength={60}
                onChange={(event) => setBrand(event.target.value)}
              />
            )}
          </Field>
          <Field label="Modelo">
            {({ id }) => (
              <Input
                id={id}
                name="model"
                value={model}
                maxLength={60}
                onChange={(event) => setModel(event.target.value)}
              />
            )}
          </Field>
        </div>

        <div className="grid gap-3 sm:grid-cols-2">
          <DatePicker
            label="Comprado em"
            value={acquiredAt}
            onChange={setAcquiredAt}
            max={todayInputValue()}
          />
          <DatePicker
            label="Garantia até"
            value={warrantyUntil}
            onChange={setWarrantyUntil}
            min={todayInputValue()}
          />
        </div>
      </form>
    </Modal>
  )
}

/** O recurso devolve instante com fuso; o campo do formulário quer dia. */
function dateOnly(iso: string | null | undefined): string {
  return iso ? iso.slice(0, 10) : ''
}
