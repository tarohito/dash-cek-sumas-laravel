import path from 'node:path';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['src/index.css', 'src/main.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, '.'),
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    esbuild: {
        drop: ['debugger'],
    },
    build: {
        chunkSizeWarningLimit: 800,
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (!id.includes('node_modules')) {
                        return undefined;
                    }
                    if (id.includes('/react/') || id.includes('/react-dom/') || id.includes('/scheduler/')) {
                        return 'react-vendor';
                    }
                    if (id.includes('/recharts/') || id.includes('/d3-') || id.includes('/victory-vendor/')) {
                        return 'charts-vendor';
                    }
                    if (id.includes('/leaflet/')) {
                        return 'maps-vendor';
                    }
                    if (id.includes('/jspdf/') || id.includes('/qrcode/')) {
                        return 'pdf-qr-vendor';
                    }
                    if (id.includes('/lucide-react/')) {
                        return 'icons-vendor';
                    }
                    return undefined;
                },
            },
        },
    },
});
