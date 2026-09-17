import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    // Set DEV_SERVER_HOST (e.g. 192.168.1.9) in .env to develop against the
    // app from another device on the LAN (phone, tablet). It must be this
    // machine's LAN IP: Vite writes it into public/hot so the device fetches
    // assets from the PC instead of its own localhost. Leave unset for the
    // default localhost-only dev server.
    const devHost = env.DEV_SERVER_HOST;

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.jsx'],
                refresh: true,
            }),
            react(),
            tailwindcss(),
        ],
        server: {
            host: devHost ? true : 'localhost',
            ...(devHost ? { hmr: { host: devHost }, allowedHosts: [devHost] } : {}),
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
