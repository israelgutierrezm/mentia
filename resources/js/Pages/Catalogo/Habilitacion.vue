<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';

defineProps({
    instrumentos: { type: Array, required: true },
});

const declarando = ref(null);

const forma = useForm({ declaracion: '' });

const etiquetasEstado = {
    disponible: 'Disponible',
    habilitado: 'Habilitado',
    pendiente_contenido: 'Falta capturar contenido',
    bloqueado: 'Bloqueado',
};

const coloresEstado = {
    disponible: 'bg-slate-100 text-slate-600',
    habilitado: 'bg-emerald-50 text-emerald-800',
    pendiente_contenido: 'bg-amber-50 text-amber-800',
    bloqueado: 'bg-rose-50 text-rose-800',
};

function habilitar(versionId) {
    router.post(`/habilitacion/${versionId}/habilitar`, {}, { preserveScroll: true });
}

function declarar(versionId) {
    forma.post(`/habilitacion/${versionId}/declaracion`, {
        preserveScroll: true,
        onSuccess: () => {
            declarando.value = null;
            forma.reset();
        },
    });
}
</script>

<template>
    <Head title="Instrumentos habilitados" />

    <div class="mx-auto max-w-5xl space-y-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Instrumentos de la organización</h1>
            <p class="mt-1 text-sm text-slate-600">
                Los de dominio público se encienden directo. Los que tienen
                copyright exigen que declares tu licencia y captures el
                contenido: la plataforma no distribuye material licenciado.
            </p>
        </header>

        <div class="space-y-3">
            <article
                v-for="fila in instrumentos"
                :key="fila.version_id"
                class="rounded-lg border border-slate-200 bg-white p-4"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-medium text-slate-800">{{ fila.instrumento }}</h2>
                        <p class="text-xs text-slate-500">
                            {{ fila.dominio }} · versión {{ fila.version }}
                        </p>
                        <p v-if="fila.firmante" class="mt-1 text-xs text-slate-500">
                            Licencia declarada por {{ fila.firmante }} el {{ fila.declaracion_firmada_en }}
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-3">
                        <span class="rounded-full px-2 py-1 text-xs" :class="coloresEstado[fila.estado]">
                            {{ etiquetasEstado[fila.estado] }}
                        </span>

                        <button
                            v-if="fila.exige_licencia && fila.estado === 'disponible'"
                            class="text-xs text-blue-700 hover:underline"
                            @click="declarando = declarando === fila.version_id ? null : fila.version_id"
                        >
                            Declarar licencia
                        </button>

                        <button
                            v-else-if="fila.estado !== 'habilitado' && fila.estado !== 'bloqueado'"
                            class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700"
                            @click="habilitar(fila.version_id)"
                        >
                            Habilitar
                        </button>
                    </div>
                </div>

                <div v-if="declarando === fila.version_id" class="mt-4 space-y-3 border-t border-slate-100 pt-4">
                    <p class="text-xs text-slate-600">
                        El texto que escribas queda registrado con tu nombre y la fecha.
                        Es la evidencia de quién asumió la responsabilidad de usar este
                        contenido.
                    </p>
                    <textarea
                        v-model="forma.declaracion"
                        rows="3"
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                        placeholder="Declaro que esta organización cuenta con licencia vigente del editor para aplicar este instrumento…"
                    />
                    <span v-if="forma.errors.declaracion" class="block text-xs text-rose-600">
                        {{ forma.errors.declaracion }}
                    </span>
                    <button
                        class="rounded-md bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-900"
                        :disabled="forma.processing"
                        @click="declarar(fila.version_id)"
                    >
                        Firmar declaración
                    </button>
                </div>
            </article>

            <p v-if="instrumentos.length === 0" class="rounded-lg border border-slate-200 bg-white p-6 text-center text-sm text-slate-500">
                No hay instrumentos publicados en el catálogo todavía.
            </p>
        </div>
    </div>
</template>
