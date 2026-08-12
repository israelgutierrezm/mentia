import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import LayoutAdmin from './Layouts/LayoutAdmin.vue';

const nombre = import.meta.env.VITE_APP_NAME || 'Mentia';

createInertiaApp({
    title: (titulo) => (titulo ? `${titulo} · ${nombre}` : nombre),

    resolve: (pagina) => {
        const paginas = import.meta.glob('./Pages/**/*.vue', { eager: true });
        const componente = paginas[`./Pages/${pagina}.vue`];

        if (!componente) {
            throw new Error(`No existe la página Inertia "${pagina}".`);
        }

        // El layout se aplica por omisión; una página lo cambia declarando
        // su propio `layout`. Así una pantalla pública —el canje de token
        // anónimo, sin sesión— no arrastra la barra lateral del panel.
        componente.default.layout ??= LayoutAdmin;

        return componente;
    },

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },

    progress: {
        color: '#2563eb',
    },
});
