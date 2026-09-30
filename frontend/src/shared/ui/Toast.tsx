import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { createPortal } from 'react-dom'

export type ToastVariant = 'success' | 'error' | 'info'

export interface ToastApi {
  success: (message: string) => void
  error: (message: string) => void
  info: (message: string) => void
}

interface Toast {
  id: number
  variant: ToastVariant
  message: string
}

const ToastContext = createContext<ToastApi | null>(null)

const AUTO_DISMISS_MS = 5000

const VARIANT_STYLES: Record<ToastVariant, string> = {
  success: 'border-success/40 bg-success/15 text-success',
  error: 'border-danger/40 bg-danger/15 text-danger',
  info: 'border-brand/40 bg-brand/15 text-brand',
}

/**
 * Avisos efêmeros de feedback de mutação (sucesso, erro, informação). Vive num
 * provider próprio para que qualquer tela precise só importar `useToast()`.
 */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([])
  const nextId = useRef(0)
  const timers = useRef(new Map<number, ReturnType<typeof setTimeout>>())

  const dismiss = useCallback((id: number) => {
    setToasts((current) => current.filter((toast) => toast.id !== id))
    const timer = timers.current.get(id)
    if (timer) {
      clearTimeout(timer)
      timers.current.delete(id)
    }
  }, [])

  const push = useCallback(
    (variant: ToastVariant, message: string) => {
      const id = nextId.current++
      setToasts((current) => [...current, { id, variant, message }])
      timers.current.set(
        id,
        setTimeout(() => dismiss(id), AUTO_DISMISS_MS),
      )
    },
    [dismiss],
  )

  useEffect(() => {
    const pending = timers.current
    return () => {
      for (const timer of pending.values()) clearTimeout(timer)
      pending.clear()
    }
  }, [])

  const api = useMemo<ToastApi>(
    () => ({
      success: (message: string) => push('success', message),
      error: (message: string) => push('error', message),
      info: (message: string) => push('info', message),
    }),
    [push],
  )

  return (
    <ToastContext.Provider value={api}>
      {children}
      {createPortal(
        <div
          className="pointer-events-none fixed inset-x-0 bottom-4 z-50 flex flex-col items-center gap-2 px-4"
          role="region"
          aria-label="Avisos"
        >
          {toasts.map((toast) => (
            <div
              key={toast.id}
              role="status"
              aria-live="polite"
              data-variant={toast.variant}
              className={`pointer-events-auto w-full max-w-sm rounded-card border px-4 py-3 text-sm shadow-pop backdrop-blur ${VARIANT_STYLES[toast.variant]}`}
            >
              <div className="flex items-start justify-between gap-3">
                <span>{toast.message}</span>
                <button
                  type="button"
                  onClick={() => dismiss(toast.id)}
                  aria-label="Dispensar aviso"
                  className="shrink-0 opacity-70 hover:opacity-100"
                >
                  ✕
                </button>
              </div>
            </div>
          ))}
        </div>,
        document.body,
      )}
    </ToastContext.Provider>
  )
}

export function useToast(): ToastApi {
  const context = useContext(ToastContext)
  if (!context) throw new Error('useToast precisa estar dentro de <ToastProvider>')
  return context
}
