import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  base: '/admin-assets/',
  build: {
    outDir: '../public/admin-assets',
    emptyOutDir: true,
    // Required: without this Vite 5 never writes .vite/manifest.json, and
    // spa.blade.php has no way to find the hashed entry files.
    manifest: true,
  },
  server: {
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
      '/sanctum': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
});
