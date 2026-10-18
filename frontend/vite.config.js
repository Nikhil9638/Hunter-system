import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Hunter System student interface.
// - Served by Apache under /hunter-system/ (router basename /hunter-system).
// - Build output: frontend/dist; static files under dist/static/ so they never
//   collide with the badge-artwork path /hunter-system/assets/badges/.
// - Dev: http://localhost:5173/hunter-system/ with /hunter-system/api proxied to
//   Apache (http://localhost). The session cookie (Path=/hunter-system/) is
//   host-scoped, so it works through the proxy. The API refuses unknown Origins,
//   so the proxy presents the dev request with the Apache origin (dev only).
// HUNTER_API_TARGET overrides the proxy target (used only by the browser tests to
// point `vite preview` at the isolated fixture API on 127.0.0.1:8098).
const apiTarget = process.env.HUNTER_API_TARGET || 'http://localhost';

export default defineConfig({
  base: '/hunter-system/',
  plugins: [react()],
  build: {
    outDir: 'dist',
    assetsDir: 'static',
    emptyOutDir: true,
    sourcemap: false,
  },
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/hunter-system/api': {
        target: apiTarget,
        changeOrigin: true,
        configure(proxy) {
          proxy.on('proxyReq', (req) => {
            if (req.getHeader('origin')) req.setHeader('origin', 'http://localhost');
          });
        },
      },
    },
  },
});
