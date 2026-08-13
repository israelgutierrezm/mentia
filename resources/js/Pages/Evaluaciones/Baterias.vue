<script setup>
import { ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import draggable from 'vuedraggable';

const props = defineProps({
    baterias: { type: Array, required: true },
    disponibles: { type: Array, required: true },
});

const abierta = ref(props.baterias[0]?.id ?? null);

// Copia local para que el arrastre se vea inmediato; el orden se manda al
// soltar, no en cada movimiento.
const orden = ref({});

watch(
    () => props.baterias,
    (lista) => {
        for (const bateria of lista) {
            orden.value[bateria.id] = [...bateria.instrumentos];
        }
    },
    { immediate: true, deep: true },
);

const formaBateria = useForm({ clave: '', nombre: '', descripcion: '' });
const formaAgregar = useForm({ version_instrumento_id: null, obligatorio: true });

function crear() {
    formaBateria.post('/baterias', {
        preserveScroll: true,
        onSuccess: () => formaBateria.reset(),
    });
}

function agregar(bateriaId) {
    formaAgregar.post(`/baterias/${bateriaId}/instrumentos`, {
        preserveScroll: true,
        onSuccess: () => formaAgregar.reset(),
    });
}

function quitar(bateriaId, renglonId) {
    router.delete(`/baterias/${bateriaId}/instrumentos/${renglonId}`, { preserveScroll: true });
}

function guardarOrden(bateriaId) {
    router.post(
        `/baterias/${bateriaId}/orden`,
        { orden: orden.value[bateriaId].map((renglon) => renglon.id) },
        { preserveScroll: true },
    );
}

function activar(bateriaId) {
    router.post(`/baterias/${bateriaId}/activar`, {}, { preserveScroll: true });
}

function minutosDe(bateriaId) {
    return (orden.value[bateriaId] ?? []).reduce(
        (total, renglon) => total + (renglon.duracion ?? 0),
        0,
    );
}

const coloresEstado = {
    borrador: 'bg-slate-100 text-slate-600',
    activa: 'bg-emerald-50 text-emerald-800',
    archivada: 'bg-slate-100 text-slate-400',
};
</script>

<template>
    <Head title="Baterías" />

    <div class="mx-auto max-w-4xl space-y-8">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Baterías</h1>
            <p class="mt-1 text-sm text-slate-600">
                Un conjunto de instrumentos que se aplican juntos. Sólo se
                pueden agregar los que esta organización tiene habilitados: una
                batería con un instrumento apagado reventaría al asignarla.
            </p>
        </header>

        <form
            class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4"
            @submit.prevent="crear"
        >
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">Clave</span>
                <input v-model="formaBateria.clave" required class="rounded-md border border-slate-300 px-3 py-2 font-mono text-xs" />
                <span v-if="formaBateria.errors.clave" class="text-xs text-rose-600">{{ formaBateria.errors.clave }}</span>
            </label>

            <label class="flex flex-1 flex-col gap-1 text-sm">
                <span class="text-slate-700">Nombre</span>
                <input v-model="formaBateria.nombre" required class="rounded-md border border-slate-300 px-3 py-2" />
            </label>

            <button
                type="submit"
                :disabled="formaBateria.processing"
                class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
            >
                Crear batería
            </button>
        </form>

        <article
            v-for="bateria in baterias"
            :key="bateria.id"
            class="overflow-hidden rounded-lg border border-slate-200 bg-white"
        >
            <header
                class="flex cursor-pointer items-center justify-between border-b border-slate-200 px-5 py-3"
                @click="abierta = abierta === bateria.id ? null : bateria.id"
            >
                <div>
                    <h2 class="font-medium text-slate-800">{{ bateria.nombre }}</h2>
                    <p class="text-xs text-slate-500">
                        {{ bateria.instrumentos.length }} instrumentos
                        <span v-if="minutosDe(bateria.id)"> · ~{{ minutosDe(bateria.id) }} min</span>
                    </p>
                </div>

                <span class="rounded-full px-2 py-1 text-xs" :class="coloresEstado[bateria.estado]">
                    {{ bateria.estado }}
                </span>
            </header>

            <div v-if="abierta === bateria.id" class="space-y-4 p-5">
                <p class="text-xs text-slate-500">
                    Arrastra para cambiar el orden en que se contestan. El orden
                    importa: lo que va al final se contesta más cansado.
                </p>

                <draggable
                    v-model="orden[bateria.id]"
                    item-key="id"
                    handle=".asa"
                    class="space-y-2"
                    @end="guardarOrden(bateria.id)"
                >
                    <template #item="{ element, index }">
                        <div class="flex items-center gap-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                            <span class="asa cursor-grab select-none text-slate-400" title="Arrastrar">⠿</span>
                            <span class="w-6 text-xs text-slate-400">{{ index + 1 }}</span>
                            <span class="flex-1">
                                {{ element.nombre }}
                                <span class="text-xs text-slate-500">{{ element.version }}</span>
                            </span>
                            <span v-if="!element.obligatorio" class="text-xs text-slate-500">opcional</span>
                            <button
                                class="text-xs text-rose-700 hover:underline"
                                @click="quitar(bateria.id, element.id)"
                            >
                                Quitar
                            </button>
                        </div>
                    </template>
                </draggable>

                <p v-if="bateria.instrumentos.length === 0" class="text-sm text-slate-500">
                    Todavía no tiene instrumentos.
                </p>

                <div class="flex flex-wrap items-end gap-3 border-t border-slate-100 pt-4">
                    <label class="flex flex-1 flex-col gap-1 text-sm">
                        <span class="text-slate-700">Agregar instrumento</span>
                        <select v-model="formaAgregar.version_instrumento_id" class="rounded-md border border-slate-300 px-3 py-2">
                            <option :value="null" disabled>Elige uno habilitado</option>
                            <option v-for="version in disponibles" :key="version.id" :value="version.id">
                                {{ version.nombre }} — {{ version.version }}
                            </option>
                        </select>
                    </label>

                    <label class="flex items-center gap-2 pb-2 text-sm">
                        <input v-model="formaAgregar.obligatorio" type="checkbox" />
                        <span class="text-slate-700">Obligatorio</span>
                    </label>

                    <button
                        class="rounded-md bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-900"
                        @click="agregar(bateria.id)"
                    >
                        Agregar
                    </button>

                    <button
                        v-if="bateria.estado === 'borrador'"
                        class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700"
                        @click="activar(bateria.id)"
                    >
                        Activar
                    </button>
                </div>

                <p v-if="disponibles.length === 0" class="text-xs text-amber-700">
                    Esta organización no tiene instrumentos habilitados todavía.
                    Habilítalos desde el panel de instrumentos.
                </p>
            </div>
        </article>

        <p v-if="baterias.length === 0" class="rounded-lg border border-slate-200 bg-white p-6 text-center text-sm text-slate-500">
            Todavía no hay baterías.
        </p>
    </div>
</template>
