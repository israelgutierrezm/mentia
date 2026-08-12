<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';

defineProps({
    tutorias: { type: Array, required: true },
});

const forma = useForm({
    tutor_uuid: '',
    menor_uuid: '',
    parentesco: 'madre',
    vigencia_fin: '',
});

const etiquetas = {
    pendiente_validacion: 'Pendiente de validación',
    vigente: 'Vigente',
    revocada: 'Revocada',
    extinta_mayoria_edad: 'Extinta por mayoría de edad',
};

const colores = {
    pendiente_validacion: 'bg-amber-50 text-amber-800',
    vigente: 'bg-emerald-50 text-emerald-800',
    revocada: 'bg-slate-100 text-slate-600',
    extinta_mayoria_edad: 'bg-slate-100 text-slate-600',
};

function registrar() {
    forma.post('/tutorias', { preserveScroll: true, onSuccess: () => forma.reset() });
}

function validar(id) {
    router.post('/tutorias/' + id + '/validar', {}, { preserveScroll: true });
}

function revocar(id) {
    router.post('/tutorias/' + id + '/revocar', {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Tutorías" />

    <div class="mx-auto max-w-5xl space-y-8">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Tutorías</h1>
            <p class="mt-1 text-sm text-slate-600">
                Quién puede responder y consentir en nombre de un menor. Una
                tutoría registrada <strong>no da acceso</strong>: hace falta que
                alguien más la acredite. El parentesco que declara quien se
                registra no prueba nada.
            </p>
        </header>

        <form class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2" @submit.prevent="registrar">
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">UUID del tutor</span>
                <input v-model="forma.tutor_uuid" required class="rounded-md border border-slate-300 px-3 py-2 font-mono text-xs" />
                <span v-if="forma.errors.tutor_uuid" class="text-xs text-rose-600">{{ forma.errors.tutor_uuid }}</span>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">UUID del menor</span>
                <input v-model="forma.menor_uuid" required class="rounded-md border border-slate-300 px-3 py-2 font-mono text-xs" />
                <span v-if="forma.errors.menor_uuid" class="text-xs text-rose-600">{{ forma.errors.menor_uuid }}</span>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">Parentesco</span>
                <select v-model="forma.parentesco" class="rounded-md border border-slate-300 px-3 py-2">
                    <option value="madre">Madre</option>
                    <option value="padre">Padre</option>
                    <option value="tutor_legal">Tutor legal</option>
                    <option value="otro">Otro</option>
                </select>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">Vigente hasta <span class="text-slate-400">(opcional)</span></span>
                <input v-model="forma.vigencia_fin" type="date" class="rounded-md border border-slate-300 px-3 py-2" />
            </label>

            <div class="sm:col-span-2">
                <button
                    type="submit"
                    :disabled="forma.processing"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                >
                    Registrar tutoría
                </button>
            </div>
        </form>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-2 font-medium">Tutor</th>
                        <th class="px-4 py-2 font-medium">Menor</th>
                        <th class="px-4 py-2 font-medium">Parentesco</th>
                        <th class="px-4 py-2 font-medium">Estado</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="tutoria in tutorias" :key="tutoria.id">
                        <td class="px-4 py-2">{{ tutoria.tutor }}</td>
                        <td class="px-4 py-2">{{ tutoria.menor }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ tutoria.parentesco }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-1 text-xs" :class="colores[tutoria.estado]">
                                {{ etiquetas[tutoria.estado] }}
                            </span>
                        </td>
                        <td class="space-x-3 px-4 py-2 text-right">
                            <button
                                v-if="tutoria.estado === 'pendiente_validacion'"
                                class="text-xs text-emerald-700 hover:underline"
                                @click="validar(tutoria.id)"
                            >
                                Acreditar
                            </button>
                            <button
                                v-if="tutoria.estado === 'vigente'"
                                class="text-xs text-rose-700 hover:underline"
                                @click="revocar(tutoria.id)"
                            >
                                Revocar
                            </button>
                        </td>
                    </tr>
                    <tr v-if="tutorias.length === 0">
                        <td colspan="5" class="px-4 py-6 text-center text-slate-500">
                            Todavía no hay tutorías registradas.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
