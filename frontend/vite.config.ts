import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig, type Plugin } from 'vite'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const rootDir = path.dirname(fileURLToPath(import.meta.url))
const ioniconsSvgDir = path.resolve(
  rootDir,
  'node_modules/ionicons/dist/ionicons/svg',
)
const serveAt = '/ionicons/svg'

// Keep Ionicons' SVG catalogue at a stable URL in development and in the build.
function ioniconsAssets(): Plugin {
  return {
    name: 'ionicons-static-assets',
    configureServer: (server) => {
      server.middlewares.use(serveAt, (req, res, next) => {
        const file = path.join(ioniconsSvgDir, decodeURIComponent(req.url ?? ''))
        if (!file.startsWith(ioniconsSvgDir) || !fs.existsSync(file)) {
          next()
          return
        }
        res.setHeader('Content-Type', 'image/svg+xml')
        fs.createReadStream(file).pipe(res)
      })
    },
    closeBundle: () => {
      fs.cpSync(ioniconsSvgDir, path.resolve(rootDir, `dist${serveAt}`), {
        recursive: true,
      })
    },
  }
}

export default defineConfig({
  plugins: [ioniconsAssets(), react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(rootDir, './src'),
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
})
