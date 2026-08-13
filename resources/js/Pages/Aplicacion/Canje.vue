<script setup>
/**
 * Canje de liga, SIN sesión.
 *
 * Es la única pantalla del sistema a la que se entra sin cuenta. La madre que
 * recibe por WhatsApp la liga para contestar el M-CHAT sobre su hijo no se
 * registra en nada: su credencial es el token, y por eso esta página no lleva
 * el layout del panel ni ninguna liga que lleve a él.
 *
 * El token NO llega como prop: viene en el FRAGMENTO de la URL —lo que está
 * después de `#`— y el fragmento no se manda al servidor. La liga del correo es
 * `/contestar#<token>`, y esta página lo lee del navegador y lo canjea por POST
 * contra `/api/v1`. Con el token en la ruta, la credencial quedaría escrita en
 * el log de accesos del servidor web, en el proxy de la empresa y en cualquier
 * `Referer` que salga de aquí.
 */
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import LayoutAplicacion from '../../Aplicacion/LayoutAplicacion.vue';
import Contestar from '../../Aplicacion/Contestar.vue';

defineOptions({ layout: null });

const codigo = ref('');
const estructura = ref(null);
const cargando = ref(false);
const error = ref(null);
const consentido = ref(false);

async function canjear() {
    if (codigo.value.length !== 64) {
        error.value = 'Ese código no está completo. Cópialo otra vez desde tu liga.';

        return;
    }

    cargando.value = true;
    error.value = null;

    try {
        const { data } = await window.axios.post('/api/v1/aplicaciones/iniciar', {
            token: codigo.value,
        });

        estructura.value = data;

        // El token sale de la barra de direcciones en cuanto se canjea: una URL
        // con la credencial dentro se comparte por accidente —se manda la
        // captura, se deja abierta en una tableta compartida—.
        window.history.replaceState({}, '', '/contestar');
        codigo.value = '';
    } catch (fallo) {
        error.value = mensajeDe(fallo);
    } finally {
        cargando.value = false;
    }
}

function mensajeDe(fallo) {
    const datos = fallo.response ? fallo.response.data : null;

    if (datos && datos.errors) {
        return Object.values(datos.errors).flat().join(' ');
    }

    return (datos && (datos.detail || datos.message))
        || 'No se pudo conectar. Revisa tu internet e inténtalo otra vez.';
}

// Con token en el fragmento se canjea solo: quien picó la liga del correo ya
// dijo que quería entrar, y pedirle además que le dé a un botón es un paso de
// más. Si un reescritor de ligas corporativo se comió el fragmento, cae en la
// captura a mano, que por eso sigue existiendo.
onMounted(() => {
    const delFragmento = window.location.hash.replace(/^#/, '').trim();

    if (delFragmento.length === 64) {
        codigo.value = delFragmento;
        canjear();
    }
});
</script>

<template>
    <Head :title="estructura ? estructura.instrumento : 'Contestar'" />

    <LayoutAplicacion
        :modo="estructura ? estructura.modo_presentacion : 'adulto'"
        :titulo="estructura ? estructura.instrumento : ''"
    >
        <!-- ── Ya se canjeó: a contestar ─────────────────────────────────── -->
        <template v-if="estructura && consentido">
            <Contestar :estructura="estructura" />
        </template>

        <!-- ── Portada: qué es esto y qué pasa con lo que conteste ───────── -->
        <section
            v-else-if="estructura"
            class="space-y-5 rounded-xl border border-slate-200 bg-white p-6"
        >
            <div class="space-y-2">
                <h1 class="text-xl font-semibold tracking-tight text-slate-900">
                    {{ estructura.instrumento }}
                </h1>
                <p class="text-sm text-slate-600">
                    {{ estructura.bloques.length }}
                    {{ estructura.bloques.length === 1 ? 'sección' : 'secciones' }},
                    {{ estructura.bloques.reduce((suma, bloque) => suma + bloque.total_reactivos, 0) }}
                    preguntas.
                </p>
            </div>

            <div
                v-for="bloque in estructura.bloques"
                :key="bloque.clave"
                class="rounded-lg bg-slate-50 p-4"
            >
                <p class="text-sm font-medium text-slate-900">{{ bloque.titulo }}</p>
                <p v-if="bloque.instrucciones" class="mt-1 text-sm text-slate-600">
                    {{ bloque.instrucciones }}
                </p>
                <p v-if="bloque.tiempo_limite_seg" class="mt-2 text-xs text-amber-800">
                    Esta sección tiene tiempo límite: {{ Math.round(bloque.tiempo_limite_seg / 60) }}
                    minutos desde que la abres.
                </p>
            </div>

            <!--
                Lo que se le dice a quien contesta antes de empezar. No es el
                consentimiento informado formal —ese vive en su propio módulo, con
                versión y firma—: es la advertencia mínima de que esto no es un
                juego y de que alguien va a leer lo que conteste.
            -->
            <div class="rounded-lg border border-slate-200 p-4 text-sm text-slate-600">
                <p>
                    Contesta con lo primero que se te venga a la mente y con la verdad;
                    no hay respuestas buenas ni malas. Lo que respondas lo revisa un
                    profesional y forma parte de tu expediente.
                </p>
                <p class="mt-2">
                    Si algo de lo que se te pregunta te mueve más de la cuenta, puedes
                    pausar y seguir después.
                </p>
            </div>

            <button
                type="button"
                class="w-full rounded-md bg-blue-600 px-5 py-3 font-medium text-white transition hover:bg-blue-700"
                @click="consentido = true"
            >
                Empezar
            </button>
        </section>

        <!-- ── Sin token todavía: capturarlo a mano ──────────────────────── -->
        <section v-else class="space-y-5 rounded-xl border border-slate-200 bg-white p-6">
            <div class="space-y-2">
                <h1 class="text-xl font-semibold tracking-tight text-slate-900">
                    Entrar a contestar
                </h1>
                <p class="text-sm text-slate-600">
                    Pega aquí el código de tu liga. Te lo mandaron por correo o por
                    mensaje junto con la invitación.
                </p>
            </div>

            <p v-if="cargando" class="text-sm text-slate-500">Abriendo tu evaluación…</p>

            <template v-else>
                <input
                    v-model.trim="codigo"
                    type="text"
                    autocomplete="off"
                    spellcheck="false"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm"
                    placeholder="Código de 64 caracteres"
                    @keyup.enter="canjear"
                >

                <p v-if="error" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-900">
                    {{ error }}
                </p>

                <button
                    type="button"
                    class="w-full rounded-md bg-blue-600 px-5 py-3 font-medium text-white transition hover:bg-blue-700"
                    @click="canjear"
                >
                    Entrar
                </button>
            </template>
        </section>
    </LayoutAplicacion>
</template>
