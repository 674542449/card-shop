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
    rollupOptions: {
      output: {
        // Keep the package's cyclic re-exports together without pulling its
        // entire dependency tree into the initial bundle. Pages stay lazy.
        onlyExplicitManualChunks: true,
        manualChunks(id) {
          const path = id.replaceAll('\\', '/');
          if (path.endsWith('/@ant-design/pro-utils/es/index.js')
            || path.endsWith('/@ant-design/pro-utils/es/useEditableArray/index.js')) {
            return 'pro-utils-cycle';
          }
        },
      },
    },
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
