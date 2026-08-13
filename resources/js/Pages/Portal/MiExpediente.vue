<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';

defineProps({
    secciones: { type: Array, required: true },
    consentimientos: { type: Array, required: true },
    textos_disponibles: { type: Array, required: true },
});

const textoAbierto = ref(null);

const formaValor = useForm({ campo_id: null, valor: '' });
const formaConsentimiento = useForm({ texto_consentimiento_id: null });

function guardar(campo) {
    formaValor.campo_id = campo.id;
    formaValor.post('/mi-expediente/valores', { preserveScroll: true });
}

function otorgar(texto) {
    formaConsentimiento.texto_consentimiento_id = texto.id;
    formaConsentimiento.post('/consentimientos', {
        preserveScroll: true,
        onSuccess: () => { textoAbierto.value = null; },
    });
}

function revocar(id) {
    router.post('/consentimientos/' + id + '/revocar', {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Mi expediente" />

    <div class="mx-auto max-w-3xl space-y-10">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Mi expediente</h1>
            <p class="mt-1 text-sm text-slate-600">
                Tus datos son tuyos. Aquí los capturas, los corriges y decides
                quién puede tratarlos.
            </p>
        </header>

        <!-- Consentimientos primero: sin ellos, nadie puede tratar nada. -->
        <section class="space-y-4">
            <h2 class="text-lg font-medium">Consentimientos</h2>

            <div
                v-for="consentimiento in consentimientos"
                :key="consentimiento.id"
                class="flex items-center justify-between rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm"
            >
                <div>
                    <p class="font-medium text-slate-800">{{ consentimiento.titulo }}</p>
                    <p class="text-xs text-slate-500">
                        Otorgado el {{ consentimiento.otorgado_en }}
                        <span v-if="consentimiento.relacion === 'tutor'"> · por tu tutor</span>
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <span
                        class="rounded-full px-2 py-1 text-xs"
                        :class="consentimiento.vigente ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600'"
                    >
                        {{ consentimiento.estado }}
                    </span>
                    <button
                        v-if="consentimiento.vigente"
                        class="text-xs text-rose-700 hover:underline"
                        @click="revocar(consentimiento.id)"
                    >
                        Revocar
                    </button>
                </div>
            </div>

            <div class="rounded-lg border border-dashed border-slate-300 p-4">
                <p class="text-sm font-medium text-slate-700">Otorgar un consentimiento</p>

                <div v-for="texto in textos_disponibles" :key="texto.id" class="mt-3">
                    <button
                        class="text-sm text-blue-700 hover:underline"
                        @click="textoAbierto = textoAbierto === texto.id ? null : texto.id"
                    >
                        {{ texto.titulo }}
                    </button>

                    <div v-if="textoAbierto === texto.id" class="mt-2 space-y-3">
                        <!-- El texto COMPLETO antes de firmar. Un consentimiento
                             que se acepta sin poder leerlo no es consentimiento. -->
                        <p class="whitespace-pre-line rounded-md bg-slate-50 p-4 text-sm text-slate-700">
                            {{ texto.cuerpo }}
                        </p>
                        <button
                            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                            @click="otorgar(texto)"
                        >
                            He leído y acepto
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Autollenado -->
        <section v-for="seccion in secciones" :key="seccion.clave" class="space-y-3">
            <h2 class="text-lg font-medium">{{ seccion.nombre }}</h2>

            <div
                v-for="campo in seccion.campos"
                :key="campo.id"
                class="rounded-lg border border-slate-200 bg-white p-4"
            >
                <label class="block text-sm">
                    <span class="text-slate-700">
                        {{ campo.etiqueta }}
                        <span v-if="campo.obligatorio" class="text-rose-600">*</span>
                    </span>

                    <select
                        v-if="campo.tipo_dato === 'catalogo'"
                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
                        @change="formaValor.valor = $event.target.value"
                    >
                        <option value="">— Elige —</option>
                        <option v-for="opcion in campo.opciones" :key="opcion.id" :value="opcion.id">
                            {{ opcion.etiqueta }}
                        </option>
                    </select>

                    <input
                        v-else
                        :type="campo.tipo_dato === 'fecha' ? 'date' : (campo.tipo_dato === 'numero' ? 'number' : 'text')"
                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
                        @input="formaValor.valor = $event.target.value"
                    />
                </label>

                <div class="mt-2 flex items-center justify-between text-xs">
                    <span class="text-slate-500">
                        <template v-if="campo.valor !== null && campo.valor !== undefined">
                            Actual: {{ campo.valor }}
                        </template>
                        <template v-else-if="campo.pendiente">
                            Enviado y en revisión: {{ campo.pendiente }}
                        </template>
                        <template v-else>Sin capturar</template>
                    </span>

                    <button
                        class="rounded-md bg-slate-800 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-900"
                        @click="guardar(campo)"
                    >
                        Guardar
                    </button>
                </div>
            </div>
        </section>
    </div>
</template>
