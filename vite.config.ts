import { fileURLToPath, URL } from "url";
import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";
import path from "path";
import VueI18nPlugin from "@intlify/unplugin-vue-i18n/vite";
import laravel from "laravel-vite-plugin";

export default defineConfig({
    plugins: [
        laravel({
            input: ["resources/js/src/main.ts"],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    includeAbsolute: false,
                },
            },
        }),
        VueI18nPlugin({
            include: path.resolve("resources/js/src/locales/**"),
        }),
    ],
    resolve: {
        alias: {
            "@": path.resolve("resources/js/src"),
        },
    },
    optimizeDeps: {
        include: ["quill"],
    },
});
