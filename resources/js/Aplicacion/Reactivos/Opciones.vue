<script setup>
/**
 * Opciones en columna. Sirve a likert de 3/4/5/7, dicotómico y opción múltiple.
 *
 * Son un solo componente porque son la misma interacción —elegir una de N— y
 * lo único que cambia es cuántas hay. Un componente por cada uno multiplicaría
 * por cuatro el mismo código y con él los lugares donde arreglar un bug.
 */
import { configuracionDe } from '../modos';

const props = defineProps({
    reactivo: { type: Object, required: true },
    modo: { type: String, default: 'adulto' },
    valor: { type: [String, Number, null], default: null },
});

const emit = defineEmits(['responder']);

const config = configuracionDe(props.modo);

function elegir(opcion) {
    emit('responder', {
        opcion_codigo: opcion.codigo,
        valor_numerico: Number.isNaN(Number(opcion.codigo)) ? null : Number(opcion.codigo),
    });
}
</script>

<template>
    <div class="space-y-2">
        <button
            v-for="opcion in reactivo.opciones"
            :key="opcion.codigo"
            type="button"
            class="w-full rounded-lg border text-left transition"
            :class="[
                config.botones,
                valor === opcion.codigo
                    ? 'border-blue-600 bg-blue-50 font-medium text-blue-900'
                    : 'border-slate-300 bg-white hover:border-slate-400',
            ]"
            @click="elegir(opcion)"
        >
            {{ opcion.texto }}
        </button>
    </div>
</template>
