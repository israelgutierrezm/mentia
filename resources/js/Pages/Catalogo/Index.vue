<script setup>
import { reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    instrumentos: { type: Array, required: true },
    dominios: { type: Array, required: true },
    categorias: { type: Array, required: true },
    filtros: { type: Object, required: true },
});

const filtro = reactive({
    texto: props.filtros.texto ?? '',
    dominio: props.filtros.dominio ?? '',
    estatus_licencia: props.filtros.estatus_licencia ?? '',
});

let temporizador = null;

watch(filtro, () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        router.get('/catalogo', { ...filtro }, { preserveState: true, replace: true });
    }, 300);
});

const etiquetasLicencia = {
    dominio_publico: 'Dominio público',
    requiere_licencia_tenant: 'Requiere licencia',
    solo_captura: 'Sólo captura',
};

const coloresLicencia = {
    dominio_publico: 'bg-emerald-50 text-emerald-800',
    requiere_licencia_tenant: 'bg-amber-50 text-amber-800',
    solo_captura: 'bg-slate-100 text-slate-600',
};
</script>

<template>
    <Head title="Catálogo de instrumentos" />

    <div class="mx-auto max-w-5xl space-y-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Catálogo</h1>
            <p class="mt-1 text-sm text-slate-600">
                Aquí está la ficha técnica de cada instrumento. Los reactivos no
                se muestran: el contenido sólo se entrega durante la aplicación.
            </p>
        </header>

        <div class="flex flex-wrap gap-3 rounded-lg border border-slate-200 bg-white p-4">
            <input
                v-model="filtro.texto"
                placeholder="Buscar por nombre o clave"
                class="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm"
            />

            <select v-model="filtro.dominio" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option value="">Todos los dominios</option>
                <option v-for="dominio in dominios" :key="dominio.clave" :value="dominio.clave">
                    {{ dominio.nombre }}
                </option>
            </select>

            <select v-model="filtro.estatus_licencia" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option value="">Cualquier licencia</option>
                <option value="dominio_publico">Dominio público</option>
                <option value="requiere_licencia_tenant">Requiere licencia</option>
                <option value="solo_captura">Sólo captura</option>
            </select>
        </div>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-2 font-medium">Instrumento</th>
                        <th class="px-4 py-2 font-medium">Dominio</th>
                        <th class="px-4 py-2 font-medium">Licencia</th>
                        <th class="px-4 py-2 font-medium">Sens.</th>
                        <th class="px-4 py-2 font-medium">Versiones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="instrumento in instrumentos" :key="instrumento.clave">
                        <td class="px-4 py-2">
                            <Link :href="`/catalogo/${instrumento.clave}`" class="text-blue-700 hover:underline">
                                {{ instrumento.nombre }}
                            </Link>
                            <span v-if="!instrumento.se_aplica_en_linea" class="ml-2 text-xs text-slate-500">
                                (no se aplica en línea)
                            </span>
                        </td>
                        <td class="px-4 py-2 text-slate-600">{{ instrumento.dominio }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-1 text-xs" :class="coloresLicencia[instrumento.estatus_licencia]">
                                {{ etiquetasLicencia[instrumento.estatus_licencia] }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-slate-600">{{ instrumento.nivel_sensibilidad }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ instrumento.versiones_publicadas }}</td>
                    </tr>
                    <tr v-if="instrumentos.length === 0">
                        <td colspan="5" class="px-4 py-6 text-center text-slate-500">
                            No hay instrumentos que coincidan.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
