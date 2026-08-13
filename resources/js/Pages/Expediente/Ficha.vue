<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    persona: { type: Object, required: true },
    secciones: { type: Array, required: true },
});

const etiquetasNivel = {
    1: 'General',
    2: 'Laboral',
    3: 'Psicológico',
    4: 'Clínico',
};

const coloresNivel = {
    1: 'bg-slate-100 text-slate-600',
    2: 'bg-sky-50 text-sky-800',
    3: 'bg-amber-50 text-amber-800',
    4: 'bg-rose-50 text-rose-800',
};
</script>

<template>
    <Head :title="`Expediente · ${persona.nombre_completo}`" />

    <div class="mx-auto max-w-4xl space-y-8">
        <div>
            <Link :href="`/personas/${persona.uuid}`" class="text-sm text-blue-700 hover:underline">
                ← {{ persona.nombre_completo }}
            </Link>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight">Expediente</h1>
            <p class="mt-1 text-sm text-slate-600">
                Sólo se muestran las secciones que tu rol alcanza. Lo que no
                aparece no está oculto en la pantalla: no salió del servidor.
            </p>
        </div>

        <section
            v-for="seccion in secciones"
            :key="seccion.clave"
            class="overflow-hidden rounded-lg border border-slate-200 bg-white"
        >
            <header class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                <h2 class="font-medium text-slate-800">{{ seccion.nombre }}</h2>
                <span class="rounded-full px-2 py-1 text-xs" :class="coloresNivel[seccion.nivel_sensibilidad]">
                    {{ etiquetasNivel[seccion.nivel_sensibilidad] }}
                </span>
            </header>

            <dl v-if="seccion.campos.length" class="divide-y divide-slate-100">
                <div v-for="campo in seccion.campos" :key="campo.id" class="flex gap-4 px-5 py-3 text-sm">
                    <dt class="w-64 shrink-0 text-slate-500">{{ campo.etiqueta }}</dt>
                    <dd class="text-slate-800">
                        <span v-if="campo.valor !== null && campo.valor !== undefined">
                            {{ campo.valor }}
                        </span>
                        <span v-else class="text-slate-400">Sin capturar</span>
                        <span v-if="campo.version > 1" class="ml-2 text-xs text-slate-400">
                            v{{ campo.version }}
                        </span>
                    </dd>
                </div>
            </dl>

            <p v-else class="px-5 py-4 text-sm text-slate-500">
                Esta sección no tiene campos configurados.
            </p>
        </section>

        <p v-if="secciones.length === 0" class="rounded-lg border border-slate-200 bg-white p-6 text-center text-sm text-slate-500">
            No alcanzas ninguna sección de este expediente.
        </p>
    </div>
</template>
