/**
 * Los modos de presentación (Doc 02 §6).
 *
 * El reactivo es EL MISMO en todos: lo que cambia es cómo se ve y cómo se
 * acompaña. Un M-CHAT contestado por una madre y un tamizaje contestado por un
 * niño de seis años tienen los mismos ítems y no pueden verse igual.
 *
 * Se declara aquí, en datos, y no con `if (modo === ...)` repartidos por los
 * componentes: así agregar el modo kiosco de la V4 es una entrada más.
 */
export const modos = {
    adulto: {
        clases: 'text-base',
        botones: 'px-4 py-2 text-sm',
        enunciado: 'text-base',
        mostrarProgreso: true,
        mostrarCronometro: true,
        refuerzos: false,
        tts: false,
    },

    adolescente: {
        clases: 'text-base',
        botones: 'px-5 py-3 text-base',
        enunciado: 'text-lg',
        mostrarProgreso: true,
        mostrarCronometro: true,
        // Refuerzo ligero: la barra de progreso ya motiva; un aplauso cada
        // respuesta a los quince años se lee como burla.
        refuerzos: false,
        tts: false,
    },

    informante: {
        clases: 'text-base',
        botones: 'px-4 py-2 text-sm',
        enunciado: 'text-base',
        mostrarProgreso: true,
        mostrarCronometro: false,
        refuerzos: false,
        tts: false,
    },

    /**
     * Infantil, versión básica de la V1: botones grandes, audio del navegador y
     * refuerzo positivo. Sin cronómetro visible —apurar a un niño de seis años
     * mide su ansiedad, no lo que la prueba quiere medir— y sin barra de
     * progreso, que a esa edad sólo comunica cuánto falta.
     */
    infantil: {
        clases: 'text-lg',
        botones: 'px-8 py-6 text-xl',
        enunciado: 'text-2xl',
        mostrarProgreso: false,
        mostrarCronometro: false,
        refuerzos: true,
        tts: true,
    },

    examinador: {
        clases: 'text-sm',
        botones: 'px-3 py-1.5 text-sm',
        enunciado: 'text-sm',
        mostrarProgreso: true,
        mostrarCronometro: true,
        refuerzos: false,
        tts: false,
    },

    kiosco: {
        clases: 'text-lg',
        botones: 'px-6 py-4 text-lg',
        enunciado: 'text-xl',
        mostrarProgreso: true,
        mostrarCronometro: true,
        refuerzos: false,
        tts: false,
    },
};

export function configuracionDe(modo) {
    return modos[modo] ?? modos.adulto;
}

/**
 * Lee un texto en voz alta con la síntesis del navegador.
 *
 * Sin librería ni servicio externo: mandar el enunciado de un instrumento con
 * copyright a una API de terceros para que lo lea sería distribuir su
 * contenido. `speechSynthesis` lo hace en el dispositivo.
 */
export function leerEnVozAlta(texto) {
    if (typeof window === 'undefined' || !window.speechSynthesis || !texto) {
        return;
    }

    window.speechSynthesis.cancel();

    const locucion = new SpeechSynthesisUtterance(texto);
    locucion.lang = 'es-MX';
    locucion.rate = 0.9;

    window.speechSynthesis.speak(locucion);
}

/** Refuerzos para el modo infantil. Se rotan para que no cansen. */
export const refuerzos = ['¡Muy bien!', '¡Así es!', '¡Excelente!', '¡Vas muy bien!', '¡Genial!'];
