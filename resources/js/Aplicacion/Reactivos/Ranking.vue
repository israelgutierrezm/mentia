<script setup>
/**
 * Ordenamiento por rango (Zavic, Allport): se ordenan las opciones de mayor a
 * menor importancia.
 *
 * Se resuelve con clic en secuencia y no con arrastre: en un teléfono el
 * arrastre dentro de una lista larga pelea con el desplazamiento de la
 * pantalla, y quien contesta un instrumento no debería tener que pelearse con
 * la interfaz. El arrastre se puede sumar después; esto funciona en todo.
 */
import { computed, ref } from 'vue';
import { configuracionDe } from '../modos';

const props = defineProps({
    reactivo: { type: Object, required: true },
    modo: { type: String, default: 'adulto' },
});

const emit = defineEmits(['responder']);

const config = configuracionDe(props.modo);

const orden = ref([]);

const pendientes = computed(() =>
    props.reactivo.opciones.filter((opcion) => !orden.value.includes(opcion.codigo)),
);

function elegir(opcion) {
    orden.value.push(opcion.codigo);

    if (orden.value.length === props.reactivo.opciones.length) {
        emit(
            'responder',
            orden.value.map((codigo, indice) => ({
                opcion_codigo: codigo,
                posicion_ranking: indice + 1,
            })),
        );
    }
}

function reiniciar() {
    orden.value = [];
}
</script>

<template>
    <div class="space-y-3">
        <p class="text-xs text-slate-500">
            Elige en orden, de lo más importante para ti a lo menos importante.
        </p>

        <ol v-if="orden.length" class="space-y-1 text-sm">
            <li
                v-for="(codigo, indice) in orden"
                :key="codigo"
                class="flex gap-2 rounded-md bg-slate-100 px-3 py-2"
            >
                <span class="font-medium text-slate-500">{{ indice + 1 }}.</span>
                <span>{{ reactivo.opciones.find((o) => o.codigo === codigo)?.texto }}</span>
            </li>
        </ol>

        <div class="space-y-2">
            <button
                v-for="opcion in pendientes"
                :key="opcion.codigo"
                type="button"
                class="w-full rounded-lg border border-slate-300 bg-white text-left hover:border-slate-400"
                :class="config.botones"
                @click="elegir(opcion)"
            >
                {{ opcion.texto }}
            </button>
        </div>

        <button
            v-if="orden.length"
            type="button"
            class="text-xs text-slate-600 hover:underline"
            @click="reiniciar"
        >
            Empezar de nuevo
        </button>
    </div>
</template>
