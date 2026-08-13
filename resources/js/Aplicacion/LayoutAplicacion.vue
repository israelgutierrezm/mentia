<script setup>
/**
 * El marco de quien CONTESTA. No es el panel.
 *
 * Deliberadamente sin barra lateral, sin menú y sin ninguna liga que saque de
 * aquí: la persona entró a hacer una cosa y cada elemento que no sea el
 * reactivo compite con él. Quien contesta un tamizaje de ansiedad no necesita
 * un buscador arriba.
 *
 * El tema sale del MODO (Doc 02 §6), en datos: cambia el tamaño de la letra, el
 * ancho de la columna y el contraste, no la estructura. El mismo reactivo se ve
 * distinto para un niño de seis años y para un adulto en selección; lo que no
 * cambia nunca es cuál reactivo es.
 */
import { computed } from 'vue';
import { configuracionDe } from './modos';

const props = defineProps({
    modo: { type: String, default: 'adulto' },
    titulo: { type: String, default: '' },
});

const config = computed(() => configuracionDe(props.modo));

/*
 * El modo infantil sube el contraste y ensancha la columna. En una tableta, a
 * esa edad, la línea corta y la letra grande son la diferencia entre leer el
 * ítem y adivinarlo — y un ítem adivinado contamina el puntaje.
 */
const fondo = computed(() =>
    props.modo === 'infantil' ? 'bg-amber-50' : 'bg-slate-50',
);

const ancho = computed(() =>
    props.modo === 'examinador' ? 'max-w-5xl' : 'max-w-2xl',
);
</script>

<template>
    <div class="min-h-screen" :class="[fondo, config.clases]">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex items-center justify-between px-4 py-3" :class="ancho">
                <span class="text-sm font-semibold tracking-tight text-slate-900">Mentia</span>
                <span v-if="titulo" class="text-xs text-slate-500">{{ titulo }}</span>
            </div>
        </header>

        <main class="mx-auto px-4 py-6" :class="ancho">
            <slot />
        </main>

        <footer class="mx-auto px-4 pb-10 text-center" :class="ancho">
            <p class="text-xs text-slate-500">
                Tus respuestas las revisa un profesional. No se comparten con nadie más
                sin tu consentimiento.
            </p>
        </footer>
    </div>
</template>
