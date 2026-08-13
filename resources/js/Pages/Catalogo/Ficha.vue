<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    instrumento: { type: Object, required: true },
});

function meses(valor) {
    if (valor === null || valor === undefined) return '—';
    const anios = Math.floor(valor / 12);
    const resto = valor % 12;
    return resto === 0 ? `${anios} años` : `${anios} años ${resto} meses`;
}
</script>

<template>
    <Head :title="instrumento.nombre" />

    <div class="mx-auto max-w-4xl space-y-6">
        <div>
            <Link href="/catalogo" class="text-sm text-blue-700 hover:underline">← Catálogo</Link>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight">{{ instrumento.nombre }}</h1>
            <p class="mt-1 font-mono text-xs text-slate-500">{{ instrumento.clave }}</p>
        </div>

        <div
            v-if="!instrumento.se_aplica_en_linea"
            class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            Este instrumento <strong>no se aplica en línea</strong>: la editorial lo
            prohíbe. Existe en el catálogo para registrar sus resultados capturados.
        </div>

        <dl class="grid gap-4 rounded-lg border border-slate-200 bg-white p-5 sm:grid-cols-3">
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500">Dominio</dt>
                <dd class="mt-1 text-sm">{{ instrumento.dominio }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500">Categoría</dt>
                <dd class="mt-1 text-sm">{{ instrumento.categoria ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500">Sensibilidad</dt>
                <dd class="mt-1 text-sm">Nivel {{ instrumento.nivel_sensibilidad }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500">Quién responde</dt>
                <dd class="mt-1 text-sm">{{ instrumento.quien_responde }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500">Calificación</dt>
                <dd class="mt-1 text-sm">{{ instrumento.modo_calificacion }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500">Duración</dt>
                <dd class="mt-1 text-sm">
                    {{ instrumento.duracion_estimada_min ? `${instrumento.duracion_estimada_min} min` : '—' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500">Edad</dt>
                <dd class="mt-1 text-sm">
                    {{ meses(instrumento.edad_min_meses) }} – {{ meses(instrumento.edad_max_meses) }}
                </dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-xs uppercase tracking-wide text-slate-500">Ficha</dt>
                <dd class="mt-1 text-sm">
                    {{ instrumento.ficha.autor ?? 'Autor no registrado' }}
                    <span v-if="instrumento.ficha.anio">({{ instrumento.ficha.anio }})</span>
                </dd>
            </div>
        </dl>

        <section
            v-for="version in instrumento.versiones"
            :key="version.id"
            class="overflow-hidden rounded-lg border border-slate-200 bg-white"
        >
            <header class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                <h2 class="font-medium">Versión {{ version.version }} · {{ version.idioma }}</h2>
                <span
                    class="rounded-full px-2 py-1 text-xs"
                    :class="version.estado === 'publicada'
                        ? 'bg-emerald-50 text-emerald-800'
                        : 'bg-slate-100 text-slate-600'"
                >
                    {{ version.estado }}
                </span>
            </header>

            <div class="px-5 py-3 text-sm text-slate-600">
                {{ version.reactivos }} reactivos · {{ version.escalas.length }} escalas
            </div>

            <ul v-if="version.escalas.length" class="divide-y divide-slate-100 text-sm">
                <li v-for="escala in version.escalas" :key="escala.clave" class="flex gap-3 px-5 py-2">
                    <span class="w-20 shrink-0 font-mono text-xs text-slate-500">{{ escala.clave }}</span>
                    <span>{{ escala.nombre }}</span>
                    <span v-if="escala.es_validez" class="text-xs text-amber-700">validez</span>
                </li>
            </ul>
        </section>
    </div>
</template>
