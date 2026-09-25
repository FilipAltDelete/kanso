import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// One bundle for every installation: nothing environment-specific is baked in.
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    host: '0.0.0.0',
    // Also passed on the command line in the `dev` script, so a restart Vite
    // triggers itself never falls back to a port the proxy does not know.
    port: 8080,
    strictPort: true,
    // The browser reaches the app through the reverse proxy, so the HMR socket
    // has to be told which port it is actually talking to.
    hmr: { clientPort: Number(process.env.HMR_CLIENT_PORT ?? 8090) },
    // The end-to-end suite reaches the app as http://proxy:8080 from inside the
    // compose network (make e2e); Vite refuses hosts it was not told about.
    allowedHosts: ['proxy'],
  },
  build: { outDir: 'dist', sourcemap: false },
  test: {
    environment: 'jsdom',
    globals: true,
    include: ['src/**/*.test.{js,jsx}'],
  },
});
