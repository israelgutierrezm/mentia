<script setup>
/**
 * El resultado de una aplicación, por audiencia.
 *
 * La misma URL le enseña a la psicóloga el perfil técnico y a la madre el texto
 * que se escribió para ella. Lo decide el servidor: aquí no hay selector de
 * audiencia porque no puede haberlo.
 *
 * Las escalas sólo vienen si quien mira tiene `resultados.ver_detalle`. Cuando
 * no vienen, esta pantalla enseña las interpretaciones y ya — un percentil sin
 * nadie que lo explique es una cifra que la persona va a interpretar sola, y
 * casi siempre mal.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    resultado: { type: Object, required: true },
});

const conDetalle = computed(() => Array.isArray(props.resultado.escalas));

const banderas = {
    verde: 'bg-emerald-100 text-emerald-900',
    amarillo: 'bg-amber-100 text-amber-900',
    rojo: 'bg-rose-100 text-rose-900',
};

const avisosValidez = {
    dudosa: 'Este protocolo tiene señales de validez cuestionable. Interprétalo con reserva.',
    invalida: 'Este protocolo se marcó como inválido y no se calificó.',
};

/**
 * El ancho de la barra de perfil.
 *
 * Los percentiles y las puntuaciones T tienen escalas distintas: dibujar las
 * dos sobre el mismo 0–100 haría que una T de 50 —que es exactamente el
 * promedio— se viera a la mitad de la barra igual que un percentil 50, lo cual
 * es correcto por casualidad, y que una T de 80 se saliera de la caja.
 */
function anchoDe(escala) {
    if (escala.normalizado === null || escala.normalizado === undefined) {
        return 0;
    }

    const topes = { percentil: 100, T: 100, ci_desviacion: 160, decatipo: 10, estanina: 9 };
    const tope = topes[escala.tipo_norma] ?? 100;

    return Math.min(100, Math.max(0, (escala.normalizado / tope) * 100));
}
</script>

<template>
    <Head :title="`Resultados · ${resultado.instrumento}`" />

    <div class="mx-auto max-w-3xl space-y-6 p-6">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight text-slate-900">
                {{ resultado.instrumento }}
            </h1>
            <p class="text-sm text-slate-600">
                Contestado el {{ resultado.fecha }}
            </p>
        </header>

        <!-- La advertencia de validez va ARRIBA, antes de cualquier número: un
             puntaje leído sin ella es un puntaje mal leído. -->
        <p
            v-if="avisosValidez[resultado.validez]"
            class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            {{ avisosValidez[resultado.validez] }}
        </p>

        <section v-if="resultado.interpretaciones.length" class="space-y-3">
            <h2 class="text-sm font-medium text-slate-700">Interpretación</h2>

            <article
                v-for="(texto, indice) in resultado.interpretaciones"
                :key="indice"
                class="rounded-xl border border-slate-200 bg-white p-5"
            >
                <span
                    v-if="texto.bandera"
                    class="mb-2 inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="banderas[texto.bandera]"
                >
                    {{ texto.bandera }}
                </span>

                <p class="whitespace-pre-line text-sm text-slate-800">{{ texto.texto }}</p>
            </article>
        </section>

        <p v-else class="rounded-lg bg-slate-50 p-6 text-center text-sm text-slate-500">
            Todavía no hay interpretaciones para esta evaluación.
        </p>

        <!-- ── Perfil por escalas: sólo con permiso de detalle ───────────── -->
        <section v-if="conDetalle" class="space-y-3">
            <h2 class="text-sm font-medium text-slate-700">Perfil por escalas</h2>

            <div class="space-y-3 rounded-xl border border-slate-200 bg-white p-5">
                <div v-for="escala in resultado.escalas" :key="escala.escala" class="space-y-1">
                    <div class="flex items-baseline justify-between gap-3 text-sm">
                        <span class="text-slate-800">{{ escala.nombre ?? escala.escala }}</span>

                        <span class="shrink-0 text-slate-500">
                            <template v-if="escala.sin_norma">
                                bruto {{ escala.bruto }} · sin norma
                            </template>
                            <template v-else-if="escala.tipo_norma === 'semaforo'">
                                {{ escala.etiqueta }}
                            </template>
                            <template v-else>
                                {{ escala.normalizado }} ({{ escala.tipo_norma }})
                            </template>
                        </span>
                    </div>

                    <!--
                        Sin norma NO se dibuja barra. Un bruto pintado junto a
                        percentiles se lee como si significara lo mismo, y no
                        significa nada fuera de su propia aplicación.
                    -->
                    <div v-if="!escala.sin_norma && escala.tipo_norma !== 'semaforo'" class="h-2 rounded-full bg-slate-100">
                        <div
                            class="h-full rounded-full bg-blue-600"
                            :style="{ width: `${anchoDe(escala)}%` }"
                        />
                    </div>

                    <p v-else-if="escala.sin_norma" class="text-xs text-amber-700">
                        No hay baremo aplicable para esta persona: el puntaje no es comparable.
                    </p>
                </div>
            </div>
        </section>

        <!-- ── Validez, sólo para la audiencia profesional ───────────────── -->
        <section v-if="resultado.validez_detalle?.length" class="space-y-2">
            <h2 class="text-sm font-medium text-slate-700">Verificaciones de validez</h2>

            <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white text-sm">
                <li
                    v-for="(detalle, indice) in resultado.validez_detalle"
                    :key="indice"
                    class="flex items-start gap-3 p-3"
                >
                    <span
                        class="mt-0.5 shrink-0 rounded px-1.5 py-0.5 text-xs"
                        :class="detalle.resultado === 'paso'
                            ? 'bg-emerald-100 text-emerald-900'
                            : 'bg-amber-100 text-amber-900'"
                    >
                        {{ detalle.resultado }}
                    </span>
                    <span class="text-slate-700">{{ detalle.detalle }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>
