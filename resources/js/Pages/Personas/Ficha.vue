<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    persona: { type: Object, required: true },
});
</script>

<template>
    <Head :title="persona.nombre_completo" />

    <div class="mx-auto max-w-3xl space-y-6">
        <Link href="/personas" class="text-sm text-blue-700 hover:underline">← Personas</Link>

        <header>
            <h1 class="text-2xl font-semibold tracking-tight">{{ persona.nombre_completo }}</h1>
            <p v-if="persona.es_menor" class="mt-1 text-sm text-amber-700">
                Menor de edad: quien actúe en su nombre necesita una tutoría validada.
            </p>
        </header>

        <dl class="grid gap-4 rounded-lg border border-slate-200 bg-white p-5 sm:grid-cols-2">
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500">CURP</dt>
                <dd class="mt-1 font-mono text-sm">{{ persona.curp ?? 'Sin CURP registrada' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500">Fecha de nacimiento</dt>
                <dd class="mt-1 text-sm">{{ persona.fecha_nacimiento }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500">Sexo registral</dt>
                <dd class="mt-1 text-sm">{{ persona.sexo_registral }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500">Verificación de identidad</dt>
                <dd class="mt-1 text-sm">{{ persona.verificacion_identidad }}</dd>
            </div>
        </dl>

        <p class="text-xs text-slate-500">
            El expediente y los resultados llegan en fases posteriores. Esta
            consulta quedó registrada en la bitácora.
        </p>
    </div>
</template>
