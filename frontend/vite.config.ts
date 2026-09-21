import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig, type Plugin } from 'vite'
import fs from 'node:fs'
import path from 'node:path'

const ioniconsSvgDir = path.resolve(
  __dirname,
  'node_modules/ionicons/dist/ionicons/svg',
)
const serveAt = '/ionicons/svg'

// <ion-icon> fetches its SVGs by name relative to the document base, so the
// catalogue has to live at a stable URL in both dev and the built bundle.
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
      fs.cpSync(ioniconsSvgDir, path.resolve(__dirname, `dist${serveAt}`), {
        recursive: true,
      })
    },
  }
}

// https://vite.dev/config/
export default defineConfig({
  plugins: [ioniconsAssets(), react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
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
