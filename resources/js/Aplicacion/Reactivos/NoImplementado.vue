<script setup>
/**
 * Tipo de reactivo sin componente todavía.
 *
 * Existe A PROPÓSITO en vez de dejar la pantalla en blanco: un tipo sin
 * renderizador tiene que verse, no fallar en silencio. Una pantalla vacía en
 * mitad de una prueba se lee como un error de la persona que contesta, y el
 * instrumento se abandona sin que nadie sepa por qué.
 *
 * Los que faltan son los de multimedia —matriz visual, captura de dibujo,
 * audio— y los de aplicación presencial, que llegan con el modo examinador
 * completo.
 */
defineProps({
    reactivo: { type: Object, required: true },
});
</script>

<template>
    <div class="rounded-lg border border-dashed border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
        <p class="font-medium">Este tipo de reactivo todavía no se puede contestar aquí.</p>
        <p class="mt-1 text-xs">
            Tipo: <span class="font-mono">{{ reactivo.tipo }}</span>. Avisa a quien te
            asignó la evaluación.
        </p>
    </div>
</template>
