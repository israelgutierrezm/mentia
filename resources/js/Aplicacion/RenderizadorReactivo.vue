<script setup>
/**
 * Elige el componente según `tipo_reactivo`.
 *
 * El mapa es DATOS, no una cadena de `v-if`: agregar un tipo de reactivo en la
 * Fase 3 es una fila en el catálogo, y agregarlo aquí tiene que ser una entrada
 * más — no tocar la lógica del motor.
 */
import { computed } from 'vue';
import Opciones from './Reactivos/Opciones.vue';
import EleccionForzada from './Reactivos/EleccionForzada.vue';
import Ranking from './Reactivos/Ranking.vue';
import DiferencialSemantico from './Reactivos/DiferencialSemantico.vue';
import TextoAbierto from './Reactivos/TextoAbierto.vue';
import NoImplementado from './Reactivos/NoImplementado.vue';
import { configuracionDe, leerEnVozAlta } from './modos';

const props = defineProps({
    reactivo: { type: Object, required: true },
    modo: { type: String, default: 'adulto' },
    valor: { type: [String, Number, null], default: null },
});

const emit = defineEmits(['responder']);

const componentes = {
    likert_3: Opciones,
    likert_4: Opciones,
    likert_5: Opciones,
    likert_7: Opciones,
    dicotomico: Opciones,
    opcion_multiple_correcta: Opciones,
    observacional_examinador: Opciones,
    entrevista_estructurada: Opciones,
    eleccion_forzada_par: EleccionForzada,
    eleccion_forzada_cuadro: EleccionForzada,
    ranking: Ranking,
    diferencial_semantico: DiferencialSemantico,
    texto_abierto: TextoAbierto,
};

const componente = computed(() => componentes[props.reactivo.tipo] ?? NoImplementado);
const config = computed(() => configuracionDe(props.modo));

function leer() {
    leerEnVozAlta(props.reactivo.enunciado);
}
</script>

<template>
    <article class="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
        <header class="flex items-start gap-3">
            <p class="flex-1 text-slate-900" :class="config.enunciado">
                {{ reactivo.enunciado }}
            </p>

            <!-- TTS del navegador, en el dispositivo: mandar el enunciado de un
                 instrumento con copyright a un servicio externo sería
                 distribuir su contenido. -->
            <button
                v-if="config.tts"
                type="button"
                class="shrink-0 rounded-full bg-slate-100 p-3 text-xl"
                title="Escuchar"
                @click="leer"
            >
                🔊
            </button>
        </header>

        <component
            :is="componente"
            :reactivo="reactivo"
            :modo="modo"
            :valor="valor"
            @responder="emit('responder', $event)"
        />
    </article>
</template>
