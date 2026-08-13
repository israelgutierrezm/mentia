<script setup>
/**
 * El perfil longitudinal: la "ficha de hospital" del Doc 01.
 *
 * Los DOMINIOS son las tarjetas y cada constructo su serie. Agrupa por dominio
 * y no por instrumento a propósito: a nadie le importa con qué prueba se midió
 * la ansiedad en 2023, le importa la ansiedad. Es la pantalla que hace visible
 * la idea rectora del proyecto —ver la evolución de cada "órgano" en el tiempo—.
 *
 * La gráfica es un SVG a mano y no una librería: son cuatro puntos y una línea,
 * y meter doscientos kilobytes de dependencia para eso los pagaría cada visita.
 */
import { Head } from '@inertiajs/vue3';

defineProps({
    persona: { type: Object, required: true },
    dominios: { type: Array, required: true },
});

const colorBandera = {
    verde: '#059669',
    amarillo: '#d97706',
    rojo: '#e11d48',
};

const ANCHO = 560;
const ALTO = 120;
const MARGEN = 28;

/** El tope de la escala en que está medido el constructo. */
function topeDe(puntos) {
    const topes = { percentil: 100, T: 100, ci_desviacion: 160, decatipo: 10, estanina: 9 };

    return topes[puntos[0]?.tipo_norma] ?? 100;
}

function coordenadas(puntos) {
    const tope = topeDe(puntos);
    const util = ANCHO - MARGEN * 2;

    return puntos.map((punto, indice) => {
        // Con un solo punto se dibuja al centro: dividir entre cero daría NaN
        // y la línea desaparecería sin decir por qué.
        const x = puntos.length === 1
            ? MARGEN + util / 2
            : MARGEN + (indice / (puntos.length - 1)) * util;

        const y = ALTO - MARGEN - ((punto.valor / tope) * (ALTO - MARGEN * 2));

        return { ...punto, x, y };
    });
}

function trazo(puntos) {
    return coordenadas(puntos)
        .map((punto, indice) => `${indice === 0 ? 'M' : 'L'} ${punto.x} ${punto.y}`)
        .join(' ');
}

function cambiosSignificativos(cambios) {
    return cambios.filter((cambio) => cambio.significativo);
}
</script>

<template>
    <Head :title="`Perfil · ${persona.nombre}`" />

    <div class="mx-auto max-w-4xl space-y-6 p-6">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight text-slate-900">
                {{ persona.nombre }}
            </h1>
            <p class="text-sm text-slate-600">
                Expediente psicométrico: cómo ha evolucionado cada dominio en el tiempo.
            </p>
        </header>

        <p
            v-if="dominios.length === 0"
            class="rounded-lg bg-slate-50 p-6 text-center text-sm text-slate-500"
        >
            Todavía no hay resultados normalizados de esta persona. La serie se
            arma con las evaluaciones que tengan baremo aplicable.
        </p>

        <section
            v-for="tarjeta in dominios"
            :key="tarjeta.dominio"
            class="space-y-4 rounded-xl border border-slate-200 bg-white p-5"
        >
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                {{ tarjeta.dominio }}
            </h2>

            <article
                v-for="serie in tarjeta.constructos"
                :key="serie.constructo"
                class="space-y-2"
            >
                <div class="flex items-baseline justify-between gap-3">
                    <h3 class="text-sm font-medium text-slate-800">{{ serie.constructo }}</h3>
                    <span class="text-xs text-slate-500">
                        {{ serie.puntos.length }}
                        {{ serie.puntos.length === 1 ? 'medición' : 'mediciones' }}
                        · {{ serie.puntos[0].tipo_norma }}
                    </span>
                </div>

                <svg
                    :viewBox="`0 0 ${ANCHO} ${ALTO}`"
                    class="w-full"
                    role="img"
                    :aria-label="`Serie de ${serie.constructo}`"
                >
                    <line
                        :x1="MARGEN" :y1="ALTO - MARGEN"
                        :x2="ANCHO - MARGEN" :y2="ALTO - MARGEN"
                        stroke="#e2e8f0" stroke-width="1"
                    />

                    <path
                        v-if="serie.puntos.length > 1"
                        :d="trazo(serie.puntos)"
                        fill="none" stroke="#2563eb" stroke-width="2"
                    />

                    <g v-for="punto in coordenadas(serie.puntos)" :key="punto.fecha">
                        <circle
                            :cx="punto.x" :cy="punto.y" r="5"
                            :fill="colorBandera[punto.bandera] ?? '#2563eb'"
                        />
                        <text
                            :x="punto.x" :y="punto.y - 10"
                            text-anchor="middle" font-size="11" fill="#475569"
                        >
                            {{ punto.valor }}
                        </text>
                        <text
                            :x="punto.x" :y="ALTO - MARGEN + 14"
                            text-anchor="middle" font-size="10" fill="#94a3b8"
                        >
                            {{ punto.fecha }}
                        </text>
                    </g>
                </svg>

                <!--
                    Sólo se listan los cambios SIGNIFICATIVOS. Marcar todos los
                    movimientos tendría el mismo efecto que no marcar ninguno,
                    porque nadie mira una lista en la que todo está marcado.
                -->
                <ul
                    v-if="cambiosSignificativos(serie.cambios).length"
                    class="space-y-1 rounded-lg bg-amber-50 p-3 text-xs text-amber-900"
                >
                    <li
                        v-for="cambio in cambiosSignificativos(serie.cambios)"
                        :key="cambio.hasta"
                    >
                        Cambio significativo entre {{ cambio.desde }} y {{ cambio.hasta }}:
                        {{ cambio.valor_anterior }} → {{ cambio.valor_actual }}
                        ({{ cambio.direccion }}).
                    </li>
                </ul>
            </article>
        </section>
    </div>
</template>
