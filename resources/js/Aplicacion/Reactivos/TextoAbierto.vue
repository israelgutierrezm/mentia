<script setup>
/**
 * Respuesta abierta.
 *
 * Emite al perder el foco, no en cada tecla: mandar un lote por pulsación
 * llenaría la tabla crítica de basura y haría inútil el tiempo por reactivo.
 */
import { ref } from 'vue';

const props = defineProps({
    reactivo: { type: Object, required: true },
    modo: { type: String, default: 'adulto' },
    valor: { type: [String, null], default: null },
});

const emit = defineEmits(['responder']);

const texto = ref(props.valor ?? '');

function guardar() {
    if (texto.value.trim() === '') {
        return;
    }

    emit('responder', { valor_texto: texto.value.trim() });
}
</script>

<template>
    <textarea
        v-model="texto"
        rows="4"
        class="w-full rounded-lg border border-slate-300 px-3 py-2"
        placeholder="Escribe tu respuesta"
        @blur="guardar"
    />
</template>
