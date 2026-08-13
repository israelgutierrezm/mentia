<script setup>
/**
 * El centro de alertas.
 *
 * Las críticas arriba y, dentro de cada nivel, las MÁS VIEJAS primero: una
 * alerta crítica de hace tres días es peor noticia que una de hace diez
 * minutos, y una bandeja ordenada por fecha descendente esconde justamente la
 * que se está pudriendo.
 *
 * Cerrar una alerta EXIGE escribir qué se hizo. No es formalismo: una alerta
 * que se cierra con un clic no deja constancia de si alguien habló con la
 * persona o si sólo se quitó el punto rojo de la pantalla.
 */
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    alertas: { type: Array, required: true },
    conteos: { type: Object, required: true },
    estado: { type: String, required: true },
});

const abierta = ref(null);

const formulario = useForm({ resolucion: '' });

const colores = {
    critica: 'border-rose-300 bg-rose-50 text-rose-900',
    alta: 'border-amber-300 bg-amber-50 text-amber-900',
    media: 'border-slate-300 bg-slate-50 text-slate-700',
};

const etiquetasTipo = {
    centinela: 'Reactivo centinela',
    bandera_resultado: 'Bandera de resultado',
    protocolo: 'Protocolo automático',
    validez: 'Validez del protocolo',
};

const hayCriticas = computed(() => props.conteos.criticas_abiertas > 0);

function filtrar(estado) {
    router.get('/alertas', { estado }, { preserveState: false });
}

function abrir(alerta) {
    abierta.value = abierta.value === alerta.id ? null : alerta.id;
    formulario.reset();
    formulario.clearErrors();
}

function cerrar(alerta) {
    formulario.post(`/alertas/${alerta.id}/atender`, {
        preserveScroll: true,
        onSuccess: () => {
            abierta.value = null;
            formulario.reset();
        },
    });
}

function cuandoFue(iso) {
    const fecha = new Date(iso);
    const horas = Math.floor((Date.now() - fecha.getTime()) / 3600000);

    if (horas < 1) {
        return 'hace menos de una hora';
    }

    if (horas < 24) {
        return `hace ${horas} h`;
    }

    return `hace ${Math.floor(horas / 24)} días`;
}
</script>

<template>
    <Head title="Centro de alertas" />

    <div class="mx-auto max-w-4xl space-y-6 p-6">
        <header class="space-y-2">
            <h1 class="text-xl font-semibold tracking-tight text-slate-900">
                Centro de alertas
            </h1>

            <!--
                El conteo de críticas abiertas va arriba y en rojo. Es el número
                que tiene que doler mirar.
            -->
            <p v-if="hayCriticas" class="rounded-lg bg-rose-100 px-4 py-3 text-sm font-medium text-rose-900">
                {{ conteos.criticas_abiertas }}
                {{ conteos.criticas_abiertas === 1 ? 'alerta crítica abierta' : 'alertas críticas abiertas' }}.
                Atiéndelas conforme al protocolo de tu organización.
            </p>
            <p v-else class="text-sm text-slate-600">
                No hay alertas críticas abiertas. {{ conteos.abiertas }} en total sin cerrar.
            </p>
        </header>

        <p
            v-if="$page.props.avisos?.exito"
            class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
        >
            {{ $page.props.avisos.exito }}
        </p>

        <nav class="flex gap-2 text-sm">
            <button
                v-for="filtro in ['abiertas', 'cerrada', 'todas']"
                :key="filtro"
                type="button"
                class="rounded-md px-3 py-1.5 capitalize"
                :class="estado === filtro
                    ? 'bg-slate-900 text-white'
                    : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                @click="filtrar(filtro)"
            >
                {{ filtro }}
            </button>
        </nav>

        <p v-if="alertas.length === 0" class="rounded-lg bg-slate-50 p-6 text-center text-sm text-slate-500">
            Nada por aquí.
        </p>

        <ul v-else class="space-y-3">
            <li
                v-for="alerta in alertas"
                :key="alerta.id"
                class="rounded-xl border bg-white"
                :class="alerta.estado === 'cerrada' ? 'border-slate-200' : colores[alerta.severidad]"
            >
                <div class="flex items-start justify-between gap-4 p-4">
                    <div class="space-y-1">
                        <p class="text-xs font-medium uppercase tracking-wide">
                            {{ etiquetasTipo[alerta.tipo] ?? alerta.tipo }} ·
                            {{ alerta.severidad }}
                        </p>

                        <p class="text-sm">{{ alerta.mensaje }}</p>

                        <p class="text-xs text-slate-500">
                            <!--
                                En una aplicación anónima no hay persona: el
                                riesgo existe y queda registrado, pero no hay a
                                quién atribuirlo. Es el precio del anonimato y
                                está asumido.
                            -->
                            {{ alerta.persona ?? 'Sin persona (aplicación anónima)' }}
                            · {{ cuandoFue(alerta.creada_en) }}
                        </p>
                    </div>

                    <button
                        v-if="alerta.estado !== 'cerrada'"
                        type="button"
                        class="shrink-0 rounded-md bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700"
                        @click="abrir(alerta)"
                    >
                        Atender
                    </button>

                    <span v-else class="shrink-0 text-xs text-slate-500">
                        Cerrada por {{ alerta.atendida_por }}
                    </span>
                </div>

                <!-- La resolución de una alerta ya cerrada se queda a la vista:
                     es lo que la organización tiene que poder demostrar. -->
                <p
                    v-if="alerta.estado === 'cerrada' && alerta.resolucion"
                    class="border-t border-slate-100 px-4 py-3 text-sm text-slate-600"
                >
                    {{ alerta.resolucion }}
                </p>

                <form
                    v-if="abierta === alerta.id"
                    class="space-y-3 border-t border-slate-200 p-4"
                    @submit.prevent="cerrar(alerta)"
                >
                    <label class="block space-y-1">
                        <span class="text-sm font-medium text-slate-700">
                            ¿Qué se hizo?
                        </span>
                        <textarea
                            v-model="formulario.resolucion"
                            rows="3"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            placeholder="A quién se contactó, cuándo y a dónde se canalizó"
                        />
                        <span class="text-xs text-slate-500">
                            Sin esto la alerta no se cierra. Es el registro que sostiene
                            el protocolo de actuación de la organización.
                        </span>
                    </label>

                    <p v-if="formulario.errors.resolucion" class="text-sm text-rose-700">
                        {{ formulario.errors.resolucion }}
                    </p>

                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-md px-3 py-1.5 text-sm text-slate-600"
                            @click="abierta = null"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="formulario.processing"
                            class="rounded-md bg-blue-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                        >
                            Cerrar alerta
                        </button>
                    </div>
                </form>
            </li>
        </ul>
    </div>
</template>
