<script setup>
import { reactive } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

defineProps({
    vinculos: { type: Object, required: true },
});

const forma = useForm({
    nombres: '',
    primer_apellido: '',
    segundo_apellido: '',
    fecha_nacimiento: '',
    sexo_registral: 'M',
    curp: '',
    matricula_o_num_empleado: '',
});

function guardar() {
    forma.post('/personas', {
        preserveScroll: true,
        onSuccess: () => forma.reset(),
    });
}
</script>

<template>
    <Head title="Personas" />

    <div class="mx-auto max-w-5xl space-y-8">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Personas</h1>
            <p class="mt-1 text-sm text-slate-600">
                Si la CURP ya existe en la plataforma y la fecha de nacimiento
                coincide, la persona se vincula a esta organización en vez de
                crearse de nuevo: su expediente es uno solo.
            </p>
        </header>

        <form class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-3" @submit.prevent="guardar">
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">Nombres</span>
                <input v-model="forma.nombres" required class="rounded-md border border-slate-300 px-3 py-2" />
                <span v-if="forma.errors.nombres" class="text-xs text-rose-600">{{ forma.errors.nombres }}</span>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">Primer apellido</span>
                <input v-model="forma.primer_apellido" required class="rounded-md border border-slate-300 px-3 py-2" />
                <span v-if="forma.errors.primer_apellido" class="text-xs text-rose-600">{{ forma.errors.primer_apellido }}</span>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">Segundo apellido</span>
                <input v-model="forma.segundo_apellido" class="rounded-md border border-slate-300 px-3 py-2" />
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">Fecha de nacimiento</span>
                <input v-model="forma.fecha_nacimiento" type="date" required class="rounded-md border border-slate-300 px-3 py-2" />
                <span v-if="forma.errors.fecha_nacimiento" class="text-xs text-rose-600">{{ forma.errors.fecha_nacimiento }}</span>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">Sexo registral</span>
                <select v-model="forma.sexo_registral" class="rounded-md border border-slate-300 px-3 py-2">
                    <option value="M">M</option>
                    <option value="F">F</option>
                    <option value="X">X</option>
                </select>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">CURP <span class="text-slate-400">(opcional)</span></span>
                <input v-model="forma.curp" maxlength="18" class="rounded-md border border-slate-300 px-3 py-2 uppercase" />
                <span v-if="forma.errors.curp" class="text-xs text-rose-600">{{ forma.errors.curp }}</span>
            </label>

            <div class="sm:col-span-3">
                <button
                    type="submit"
                    :disabled="forma.processing"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                >
                    Dar de alta
                </button>
            </div>
        </form>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-2 font-medium">Nombre</th>
                        <th class="px-4 py-2 font-medium">Nacimiento</th>
                        <th class="px-4 py-2 font-medium">Matrícula</th>
                        <th class="px-4 py-2 font-medium">Origen</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="vinculo in vinculos.data" :key="vinculo.uuid">
                        <td class="px-4 py-2">
                            <Link :href="`/personas/${vinculo.uuid}`" class="text-blue-700 hover:underline">
                                {{ vinculo.nombre_completo }}
                            </Link>
                        </td>
                        <td class="px-4 py-2 text-slate-600">{{ vinculo.fecha_nacimiento }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ vinculo.matricula ?? '—' }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ vinculo.origen_alta }}</td>
                    </tr>
                    <tr v-if="vinculos.data.length === 0">
                        <td colspan="4" class="px-4 py-6 text-center text-slate-500">
                            Todavía no hay personas vinculadas.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
