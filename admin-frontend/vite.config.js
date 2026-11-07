import vue from '@vitejs/plugin-vue'
import { defineConfig, loadEnv } from 'vite'
import { fileURLToPath, URL } from 'node:url'

// Dev:   npm run dev   -> http://localhost:5190  (proxies /api and /sanctum to the Laravel backend)
// Build: npm run build -> ../backend/public/admin-assets (Laravel serves the app at /admin, same origin as the API)
export default defineConfig(({ command, mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const backend = env.BACKEND_URL || 'http://localhost:8002'   // php artisan serve --port=8002 (run in backend/)

  return {
    // Files live in backend/public/admin-assets (not public/admin) so the URL space /admin/* stays free for the app's own
    // routes; Laravel serves the HTML shell for every /admin/... URL (see backend/routes/web.php).
    base: command === 'build' ? '/admin-assets/' : '/',
    plugins: [vue()],
    resolve: {
      alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
    },
    server: {
      port: 5190,
      strictPort: true,
      // Same-origin in the browser => the session cookie works without CORS tricks.
      proxy: {
        '/api': { target: backend, changeOrigin: false },
        '/sanctum': { target: backend, changeOrigin: false },
        '/storage': { target: backend, changeOrigin: false },
        '/admin-sw.js': { target: backend, changeOrigin: false },   // the push service worker lives in Laravel's public folder
      },
    },
    build: {
      outDir: '../backend/public/admin-assets',
      emptyOutDir: true,
      chunkSizeWarningLimit: 600,
    },
  }
})
