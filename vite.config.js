import { defineConfig, loadEnv, defaultAllowedOrigins } from 'vite';
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
    const devPort = env.DEV_SERVER_PORT || 8000;

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
            ...(devHost
                ? {
                      hmr: { host: devHost },
                      allowedHosts: [devHost],
                      // The page is served by Laravel on :8000 while modules are
                      // served by Vite on :5173, so the device loads them
                      // cross-origin. Vite must send Access-Control-Allow-Origin
                      // for the app's LAN origin (localhost stays allowed).
                      cors: {
                          origin: [defaultAllowedOrigins, `http://${devHost}:${devPort}`],
                      },
                  }
                : {}),
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
