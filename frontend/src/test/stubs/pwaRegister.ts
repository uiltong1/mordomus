import { vi } from 'vitest'

/**
 * Duble de `virtual:pwa-register` para os testes. O módulo virtual só existe
 * durante o build do plugin; no Vitest ele aponta para cá, de modo que o teste
 * consegue observar o que o `registerServiceWorker` pediu ao plugin.
 */
export const registerSW = vi.fn((_config?: unknown) => async (_reloadPage?: boolean) => {})

export function resetPwaRegister(): void {
  registerSW.mockClear()
}
