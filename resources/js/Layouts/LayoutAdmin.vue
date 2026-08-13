<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

const pagina = usePage();

const usuario = computed(() => pagina.props.usuario);
const organizacion = computed(() => pagina.props.organizacion);
const avisos = computed(() => pagina.props.avisos ?? {});

// El menú se arma desde el servidor a partir de los permisos del rol activo
// (Fase 1). Cablearlo aquí obligaría a tocar el frontend cada vez que una
// organización define un rol nuevo, que es justo lo que el sistema evita.
const secciones = computed(() => pagina.props.menu ?? []);

/** Las secciones agrupadas, conservando el orden en que llegaron. */
const grupos = computed(() => {
    const porGrupo = new Map();

    secciones.value.forEach((seccion) => {
        if (!porGrupo.has(seccion.grupo)) {
            porGrupo.set(seccion.grupo, []);
        }

        porGrupo.get(seccion.grupo).push(seccion);
    });

    return Array.from(porGrupo, ([grupo, items]) => ({ grupo, items }));
});
</script>

<template>
    <Head />

    <div class="flex min-h-full">
        <aside class="hidden w-64 shrink-0 border-r border-slate-200 bg-white lg:block">
            <div class="flex h-16 items-center gap-2 border-b border-slate-200 px-6">
                <span class="text-lg font-semibold tracking-tight">Mentia</span>
            </div>

            <nav v-if="secciones.length" class="space-y-4 p-4">
                <Link
                    href="/"
                    class="block rounded-md px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-100"
                >
                    Panel
                </Link>

                <div v-for="bloque in grupos" :key="bloque.grupo" class="space-y-1">
                    <p class="px-3 text-xs font-semibold uppercase tracking-wide text-slate-400">
                        {{ bloque.grupo }}
                    </p>

                    <Link
                        v-for="seccion in bloque.items"
                        :key="seccion.clave"
                        :href="seccion.url"
                        class="flex items-center justify-between rounded-md px-3 py-2 text-sm text-slate-700 hover:bg-slate-100"
                    >
                        <span>{{ seccion.etiqueta }}</span>

                        <!--
                            El contador de alertas abiertas se ve desde
                            cualquier pantalla. Es el único número del menú
                            porque es el único que no puede esperar.
                        -->
                        <span
                            v-if="seccion.contador"
                            class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-900"
                        >
                            {{ seccion.contador }}
                        </span>
                    </Link>
                </div>
            </nav>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-16 items-center justify-between border-b border-slate-200 bg-white px-6">
                <p class="text-sm text-slate-500">
                    {{ organizacion?.nombre ?? 'Sin organización activa' }}
                </p>

                <p class="text-sm text-slate-700">
                    {{ usuario?.nombre ?? 'Sin sesión' }}
                </p>
            </header>

            <div
                v-if="avisos.exito || avisos.error"
                class="px-6 pt-4"
            >
                <p
                    class="rounded-md px-4 py-3 text-sm"
                    :class="avisos.error
                        ? 'bg-rose-50 text-rose-800'
                        : 'bg-emerald-50 text-emerald-800'"
                >
                    {{ avisos.error ?? avisos.exito }}
                </p>
            </div>

            <main class="flex-1 p-6">
                <slot />
            </main>
        </div>
    </div>
</template>
