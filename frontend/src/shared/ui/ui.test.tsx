import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import {
  Button,
  Card,
  CardHeader,
  DatePicker,
  EmptyState,
  Field,
  Input,
  Modal,
  ToastProvider,
  toApiDate,
  useToast,
} from './index'

describe('Button', () => {
  it('anuncia o envio em andamento e não dispara onClick enquanto carrega', async () => {
    const onClick = vi.fn()
    render(
      <Button loading onClick={onClick}>
        Salvar
      </Button>,
    )

    const button = screen.getByRole('button', { name: 'Salvar' })
    expect(button).toBeDisabled()
    expect(button).toHaveAttribute('aria-busy', 'true')

    await userEvent.click(button)
    expect(onClick).not.toHaveBeenCalled()
  })

  it('mantém o envio livre quando não está carregando', async () => {
    const onClick = vi.fn()
    render(<Button onClick={onClick}>Salvar</Button>)

    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }))
    expect(onClick).toHaveBeenCalledOnce()
  })
})

describe('Card', () => {
  it('monta cabeçalho e corpo', () => {
    render(
      <Card>
        <CardHeader title="Agenda" description="Próximos 30 dias" />
        <div>conteúdo</div>
      </Card>,
    )

    expect(screen.getByRole('heading', { name: 'Agenda' })).toBeInTheDocument()
    expect(screen.getByText('Próximos 30 dias')).toBeInTheDocument()
    expect(screen.getByText('conteúdo')).toBeInTheDocument()
  })
})

describe('Field', () => {
  it('liga o erro ao controle por aria-describedby', () => {
    render(
      <Field label="E-mail" errors={['Este e-mail já está cadastrado.']}>
        {({ id, describedBy, invalid }) => (
          <Input id={id} aria-describedby={describedBy} invalid={invalid} defaultValue="a@b.c" />
        )}
      </Field>,
    )

    const input = screen.getByLabelText('E-mail')
    expect(input).toHaveAttribute('aria-invalid', 'true')
    const message = screen.getByRole('alert')
    expect(message).toHaveTextContent('Este e-mail já está cadastrado.')
    expect(input.getAttribute('aria-describedby')).toBe(message.id)
  })
})

describe('Modal', () => {
  it('prende o foco, fecha no Esc e devolve o foco para quem abriu', async () => {
    function Harness() {
      const [open, setOpen] = useState(false)
      return (
        <>
          <button type="button" onClick={() => setOpen(true)}>
            Abrir
          </button>
          <Modal open={open} title="Excluir cômodo" onClose={() => setOpen(false)}>
            <button type="button">Confirmar</button>
          </Modal>
        </>
      )
    }

    render(<Harness />)
    const trigger = screen.getByRole('button', { name: 'Abrir' })
    await userEvent.click(trigger)

    const dialog = screen.getByRole('dialog', { name: 'Excluir cômodo' })
    expect(dialog).toHaveAttribute('aria-modal', 'true')
    // o primeiro focável do diálogo recebe o foco ao abrir
    expect(dialog.contains(document.activeElement)).toBe(true)

    await userEvent.keyboard('{Escape}')
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(document.activeElement).toBe(trigger)
  })

  it('não renderiza nada quando está fechado', () => {
    render(
      <Modal open={false} title=" invisível" onClose={() => {}}>
        conteúdo
      </Modal>,
    )
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('mantém o foco preso: o último focável devolve ao primeiro', async () => {
    render(
      <Modal open title="Excluir cômodo" onClose={() => {}}>
        <button type="button">Cancelar</button>
        <button type="button">Confirmar</button>
      </Modal>,
    )

    const dialog = screen.getByRole('dialog')
    const focusables = Array.from(
      dialog.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled])',
      ),
    )
    const first = focusables[0]!
    const last = focusables[focusables.length - 1]!
    last.focus()

    await userEvent.tab()
    expect(document.activeElement).toBe(first)

    await userEvent.tab({ shift: true })
    expect(document.activeElement).toBe(last)
  })
})

describe('DatePicker', () => {
  it('entrega a data no formato que o backend valida', () => {
    // controlado de propósito: quem usa o componente é que guarda o estado
    function Harness({ onChange }: { onChange: (value: string) => void }) {
      const [value, setValue] = useState('2026-03-10')
      return (
        <DatePicker
          label="Vencimento"
          value={value}
          onChange={(next) => {
            setValue(next)
            onChange(next)
          }}
        />
      )
    }

    const onChange = vi.fn()
    render(<Harness onChange={onChange} />)

    const input = screen.getByLabelText('Vencimento')
    expect(input).toHaveValue('2026-03-10')

    // `type="date"` não é campo de texto: o jsdom não digita dígito a dígito
    fireEvent.change(input, { target: { value: '2026-04-01' } })

    expect(onChange).toHaveBeenCalledWith('2026-04-01')
    expect(input).toHaveValue('2026-04-01')
  })

  it('formata a data local no padrão da API', () => {
    expect(toApiDate(new Date(2026, 3, 1))).toBe('2026-04-01')
  })
})

describe('EmptyState', () => {
  it('oferece a ação que tira o morador do vazio', async () => {
    const onClick = vi.fn()
    render(
      <EmptyState
        title="Nenhum cômodo ainda"
        description="Cadastre um cômodo para começar a agenda."
        action={<Button onClick={onClick}>Adicionar cômodo</Button>}
      />,
    )

    expect(screen.getByText('Nenhum cômodo ainda')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Adicionar cômodo' }))
    expect(onClick).toHaveBeenCalledOnce()
  })
})

function ToastHarness() {
  const toast = useToast()
  return (
    <>
      <button type="button" onClick={() => toast.success('Regra criada.')}>
        sucesso
      </button>
      <button type="button" onClick={() => toast.error('Não foi possível salvar.')}>
        erro
      </button>
    </>
  )
}

describe('Toast', () => {
  it('mostra o aviso com papel de status e deixa dispensar', async () => {
    render(
      <ToastProvider>
        <ToastHarness />
      </ToastProvider>,
    )

    await userEvent.click(screen.getByRole('button', { name: 'sucesso' }))

    const status = await screen.findByRole('status')
    expect(status).toHaveTextContent('Regra criada.')
    expect(status).toHaveAttribute('data-variant', 'success')

    await userEvent.click(screen.getByRole('button', { name: 'Dispensar aviso' }))
    await waitFor(() => expect(screen.queryByRole('status')).not.toBeInTheDocument())
  })

  it('usa a variante de erro quando a mutação falha', async () => {
    render(
      <ToastProvider>
        <ToastHarness />
      </ToastProvider>,
    )

    await userEvent.click(screen.getByRole('button', { name: 'erro' }))

    const status = await screen.findByRole('status')
    expect(status).toHaveAttribute('data-variant', 'error')
  })
})
