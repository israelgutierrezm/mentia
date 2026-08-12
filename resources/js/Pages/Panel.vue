<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    fase: { type: Number, required: true },
});
</script>

<template>
    <Head title="Panel" />

    <div class="mx-auto max-w-3xl">
        <h1 class="text-2xl font-semibold tracking-tight">Panel</h1>

        <p class="mt-2 text-sm text-slate-600">
            Fundación del proyecto lista (fase {{ fase }}). El panel real se
            arma por tarjetas declaradas con su permiso a partir de la fase 1.
        </p>
    </div>
</template>
