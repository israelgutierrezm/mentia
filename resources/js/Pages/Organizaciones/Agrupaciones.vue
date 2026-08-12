<script setup>
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    agrupaciones: { type: Array, required: true },
    unidades: { type: Array, required: true },
    tipos: { type: Array, required: true },
});

const forma = useForm({
    nombre: '',
    tipo_agrupacion_id: null,
    unidad_id: null,
});

function guardar() {
    forma.post('/agrupaciones', {
        preserveScroll: true,
        onSuccess: () => forma.reset(),
    });
}
</script>

<template>
    <Head title="Agrupaciones" />

    <div class="mx-auto max-w-4xl space-y-8">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Agrupaciones</h1>
            <p class="mt-1 text-sm text-slate-600">
                El conjunto al que se le lanza una evaluación: un grupo, una
                vacante, un centro de trabajo.
            </p>
        </header>

        <form
            class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4"
            @submit.prevent="guardar"
        >
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">Nombre</span>
                <input v-model="forma.nombre" required class="rounded-md border border-slate-300 px-3 py-2" />
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">Tipo</span>
                <select v-model="forma.tipo_agrupacion_id" required class="rounded-md border border-slate-300 px-3 py-2">
                    <option :value="null" disabled>Elige un tipo</option>
                    <option v-for="tipo in tipos" :key="tipo.id" :value="tipo.id">{{ tipo.nombre }}</option>
                </select>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">Unidad</span>
                <select v-model="forma.unidad_id" class="rounded-md border border-slate-300 px-3 py-2">
                    <option :value="null">— Sin unidad —</option>
                    <option v-for="unidad in unidades" :key="unidad.id" :value="unidad.id">
                        {{ unidad.nombre }}
                    </option>
                </select>
            </label>

            <button
                type="submit"
                :disabled="forma.processing"
                class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
            >
                Agregar
            </button>
        </form>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-2 font-medium">Agrupación</th>
                        <th class="px-4 py-2 font-medium">Miembros vigentes</th>
                        <th class="px-4 py-2 font-medium">Estado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="agrupacion in agrupaciones" :key="agrupacion.id">
                        <td class="px-4 py-2">{{ agrupacion.nombre }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ agrupacion.miembros_vigentes_count ?? 0 }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ agrupacion.estado }}</td>
                    </tr>
                    <tr v-if="agrupaciones.length === 0">
                        <td colspan="3" class="px-4 py-6 text-center text-slate-500">
                            Todavía no hay agrupaciones.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
