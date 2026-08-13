<script setup>
/**
 * Diferencial semántico: una escala entre dos polos opuestos.
 *
 * Los polos salen de la primera y la última opción; los puntos intermedios son
 * las de en medio. Se dibuja en fila para que la distancia entre polos se vea,
 * que es de lo que trata el instrumento.
 */
import { computed } from 'vue';

const props = defineProps({
    reactivo: { type: Object, required: true },
    modo: { type: String, default: 'adulto' },
    valor: { type: [String, Number, null], default: null },
});

const emit = defineEmits(['responder']);

const polos = computed(() => ({
    izquierda: props.reactivo.opciones[0]?.texto ?? '',
    derecha: props.reactivo.opciones[props.reactivo.opciones.length - 1]?.texto ?? '',
}));

function elegir(opcion) {
    emit('responder', {
        opcion_codigo: opcion.codigo,
        valor_numerico: Number.isNaN(Number(opcion.codigo)) ? null : Number(opcion.codigo),
    });
}
</script>

<template>
    <div class="space-y-2">
        <div class="flex items-center gap-3">
            <span class="w-28 shrink-0 text-right text-sm text-slate-600">
                {{ polos.izquierda }}
            </span>

            <div class="flex flex-1 justify-between gap-1">
                <button
                    v-for="opcion in reactivo.opciones"
                    :key="opcion.codigo"
                    type="button"
                    class="h-9 w-9 rounded-full border transition"
                    :class="valor === opcion.codigo
                        ? 'border-blue-600 bg-blue-600'
                        : 'border-slate-300 bg-white hover:border-slate-400'"
                    :title="opcion.texto"
                    @click="elegir(opcion)"
                />
            </div>

            <span class="w-28 shrink-0 text-sm text-slate-600">{{ polos.derecha }}</span>
        </div>
    </div>
</template>
