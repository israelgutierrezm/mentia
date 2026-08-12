<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    roles: { type: Array, required: true },
    catalogo: { type: Object, required: true },
});

const editando = ref(null);

const forma = useForm({
    nombre: '',
    permisos: [],
    nivel_sensibilidad_max: 1,
});

const niveles = [
    { valor: 1, etiqueta: '1 · General' },
    { valor: 2, etiqueta: '2 · Laboral' },
    { valor: 3, etiqueta: '3 · Psicológico' },
    { valor: 4, etiqueta: '4 · Clínico' },
];

const dominios = computed(() => Object.keys(props.catalogo));

function editar(rol) {
    editando.value = rol.id;
    forma.nombre = rol.nombre;
    forma.permisos = [...rol.permisos];
    forma.nivel_sensibilidad_max = rol.nivel_sensibilidad_max;
}

function cancelar() {
    editando.value = null;
    forma.reset();
    forma.clearErrors();
}

function guardar() {
    if (editando.value) {
        forma.put('/roles/' + editando.value, {
            preserveScroll: true,
            onSuccess: cancelar,
        });
        return;
    }

    forma.post('/roles', { preserveScroll: true, onSuccess: cancelar });
}

function eliminar(rol) {
    router.delete('/roles/' + rol.id, { preserveScroll: true });
}
</script>

<template>
    <Head title="Roles" />

    <div class="mx-auto max-w-5xl space-y-8">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Roles</h1>
            <p class="mt-1 text-sm text-slate-600">
                Cada organización arma los suyos. Los permisos no se inventan
                aquí: son llaves que el sistema consulta. El tope de
                sensibilidad decide hasta qué nivel de contenido alcanza el rol,
                aunque tenga el permiso.
            </p>
        </header>

        <form class="space-y-5 rounded-lg border border-slate-200 bg-white p-5" @submit.prevent="guardar">
            <div class="flex flex-wrap items-end gap-4">
                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-slate-700">Nombre del rol</span>
                    <input v-model="forma.nombre" required class="w-64 rounded-md border border-slate-300 px-3 py-2" />
                    <span v-if="forma.errors.nombre" class="text-xs text-rose-600">{{ forma.errors.nombre }}</span>
                </label>

                <label class="flex flex-col gap-1 text-sm">
                    <span class="text-slate-700">Nivel máximo de sensibilidad</span>
                    <select v-model.number="forma.nivel_sensibilidad_max" class="rounded-md border border-slate-300 px-3 py-2">
                        <option v-for="nivel in niveles" :key="nivel.valor" :value="nivel.valor">
                            {{ nivel.etiqueta }}
                        </option>
                    </select>
                </label>
            </div>

            <div class="space-y-4">
                <div v-for="dominio in dominios" :key="dominio">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        {{ dominio }}
                    </h3>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <label
                            v-for="permiso in catalogo[dominio]"
                            :key="permiso.clave"
                            class="flex items-start gap-2 rounded-md border border-slate-200 p-2 text-sm"
                        >
                            <input v-model="forma.permisos" type="checkbox" :value="permiso.clave" class="mt-1" />
                            <span>
                                <span class="font-medium text-slate-800">{{ permiso.etiqueta }}</span>
                                <span class="block text-xs text-slate-500">{{ permiso.descripcion }}</span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <span v-if="forma.errors.permisos" class="block text-xs text-rose-600">
                {{ forma.errors.permisos }}
            </span>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    :disabled="forma.processing"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                >
                    {{ editando ? 'Guardar cambios' : 'Crear rol' }}
                </button>
                <button v-if="editando" type="button" class="text-sm text-slate-600 hover:underline" @click="cancelar">
                    Cancelar
                </button>
            </div>
        </form>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-2 font-medium">Rol</th>
                        <th class="px-4 py-2 font-medium">Permisos</th>
                        <th class="px-4 py-2 font-medium">Sensibilidad</th>
                        <th class="px-4 py-2 font-medium">Personas</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="rol in roles" :key="rol.id">
                        <td class="px-4 py-2 font-medium text-slate-800">{{ rol.nombre }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ rol.permisos.length }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ rol.nivel_sensibilidad_max }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ rol.personas }}</td>
                        <td class="space-x-3 px-4 py-2 text-right">
                            <button class="text-xs text-blue-700 hover:underline" @click="editar(rol)">Editar</button>
                            <button
                                v-if="rol.personas === 0"
                                class="text-xs text-rose-700 hover:underline"
                                @click="eliminar(rol)"
                            >
                                Eliminar
                            </button>
                        </td>
                    </tr>
                    <tr v-if="roles.length === 0">
                        <td colspan="5" class="px-4 py-6 text-center text-slate-500">
                            Esta organización no tiene roles.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
