<script setup>
/**
 * Alta del segundo factor.
 *
 * Los códigos de recuperación se muestran UNA VEZ. Guardarlos en claro para
 * poder volver a enseñarlos anularía el punto de cifrarlos.
 */
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

defineProps({
    activo: { type: Boolean, required: true },
    obligatorio: { type: Boolean, required: true },
    secreto: { type: String, default: null },
    url_alta: { type: String, default: null },
});

const pagina = usePage();
const codigos = computed(() => pagina.props.codigos_recuperacion ?? null);

const formulario = useForm({ codigo: '' });

function confirmar() {
    formulario.post('/seguridad/dos-factores', {
        onFinish: () => formulario.reset('codigo'),
    });
}
</script>

<template>
    <Head title="Verificación en dos pasos" />

    <div class="mx-auto max-w-lg space-y-6 p-6">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight text-slate-900">
                Verificación en dos pasos
            </h1>
            <p v-if="obligatorio" class="text-sm text-rose-700">
                Tu rol accede a información sensible. Activarla es obligatorio para
                poder entrar.
            </p>
        </header>

        <!-- Los códigos de recuperación, una sola vez. -->
        <section
            v-if="codigos"
            class="space-y-2 rounded-xl border border-amber-300 bg-amber-50 p-5"
        >
            <p class="text-sm font-medium text-amber-900">
                Guarda estos códigos de recuperación ahora.
            </p>
            <p class="text-xs text-amber-800">
                Son de un solo uso y no se pueden volver a mostrar. Sin ellos, perder
                el teléfono significa perder el acceso.
            </p>
            <ul class="grid grid-cols-2 gap-1 pt-2 font-mono text-sm text-amber-900">
                <li v-for="codigo in codigos" :key="codigo">{{ codigo }}</li>
            </ul>
        </section>

        <section v-if="activo" class="rounded-xl border border-emerald-200 bg-emerald-50 p-5">
            <p class="text-sm text-emerald-900">
                La verificación en dos pasos ya está activa en tu cuenta.
            </p>
        </section>

        <section v-else class="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
            <div class="space-y-2">
                <p class="text-sm text-slate-700">
                    Captura este código en tu aplicación de autenticación
                    (Google Authenticator, 1Password, Authy):
                </p>
                <p class="rounded-lg bg-slate-100 px-4 py-3 text-center font-mono text-lg tracking-widest">
                    {{ secreto }}
                </p>
                <p class="break-all text-xs text-slate-500">{{ url_alta }}</p>
            </div>

            <form class="space-y-3" @submit.prevent="confirmar">
                <label class="block space-y-1">
                    <span class="text-sm font-medium text-slate-700">
                        Escribe el código de seis dígitos que te muestra
                    </span>
                    <input
                        v-model="formulario.codigo"
                        type="text"
                        inputmode="numeric"
                        maxlength="6"
                        autocomplete="one-time-code"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-center font-mono text-lg tracking-widest"
                    >
                </label>

                <p v-if="formulario.errors.codigo" class="text-sm text-rose-700">
                    {{ formulario.errors.codigo }}
                </p>

                <button
                    type="submit"
                    :disabled="formulario.processing"
                    class="w-full rounded-md bg-blue-600 px-4 py-2.5 font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                >
                    Activar
                </button>
            </form>
        </section>
    </div>
</template>
