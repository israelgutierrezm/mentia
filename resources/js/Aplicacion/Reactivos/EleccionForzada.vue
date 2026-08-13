<script setup>
/**
 * Elección forzada: de un cuadro de opciones se marca la que MÁS y la que
 * MENOS describe a la persona (Cleaver, Kostick).
 *
 * Las dos marcas van juntas en un componente porque son una sola decisión: no
 * se puede elegir el "más" sin ver contra qué, y marcar sólo uno de los dos
 * deja el cuadro sin puntuar.
 */
import { ref } from 'vue';
import { configuracionDe } from '../modos';

const props = defineProps({
    reactivo: { type: Object, required: true },
    modo: { type: String, default: 'adulto' },
});

const emit = defineEmits(['responder']);

const config = configuracionDe(props.modo);

const mas = ref(null);
const menos = ref(null);

function marcar(opcion, rol) {
    // La misma opción no puede ser la que más y la que menos describe.
    if (rol === 'mas') {
        mas.value = mas.value === opcion.codigo ? null : opcion.codigo;
        if (menos.value === mas.value) menos.value = null;
    } else {
        menos.value = menos.value === opcion.codigo ? null : opcion.codigo;
        if (mas.value === menos.value) mas.value = null;
    }

    // Sólo se emite cuando el cuadro está completo: media respuesta ipsativa
    // no puntúa y guardar la mitad complicaría la idempotencia.
    if (mas.value !== null && menos.value !== null) {
        emit('responder', [
            { opcion_codigo: mas.value, rol_ipsativo: 'mas' },
            { opcion_codigo: menos.value, rol_ipsativo: 'menos' },
        ]);
    }
}
</script>

<template>
    <div class="space-y-2">
        <p class="text-xs text-slate-500">
            Marca la que <strong>más</strong> te describe y la que <strong>menos</strong>.
        </p>

        <div
            v-for="opcion in reactivo.opciones"
            :key="opcion.codigo"
            class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white p-2"
        >
            <button
                type="button"
                class="rounded-md border transition"
                :class="[
                    config.botones,
                    mas === opcion.codigo
                        ? 'border-emerald-600 bg-emerald-50 text-emerald-900'
                        : 'border-slate-300',
                ]"
                @click="marcar(opcion, 'mas')"
            >
                Más
            </button>

            <span class="flex-1" :class="config.enunciado">{{ opcion.texto }}</span>

            <button
                type="button"
                class="rounded-md border transition"
                :class="[
                    config.botones,
                    menos === opcion.codigo
                        ? 'border-rose-600 bg-rose-50 text-rose-900'
                        : 'border-slate-300',
                ]"
                @click="marcar(opcion, 'menos')"
            >
                Menos
            </button>
        </div>
    </div>
</template>
