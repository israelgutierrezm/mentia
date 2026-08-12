<script setup>
import { computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';

const props = defineProps({
    unidades: { type: Array, required: true },
});

const forma = reactive({
    nombre: '',
    tipo: 'plantel',
    unidad_padre_id: null,
});

// El árbol se arma en el cliente a partir de la lista plana: la jerarquía de
// una organización tiene tres o cuatro niveles, así que no vale la pena
// pedirla anidada al servidor.
const arbol = computed(() => {
    const porPadre = new Map();

    for (const unidad of props.unidades) {
        const llave = unidad.unidad_padre_id ?? 0;
        if (!porPadre.has(llave)) porPadre.set(llave, []);
        porPadre.get(llave).push(unidad);
    }

    const aplanar = (padre, nivel) =>
        (porPadre.get(padre) ?? []).flatMap((unidad) => [
            { ...unidad, nivel },
            ...aplanar(unidad.id, nivel + 1),
        ]);

    return aplanar(0, 0);
});

function guardar() {
    router.post('/unidades', forma, {
        preserveScroll: true,
        onSuccess: () => {
            forma.nombre = '';
            forma.unidad_padre_id = null;
        },
    });
}
</script>

<template>
    <Head title="Unidades" />

    <div class="mx-auto max-w-4xl space-y-8">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Unidades</h1>
            <p class="mt-1 text-sm text-slate-600">
                Planteles, sedes, departamentos y áreas. Un alcance sobre una
                unidad incluye a todas las que dependen de ella.
            </p>
        </header>

        <form class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4" @submit.prevent="guardar">
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">Nombre</span>
                <input v-model="forma.nombre" required class="rounded-md border border-slate-300 px-3 py-2" />
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">Tipo</span>
                <select v-model="forma.tipo" class="rounded-md border border-slate-300 px-3 py-2">
                    <option value="plantel">Plantel</option>
                    <option value="sede">Sede</option>
                    <option value="departamento">Departamento</option>
                    <option value="area">Área</option>
                </select>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">Depende de</span>
                <select v-model="forma.unidad_padre_id" class="rounded-md border border-slate-300 px-3 py-2">
                    <option :value="null">— Ninguna (raíz) —</option>
                    <option v-for="unidad in unidades" :key="unidad.id" :value="unidad.id">
                        {{ unidad.nombre }}
                    </option>
                </select>
            </label>

            <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                Agregar
            </button>
        </form>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-2 font-medium">Unidad</th>
                        <th class="px-4 py-2 font-medium">Tipo</th>
                        <th class="px-4 py-2 font-medium">Estado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="unidad in arbol" :key="unidad.id">
                        <td class="px-4 py-2">
                            <span :style="{ paddingLeft: `${unidad.nivel * 20}px` }">
                                {{ unidad.nombre }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-slate-600">{{ unidad.tipo }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ unidad.estado }}</td>
                    </tr>
                    <tr v-if="arbol.length === 0">
                        <td colspan="3" class="px-4 py-6 text-center text-slate-500">
                            Todavía no hay unidades.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
