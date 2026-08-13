<script setup>
/**
 * El panel, armado por tarjetas declaradas con su permiso en el servidor.
 *
 * Aquí no hay una sola rama por rol: llega lo que la persona alcanza y se
 * dibuja. Filtrar en el cliente le diría al navegador qué existe, y cualquiera
 * abriría las herramientas de desarrollo para ver el mapa de un sistema al que
 * no tiene acceso.
 */
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    tarjetas: { type: Array, required: true },
    pendientes: { type: Array, required: true },
});

/** Las tarjetas agrupadas, conservando el orden en que llegaron. */
const grupos = computed(() => {
    const porGrupo = new Map();

    props.tarjetas.forEach((tarjeta) => {
        if (!porGrupo.has(tarjeta.grupo)) {
            porGrupo.set(tarjeta.grupo, []);
        }

        porGrupo.get(tarjeta.grupo).push(tarjeta);
    });

    return Array.from(porGrupo, ([grupo, items]) => ({ grupo, items }));
});
</script>

<template>
    <Head title="Panel" />

    <div class="mx-auto max-w-4xl space-y-8">
        <!--
            Lo pendiente ARRIBA y con número. Un panel que abre con un saludo y
            deja las tres alertas críticas escondidas en el menú lateral es un
            panel que no sirve para trabajar.
        -->
        <section v-if="pendientes.length" class="space-y-2">
            <Link
                v-for="pendiente in pendientes"
                :key="pendiente.clave"
                :href="pendiente.url"
                class="block rounded-lg px-4 py-3 text-sm font-medium transition"
                :class="pendiente.urgente
                    ? 'bg-rose-100 text-rose-900 hover:bg-rose-200'
                    : 'bg-slate-100 text-slate-800 hover:bg-slate-200'"
            >
                {{ pendiente.etiqueta }} →
            </Link>
        </section>

        <section v-for="bloque in grupos" :key="bloque.grupo" class="space-y-3">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                {{ bloque.grupo }}
            </h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <Link
                    v-for="tarjeta in bloque.items"
                    :key="tarjeta.clave"
                    :href="tarjeta.url"
                    class="rounded-xl border border-slate-200 bg-white p-4 transition hover:border-slate-400"
                >
                    <p class="font-medium text-slate-900">{{ tarjeta.etiqueta }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ tarjeta.descripcion }}</p>
                </Link>
            </div>
        </section>

        <!--
            Sin tarjetas: o no hay organización activa, o el rol no alcanza
            nada. Se dice, en vez de dejar la pantalla en blanco.
        -->
        <p
            v-if="tarjetas.length === 0"
            class="rounded-lg bg-slate-50 p-6 text-center text-sm text-slate-500"
        >
            Tu rol todavía no tiene acceso a ninguna sección. Pídele a quien
            administra la organización que revise tus permisos.
        </p>
    </div>
</template>
