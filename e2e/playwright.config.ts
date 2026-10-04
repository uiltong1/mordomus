import { defineConfig, devices } from '@playwright/test'
import { appUrl } from './support/env'

/**
 * As jornadas rodam contra o ambiente real: o painel no Vite (5173) falando com
 * a API através do gateway nginx (8080). Nada de mock — o que o teste afirma é o
 * que o navegador e o gateway realmente trocaram.
 *
 * `E2E_APP_URL` e `E2E_API_URL` existem para o CI apontar o runner de outra
 * máquina; o padrão é o compose local.
 */
export default defineConfig({
  testDir: './tests',
  outputDir: './test-results',
  /* O rate limit do gateway é por IP (10 r/s com burst de 30): jornada paralela
     não mede o produto, mede o 429. */
  workers: 1,
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  timeout: 90_000,
  expect: { timeout: 15_000 },
  reporter: process.env.CI
    ? [['github'], ['html', { open: 'never' }]]
    : [['list']],
  use: {
    baseURL: appUrl,
    locale: 'pt-BR',
    timezoneId: 'America/Sao_Paulo',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
