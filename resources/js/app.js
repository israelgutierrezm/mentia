import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import LayoutAdmin from './Layouts/LayoutAdmin.vue';

const nombre = import.meta.env.VITE_APP_NAME || 'Mentia';

createInertiaApp({
    title: (titulo) => (titulo ? `${titulo} · ${nombre}` : nombre),

    /*
     * El glob NO es `eager`: cada página es su propio trozo y se baja cuando se
     * visita. Con `eager: true` el paquete es uno solo, y entonces quien entra a
     * `/contestar` desde un celular con mala señal se descarga el panel de
     * administración entero —baterías, catálogo, roles— para ver cinco
     * reactivos. La pantalla pública es justamente la que peor red tiene.
     */
    resolve: async (pagina) => {
        const paginas = import.meta.glob('./Pages/**/*.vue');
        const cargar = paginas[`./Pages/${pagina}.vue`];

        if (!cargar) {
            throw new Error(`No existe la página Inertia "${pagina}".`);
        }

        const componente = await cargar();

        /*
         * El layout se aplica por omisión; una página lo cambia declarando su
         * propio `layout`. Así una pantalla pública —el canje de token anónimo,
         * sin sesión— no arrastra la barra lateral del panel.
         *
         * La comparación es contra `undefined` y no un `??=`: una página que
         * declara `layout: null` está diciendo "ninguno", y `??=` se lo
         * sobrescribiría con el del panel justo en el caso que quiere evitarlo.
         */
        if (componente.default.layout === undefined) {
            componente.default.layout = LayoutAdmin;
        }

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
