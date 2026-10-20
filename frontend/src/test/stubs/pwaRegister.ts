import { vi } from 'vitest'

export const registerSW = vi.fn((_config?: unknown) => async (_reloadPage?: boolean) => {})

export function resetPwaRegister(): void {
  registerSW.mockClear()
}
