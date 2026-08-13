<script setup>
/**
 * La pantalla de quien contesta.
 *
 * Maneja el diálogo con el motor: estructura → tramo de reactivos → lote de
 * respuestas → finalizar. Tres cosas que no son evidentes y son el punto:
 *
 * 1. **Bandeja de salida.** Una respuesta emitida entra a `porEnviar` y no sale
 *    de ahí hasta que el servidor la acuse. Un celular con mala señal a media
 *    prueba no puede costar los ítems contestados, y el `uuid_cliente` —que se
 *    genera AQUÍ, antes de mandar— es lo que hace que reintentar sea gratis.
 * 2. **El cronómetro de pantalla es decorativo.** Descuenta entre peticiones
 *    para que se vea vivo, y cada respuesta del servidor lo corrige. La cuenta
 *    de verdad es del servidor, siempre (Doc 02 §2).
 * 3. **`respondida_en` lo pone el cliente.** Es el momento en que la persona
 *    contestó, no el momento en que el paquete llegó. Con reintentos y cola,
 *    esas dos cosas se separan minutos, y es lo que ordena las correcciones.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';
import RenderizadorReactivo from './RenderizadorReactivo.vue';
import { configuracionDe, refuerzos } from './modos';

const props = defineProps({
    estructura: { type: Object, required: true },
});

const emit = defineEmits(['terminado']);

const uuid = props.estructura.aplicacion_uuid;
const config = computed(() => configuracionDe(props.estructura.modo_presentacion));

const bloque = ref(props.estructura.bloque_actual);
const reactivos = ref([]);
const respondidos = ref({});
const mostradoEn = ref({});
const totalVisibles = ref(0);
const desde = ref(0);
const cronometro = ref(null);
const refuerzo = ref(null);
const terminado = ref(false);
const pausado = ref(false);
const cargando = ref(true);
const error = ref(null);

const porEnviar = ref([]);
const enviando = ref(false);
const sinConexion = ref(false);

const tamanoTramo = 5;

let tictac = null;
let reintento = null;
let agrupador = null;

const progreso = computed(() => {
    if (totalVisibles.value === 0) {
        return 0;
    }

    return Math.min(
        100,
        Math.round(((desde.value + contestadosDelTramo.value) / totalVisibles.value) * 100),
    );
});

const contestadosDelTramo = computed(
    () => reactivos.value.filter((reactivo) => respondidos.value[reactivo.codigo] !== undefined).length,
);

const tramoCompleto = computed(
    () => reactivos.value.length > 0 && contestadosDelTramo.value === reactivos.value.length,
);

const esUltimoTramo = computed(() => desde.value + reactivos.value.length >= totalVisibles.value);

// ── Diálogo con el motor ──────────────────────────────────────────────────

async function cargarTramo(inicio) {
    cargando.value = true;
    error.value = null;

    try {
        const { data } = await window.axios.get(
            `/api/v1/aplicaciones/${uuid}/bloques/${bloque.value}/reactivos`,
            { params: { desde: inicio, cantidad: tamanoTramo } },
        );

        reactivos.value = data.reactivos;
        totalVisibles.value = data.total_visibles;
        desde.value = inicio;
        cronometro.value = data.cronometro;

        const ahora = Date.now();
        data.reactivos.forEach((reactivo) => {
            mostradoEn.value[reactivo.codigo] ??= ahora;
        });
    } catch (fallo) {
        error.value = mensajeDe(fallo);
    } finally {
        cargando.value = false;
    }
}

/**
 * Encola la respuesta y trata de mandarla.
 *
 * Un componente puede emitir varias de golpe —el par más/menos de un Cleaver,
 * el orden completo de un ranking—, así que todo se normaliza a lista.
 */
function responder(reactivo, dato) {
    const partes = Array.isArray(dato) ? dato : [dato];
    const ahora = Date.now();

    partes.forEach((parte) => {
        porEnviar.value.push({
            uuid_cliente: nuevoUuid(),
            reactivo_codigo: reactivo.codigo,
            tiempo_respuesta_ms: Math.max(0, ahora - (mostradoEn.value[reactivo.codigo] ?? ahora)),
            respondida_en: new Date().toISOString(),
            ...parte,
        });
    });

    // Lo que se ve marcado es la última parte: en likert es la opción, y en
    // ranking e ipsativos el propio componente lleva su estado.
    respondidos.value[reactivo.codigo] = partes[partes.length - 1];

    if (config.value.refuerzos) {
        refuerzo.value = refuerzos[Math.floor(Math.random() * refuerzos.length)];
        setTimeout(() => (refuerzo.value = null), 1200);
    }

    /*
     * Se JUNTAN antes de mandar. El endpoint recibe lotes justamente para esto:
     * una petición por clic convierte un tamizaje de treinta reactivos en
     * treinta viajes de red, y en un celular con señal irregular eso es donde
     * se pierden las respuestas.
     *
     * Segundo y medio es el margen: quien va rápido manda de tres en tres y
     * quien se toma su tiempo manda de una en una, sin que ninguno espere.
     */
    clearTimeout(agrupador);
    agrupador = setTimeout(vaciarBandeja, 1500);
}

/**
 * Manda lo pendiente. Sólo lo saca de la bandeja cuando el servidor acusa.
 */
async function vaciarBandeja() {
    clearTimeout(agrupador);

    if (enviando.value) {
        return;
    }

    enviando.value = true;

    try {
        // En tandas de cien, que es el tope del endpoint. Se repite hasta
        // vaciar: tras un rato sin señal puede haber más de un lote esperando.
        while (porEnviar.value.length > 0) {
            const lote = porEnviar.value.slice(0, 100);

            try {
                const { data } = await window.axios.post(
                    `/api/v1/aplicaciones/${uuid}/respuestas`,
                    { respuestas: lote },
                );

                porEnviar.value = porEnviar.value.slice(lote.length);
                sinConexion.value = false;

                if (data.cronometro) {
                    cronometro.value = data.cronometro;
                }
            } catch (fallo) {
                /*
                 * 422 es del CONTENIDO del lote, no de la red: reintentarlo
                 * sería un ciclo infinito que además bloquea todo lo que viene
                 * detrás. Se descarta ese lote y se avisa, que es lo único
                 * honesto que se puede hacer con él.
                 */
                if (fallo.response && fallo.response.status === 422) {
                    porEnviar.value = porEnviar.value.slice(lote.length);
                    error.value = mensajeDe(fallo);

                    continue;
                }

                sinConexion.value = true;

                return;
            }
        }
    } finally {
        enviando.value = false;
    }
}

async function siguiente() {
    await vaciarBandeja();

    // Con respuestas todavía sin acusar no se avanza: el tramo siguiente lo
    // calcula el servidor con los saltos ya resueltos, y resolverlos sin las
    // respuestas que faltan mostraría reactivos que debían saltarse.
    if (porEnviar.value.length > 0) {
        return;
    }

    if (esUltimoTramo.value) {
        await finalizar();

        return;
    }

    await cargarTramo(desde.value + reactivos.value.length);
}

async function finalizar() {
    try {
        await window.axios.post(`/api/v1/aplicaciones/${uuid}/finalizar`);
        terminado.value = true;
        detenerRelojes();
        emit('terminado');
    } catch (fallo) {
        error.value = mensajeDe(fallo);
    }
}

async function pausar() {
    await vaciarBandeja();

    try {
        await window.axios.post(`/api/v1/aplicaciones/${uuid}/pausar`);
        pausado.value = true;
        clearInterval(tictac);
    } catch (fallo) {
        error.value = mensajeDe(fallo);
    }
}

/**
 * Reanudar sin volver a pedir el token.
 *
 * La pausa no cierra la sesión de contestar: mandar a la persona de regreso a
 * la pantalla del código para continuar donde iba sería castigarla por haberse
 * tomado un respiro, que es justo lo que la pausa existe para permitir.
 */
async function reanudar() {
    try {
        const { data } = await window.axios.post(`/api/v1/aplicaciones/${uuid}/reanudar`);

        cronometro.value = data.cronometro;
        pausado.value = false;

        tictac = setInterval(descontar, 1000);

        await cargarTramo(desde.value);
    } catch (fallo) {
        error.value = mensajeDe(fallo);
    }
}

// ── Ciclo de vida ─────────────────────────────────────────────────────────

onMounted(async () => {
    await cargarTramo(0);

    tictac = setInterval(descontar, 1000);

    // Reintento de la bandeja. Diez segundos: lo bastante seguido para que la
    // señal recuperada se note, lo bastante espaciado para no castigar la red.
    reintento = setInterval(vaciarBandeja, 10000);

    window.addEventListener('beforeunload', avisarPendientes);
});

onUnmounted(() => {
    detenerRelojes();
    window.removeEventListener('beforeunload', avisarPendientes);
});

function descontar() {
    if (cronometro.value && cronometro.value.restante_seg > 0) {
        cronometro.value.restante_seg -= 1;
    }
}

function detenerRelojes() {
    clearInterval(tictac);
    clearInterval(reintento);
    clearTimeout(agrupador);
}

/** Cerrar la pestaña con respuestas sin mandar sí merece una advertencia. */
function avisarPendientes(evento) {
    if (porEnviar.value.length > 0) {
        evento.preventDefault();
        evento.returnValue = '';
    }
}

function nuevoUuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
    }

    // Navegadores viejos y contextos sin https: `randomUUID` no existe y sin
    // uuid no hay idempotencia, que es de lo que depende el reintento.
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (caracter) => {
        const azar = (Math.random() * 16) | 0;
        const valor = caracter === 'x' ? azar : (azar & 0x3) | 0x8;

        return valor.toString(16);
    });
}

function mensajeDe(fallo) {
    const datos = fallo.response ? fallo.response.data : null;

    if (datos && datos.errors) {
        return Object.values(datos.errors).flat().join(' ');
    }

    return (datos && (datos.detail || datos.message))
        || 'No se pudo conectar. Revisa tu internet.';
}

function reloj(segundos) {
    const minutos = Math.floor(segundos / 60);

    return `${minutos}:${String(segundos % 60).padStart(2, '0')}`;
}
</script>

<template>
    <div class="space-y-5">
        <header v-if="!terminado && !pausado" class="space-y-3">
            <div v-if="config.mostrarProgreso" class="space-y-1">
                <div class="h-2 overflow-hidden rounded-full bg-slate-200">
                    <div class="h-full bg-blue-600 transition-all" :style="{ width: `${progreso}%` }" />
                </div>
                <p class="text-xs text-slate-500">{{ progreso }}% contestado</p>
            </div>

            <p
                v-if="config.mostrarCronometro && cronometro"
                class="text-sm font-medium"
                :class="cronometro.restante_seg < 60 ? 'text-rose-700' : 'text-slate-600'"
            >
                Tiempo restante: {{ reloj(cronometro.restante_seg) }}
            </p>
        </header>

        <p
            v-if="sinConexion"
            class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            Se perdió la conexión. Tus respuestas están guardadas en este dispositivo
            y se mandarán solas en cuanto vuelva. No cierres esta pantalla.
        </p>

        <p v-if="error" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-900">
            {{ error }}
        </p>

        <p
            v-if="refuerzo"
            class="rounded-xl bg-emerald-50 px-4 py-3 text-center text-2xl font-medium text-emerald-800"
        >
            {{ refuerzo }}
        </p>

        <!--
            En pausa. El cronómetro del bloque quedó congelado en el servidor,
            así que un descanso de dos horas no cuesta tiempo de prueba.
        -->
        <section
            v-if="pausado"
            class="space-y-4 rounded-xl border border-slate-200 bg-white p-8 text-center"
        >
            <p class="text-lg font-medium text-slate-900">Quedó en pausa.</p>
            <p class="text-sm text-slate-600">
                Lo que llevas está guardado y el tiempo dejó de correr. Puedes
                seguir cuando quieras.
            </p>
            <button
                type="button"
                class="rounded-md bg-blue-600 px-5 py-3 font-medium text-white transition hover:bg-blue-700"
                @click="reanudar"
            >
                Seguir contestando
            </button>
        </section>

        <template v-else-if="!terminado">
            <p v-if="cargando" class="py-10 text-center text-sm text-slate-500">
                Cargando…
            </p>

            <RenderizadorReactivo
                v-for="reactivo in reactivos"
                v-else
                :key="reactivo.codigo"
                :reactivo="reactivo"
                :modo="estructura.modo_presentacion"
                :valor="respondidos[reactivo.codigo]?.opcion_codigo ?? null"
                @responder="responder(reactivo, $event)"
            />

            <div v-if="!cargando" class="flex items-center justify-between gap-4 pt-2">
                <button
                    v-if="estructura.permite_pausas"
                    type="button"
                    class="text-sm text-slate-600 underline-offset-2 hover:underline"
                    @click="pausar"
                >
                    Pausar y seguir después
                </button>
                <span v-else />

                <button
                    type="button"
                    :disabled="!tramoCompleto || enviando"
                    class="rounded-md bg-blue-600 font-medium text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-40"
                    :class="config.botones"
                    @click="siguiente"
                >
                    <template v-if="enviando">Guardando…</template>
                    <template v-else>{{ esUltimoTramo ? 'Terminar' : 'Siguiente' }}</template>
                </button>
            </div>
        </template>

        <!--
            Cierre. NUNCA dice qué salió: el resultado en crudo no se le comunica
            a quien contestó (Doc 01 §6 — el sistema sugiere, el profesional
            diagnostica). Un número aquí es una interpretación sin nadie que la
            sostenga, y en un tamizaje clínico eso hace daño.
        -->
        <section v-else class="space-y-3 rounded-xl border border-slate-200 bg-white p-8 text-center">
            <p class="text-xl font-medium text-slate-900">Listo, terminaste.</p>
            <p class="text-sm text-slate-600">
                Gracias por tu tiempo. Tus respuestas quedaron guardadas y las va a
                revisar un profesional. Ya puedes cerrar esta pantalla.
            </p>
        </section>
    </div>
</template>
