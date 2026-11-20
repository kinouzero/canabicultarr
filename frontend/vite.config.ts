import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { publicOfflineFallback } from './build/pwa.ts';
import { version as packageVersion } from './package.json' with { type: 'json' };
const version = process.env.CANABICULTARR_BUILD_VERSION ?? packageVersion;

export default defineConfig({
  define: { 'import.meta.env.VITE_APP_VERSION': JSON.stringify(version) },
  plugins: [vue(), publicOfflineFallback(), {
    name: 'application-version',
    apply: 'build',
    generateBundle() {
      this.emitFile({ type: 'asset', fileName: 'application.json', source: JSON.stringify({ version }) });
    },
  }],
  server: { proxy: { '/api': process.env.API_PROXY_URL ?? `http://127.0.0.1:${process.env.APP_PORT ?? '8420'}` } },
});
