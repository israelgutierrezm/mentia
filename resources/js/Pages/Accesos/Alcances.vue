<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';

defineProps({
    alcances: { type: Object, required: true },
    roles: { type: Array, required: true },
    unidades: { type: Array, required: true },
    agrupaciones: { type: Array, required: true },
});

const forma = useForm({
    persona_uuid: '',
    rol_id: null,
    alcance_tipo: 'organizacion',
    alcance_id: null,
    vigencia_fin: '',
});

function asignar() {
    forma.post('/alcances', {
        preserveScroll: true,
        onSuccess: () => forma.reset(),
    });
}

function retirar(id) {
    router.delete('/alcances/' + id, { preserveScroll: true });
}
</script>

<template>
    <Head title="Roles y alcances" />

    <div class="mx-auto max-w-5xl space-y-8">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Roles y alcances</h1>
            <p class="mt-1 text-sm text-slate-600">
                El rol dice qué puede hacer alguien; el alcance dice sobre quién.
                Un alcance sobre una unidad incluye a las que dependen de ella.
            </p>
        </header>

        <form
            class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-3"
            @submit.prevent="asignar"
        >
            <label class="flex flex-col gap-1 text-sm sm:col-span-2">
                <span class="text-slate-700">UUID de la persona</span>
                <input
                    v-model="forma.persona_uuid"
                    required
                    class="rounded-md border border-slate-300 px-3 py-2 font-mono text-xs"
                />
                <span v-if="forma.errors.persona_uuid" class="text-xs text-rose-600">
                    {{ forma.errors.persona_uuid }}
                </span>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">Rol</span>
                <select v-model="forma.rol_id" required class="rounded-md border border-slate-300 px-3 py-2">
                    <option :value="null" disabled>Elige un rol</option>
                    <option v-for="rol in roles" :key="rol.id" :value="rol.id">{{ rol.name }}</option>
                </select>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">Alcance</span>
                <select v-model="forma.alcance_tipo" class="rounded-md border border-slate-300 px-3 py-2">
                    <option value="organizacion">Toda la organización</option>
                    <option value="unidad">Una unidad (y sus dependientes)</option>
                    <option value="agrupacion">Una agrupación</option>
                </select>
            </label>

            <label v-if="forma.alcance_tipo === 'unidad'" class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">Unidad</span>
                <select v-model="forma.alcance_id" class="rounded-md border border-slate-300 px-3 py-2">
                    <option v-for="unidad in unidades" :key="unidad.id" :value="unidad.id">
                        {{ unidad.nombre }}
                    </option>
                </select>
            </label>

            <label v-if="forma.alcance_tipo === 'agrupacion'" class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">Agrupación</span>
                <select v-model="forma.alcance_id" class="rounded-md border border-slate-300 px-3 py-2">
                    <option v-for="agrupacion in agrupaciones" :key="agrupacion.id" :value="agrupacion.id">
                        {{ agrupacion.nombre }}
                    </option>
                </select>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="text-slate-700">
                    Vigente hasta <span class="text-slate-400">(opcional)</span>
                </span>
                <input v-model="forma.vigencia_fin" type="date" class="rounded-md border border-slate-300 px-3 py-2" />
            </label>

            <div class="sm:col-span-3">
                <button
                    type="submit"
                    :disabled="forma.processing"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                >
                    Asignar
                </button>
            </div>
        </form>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-2 font-medium">Persona</th>
                        <th class="px-4 py-2 font-medium">Rol</th>
                        <th class="px-4 py-2 font-medium">Alcance</th>
                        <th class="px-4 py-2 font-medium">Vigencia</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr
                        v-for="alcance in alcances.data"
                        :key="alcance.id"
                        :class="{ 'opacity-50': !alcance.vigente }"
                    >
                        <td class="px-4 py-2">{{ alcance.persona }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ alcance.rol }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ alcance.alcance_tipo }}</td>
                        <td class="px-4 py-2 text-slate-600">
                            {{ alcance.vigencia_fin ?? 'Sin caducidad' }}
                            <span v-if="!alcance.vigente" class="ml-1 text-xs text-rose-600">(vencido)</span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <button class="text-xs text-rose-700 hover:underline" @click="retirar(alcance.id)">
                                Retirar
                            </button>
                        </td>
                    </tr>
                    <tr v-if="alcances.data.length === 0">
                        <td colspan="5" class="px-4 py-6 text-center text-slate-500">
                            Todavía no hay alcances asignados.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
