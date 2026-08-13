<script setup>
/**
 * Entrar.
 *
 * No hay liga de registro: las personas las da de alta la organización. Alguien
 * que se registra solo no está vinculado a ningún tenant, no tiene rol y no
 * puede ver nada — pero sí habría creado una cuenta con un correo que quizá no
 * es suyo, en un sistema que guarda expedientes clínicos.
 */
import { Head, useForm } from '@inertiajs/vue3';

defineOptions({ layout: null });

const formulario = useForm({
    email: '',
    password: '',
    recordarme: false,
});

function entrar() {
    formulario.post('/entrar', {
        onFinish: () => formulario.reset('password'),
    });
}
</script>

<template>
    <Head title="Entrar" />

    <div class="flex min-h-screen items-center justify-center bg-slate-50 p-4">
        <div class="w-full max-w-sm space-y-6">
            <header class="space-y-1 text-center">
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Mentia</h1>
                <p class="text-sm text-slate-600">Entra con tu cuenta de la organización.</p>
            </header>

            <form
                class="space-y-4 rounded-xl border border-slate-200 bg-white p-6"
                @submit.prevent="entrar"
            >
                <label class="block space-y-1">
                    <span class="text-sm font-medium text-slate-700">Correo</span>
                    <input
                        v-model="formulario.email"
                        type="email"
                        autocomplete="username"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    >
                </label>

                <label class="block space-y-1">
                    <span class="text-sm font-medium text-slate-700">Contraseña</span>
                    <input
                        v-model="formulario.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    >
                </label>

                <!--
                    Un solo mensaje de error para correo inexistente y
                    contraseña equivocada: distinguirlos convertiría el
                    formulario en un verificador de qué correos tienen cuenta
                    aquí, y tener cuenta aquí ya dice algo de una persona.
                -->
                <p v-if="formulario.errors.email" class="text-sm text-rose-700">
                    {{ formulario.errors.email }}
                </p>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input v-model="formulario.recordarme" type="checkbox" class="rounded">
                    Mantener la sesión abierta
                </label>

                <button
                    type="submit"
                    :disabled="formulario.processing"
                    class="w-full rounded-md bg-blue-600 px-4 py-2.5 font-medium text-white transition hover:bg-blue-700 disabled:opacity-50"
                >
                    Entrar
                </button>
            </form>

            <p class="text-center text-xs text-slate-500">
                ¿Vienes a contestar una evaluación? No necesitas cuenta:
                <a href="/contestar" class="underline">usa tu liga</a>.
            </p>
        </div>
    </div>
</template>
