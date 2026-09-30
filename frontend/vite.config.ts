import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
    // ADR-001: PWA instalável. `injectManifest` (e não generateSW) porque o
    // worker precisa do handler de `push` do Web Push ao lado do precache.
    VitePWA({
      strategies: 'injectManifest',
      srcDir: 'src',
      filename: 'sw.ts',
      // o registro é feito em src/pwa/registerServiceWorker.ts para poder
      // reportar o estado da instalação à interface
      injectRegister: null,
      registerType: 'prompt',
      injectManifest: {
        globPatterns: ['**/*.{js,css,html,svg,png,ico,webmanifest}'],
        maximumFileSizeToCacheInBytes: 4 * 1024 * 1024,
      },
      manifest: {
        id: '/',
        name: 'Mordomus — gestão doméstica',
        short_name: 'Mordomus',
        description: 'Manutenção da casa, agenda da família e contas em um lugar só.',
        lang: 'pt-BR',
        dir: 'ltr',
        start_url: '/',
        scope: '/',
        display: 'standalone',
        orientation: 'portrait-primary',
        theme_color: '#0f172a',
        background_color: '#020617',
        categories: ['productivity', 'utilities'],
        icons: [
          { src: '/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
          { src: '/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
          {
            src: '/icon-maskable-512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'maskable',
          },
        ],
      },
      devOptions: { enabled: false },
    }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: true,
    port: 5173,
    strictPort: true,
  },
  preview: {
    host: true,
    port: 5173,
    strictPort: true,
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    restoreMocks: true,
    // as chaves VAPID reais são da T6.1.4; o teste só precisa de uma chave
    // plausível para percorrer a assinatura
    env: { VITE_VAPID_PUBLIC_KEY: 'BEl62iUYgUivxIvc69QViQHuiYs8Ma8V0glpDHVJ5Y' },
    alias: {
      // módulo virtual do plugin: só existe no build, não no Vitest
      'virtual:pwa-register': fileURLToPath(
        new URL('./src/test/stubs/pwaRegister.ts', import.meta.url),
      ),
    },
    coverage: {
      provider: 'v8',
      include: ['src/**/*.{ts,tsx}'],
      exclude: ['src/test/**', 'src/main.tsx', 'src/sw.ts', 'src/**/index.ts'],
    },
  },
})
