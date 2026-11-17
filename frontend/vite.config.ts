import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { publicOfflineFallback } from './build/pwa.ts';
export default defineConfig({ plugins: [vue(), publicOfflineFallback()], server: { proxy: { '/api': process.env.API_PROXY_URL ?? `http://127.0.0.1:${process.env.APP_PORT ?? '8420'}` } } });
