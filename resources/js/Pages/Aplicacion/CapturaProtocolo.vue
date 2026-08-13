<script setup>
/**
 * Captura del protocolo de papel.
 *
 * El examinador aplicó el WISC en su cuadernillo y aquí registra los puntajes.
 * Lo que se captura son PUNTAJES, no respuestas: la editorial prohíbe
 * administrar estos instrumentos en línea, así que el sistema existe para
 * guardar su resultado y meterlo al pipeline desde la normalización, no para
 * aplicarlos (Doc 01 §6).
 *
 * El selector sólo ofrece instrumentos de sólo captura. Los demás se aplican
 * con el motor, y capturarlos a mano saltaría cronómetros, tiempos por reactivo
 * e índices de validez para producir un resultado que se ve igual y no lo es.
 */
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    instrumentos: { type: Array, required: true },
});

const formulario = useForm({
    persona_uuid: '',
    version_instrumento_id: null,
    fecha_aplicacion: new Date().toISOString().slice(0, 10),
    escalas: [],
    observaciones: '',
});

const elegido = computed(
    () => props.instrumentos.find(
        (instrumento) => instrumento.version_instrumento_id === formulario.version_instrumento_id,
    ) ?? null,
);

/*
 * Los renglones se arman con las escalas del instrumento, no a mano. Capturar
 * un WISC son quince renglones con nombre propio; pedirle al examinador que
 * escriba la clave de cada uno es pedirle que se equivoque, y una clave mal
 * escrita normaliza contra el baremo de otra escala.
 */
watch(elegido, (instrumento) => {
    formulario.escalas = instrumento
        ? instrumento.escalas.map((escala) => ({
            clave: escala.clave,
            nombre: escala.nombre,
            puntaje_bruto: '',
            puntaje_escalar: '',
        }))
        : [];
});

const completo = computed(
    () => formulario.persona_uuid !== ''
        && formulario.escalas.length > 0
        && formulario.escalas.every((escala) => escala.puntaje_bruto !== ''),
);

function guardar() {
    formulario
        .transform((datos) => ({
            ...datos,
            escalas: datos.escalas.map((escala) => ({
                clave: escala.clave,
                puntaje_bruto: Number(escala.puntaje_bruto),
                puntaje_escalar: escala.puntaje_escalar === ''
                    ? null
                    : Number(escala.puntaje_escalar),
            })),
        }))
        .post('/captura-protocolo', {
            preserveScroll: true,
            onSuccess: () => {
                formulario.reset('persona_uuid', 'observaciones');
                formulario.escalas.forEach((escala) => {
                    escala.puntaje_bruto = '';
                    escala.puntaje_escalar = '';
                });
            },
        });
}
</script>

<template>
    <Head title="Captura de protocolo" />

    <div class="mx-auto max-w-3xl space-y-6 p-6">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight text-slate-900">
                Captura de protocolo
            </h1>
            <p class="text-sm text-slate-600">
                Registra los puntajes de un instrumento aplicado en papel. Sólo
                aparecen los que la editorial no permite administrar en línea.
            </p>
        </header>

        <p
            v-if="$page.props.avisos?.exito"
            class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
        >
            {{ $page.props.avisos.exito }}
        </p>

        <p
            v-if="instrumentos.length === 0"
            class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            No hay instrumentos de sólo captura habilitados en esta organización.
            Habilítalos en el catálogo antes de capturar protocolos.
        </p>

        <form v-else class="space-y-6" @submit.prevent="guardar">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block space-y-1">
                    <span class="text-sm font-medium text-slate-700">Instrumento</span>
                    <select
                        v-model="formulario.version_instrumento_id"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option :value="null">Elige uno…</option>
                        <option
                            v-for="instrumento in instrumentos"
                            :key="instrumento.version_instrumento_id"
                            :value="instrumento.version_instrumento_id"
                        >
                            {{ instrumento.nombre }} · v{{ instrumento.version }}
                        </option>
                    </select>
                </label>

                <label class="block space-y-1">
                    <span class="text-sm font-medium text-slate-700">
                        Fecha en que se aplicó
                    </span>
                    <input
                        v-model="formulario.fecha_aplicacion"
                        type="date"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    >
                    <!--
                        La edad se congela con ESTA fecha, no con la de hoy:
                        capturar un WISC de hace tres meses tiene que normalizar
                        con la edad que la persona tenía ese día.
                    -->
                    <span class="text-xs text-slate-500">
                        Con esta fecha se calcula la edad para normalizar.
                    </span>
                </label>
            </div>

            <label class="block space-y-1">
                <span class="text-sm font-medium text-slate-700">Persona (UUID)</span>
                <input
                    v-model.trim="formulario.persona_uuid"
                    type="text"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm"
                    placeholder="Cópialo de la ficha de la persona"
                >
                <span v-if="formulario.errors.persona_uuid" class="text-xs text-rose-700">
                    {{ formulario.errors.persona_uuid }}
                </span>
            </label>

            <section v-if="formulario.escalas.length > 0" class="space-y-3">
                <h2 class="text-sm font-medium text-slate-700">Puntajes por escala</h2>

                <div class="overflow-x-auto rounded-lg border border-slate-200">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-3 py-2 font-medium">Escala</th>
                                <th class="w-32 px-3 py-2 font-medium">Bruto</th>
                                <th class="w-32 px-3 py-2 font-medium">Escalar</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="escala in formulario.escalas" :key="escala.clave">
                                <td class="px-3 py-2">
                                    {{ escala.nombre }}
                                    <span class="ml-1 font-mono text-xs text-slate-400">
                                        {{ escala.clave }}
                                    </span>
                                </td>
                                <td class="px-3 py-2">
                                    <input
                                        v-model="escala.puntaje_bruto"
                                        type="number"
                                        step="any"
                                        class="w-full rounded border border-slate-300 px-2 py-1"
                                    >
                                </td>
                                <td class="px-3 py-2">
                                    <!--
                                        Opcional: hay protocolos donde el
                                        escalar lo da el manual y otros donde lo
                                        calcula el pipeline. Exigirlo obligaría
                                        al examinador a inventarlo.
                                    -->
                                    <input
                                        v-model="escala.puntaje_escalar"
                                        type="number"
                                        step="any"
                                        class="w-full rounded border border-slate-300 px-2 py-1"
                                        placeholder="opcional"
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-if="formulario.errors.escalas" class="text-sm text-rose-700">
                    {{ formulario.errors.escalas }}
                </p>
            </section>

            <label class="block space-y-1">
                <span class="text-sm font-medium text-slate-700">
                    Observaciones de la aplicación
                </span>
                <textarea
                    v-model="formulario.observaciones"
                    rows="3"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    placeholder="Conducta durante la prueba, incidencias, condiciones de aplicación"
                />
            </label>

            <div class="flex justify-end">
                <button
                    type="submit"
                    :disabled="!completo || formulario.processing"
                    class="rounded-md bg-blue-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    Guardar protocolo
                </button>
            </div>
        </form>
    </div>
</template>
