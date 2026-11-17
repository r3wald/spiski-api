import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

// Die Admin-Oberflaeche liegt unter /admin/ und wird als statische Dateien nach
// ../public/admin gebaut; Caddy liefert sie ohne PHP aus (docker/frankenphp/Caddyfile*).
// Vite 6, weil der Container Node 18 aus Debian bookworm mitbringt.
export default defineConfig({
  base: '/admin/',
  plugins: [vue()],
  build: {
    outDir: '../public/admin',
    emptyOutDir: true,
    // Kein Inline-Code: Die Content-Security-Policy erlaubt nur Skripte und Styles von 'self'.
    assetsInlineLimit: 0,
  },
  server: {
    // `make admin-dev`: laeuft im php-Container, die API ist dort Caddy auf Port 80.
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    proxy: {
      '/v1': 'http://localhost:80',
    },
  },
  test: {
    environment: 'node',
    include: ['src/**/*.test.ts'],
  },
})
