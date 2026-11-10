/**
 * Accesibilidad de la PWA ciudadana: dictado de la nota, lectura en voz alta y vibración.
 *
 * - Dictado: Web Speech API (Chrome Android, Safari iOS). En la mayoría de teléfonos transcribe en
 *   servidores de Google o Apple, así que necesita señal; sin ella se ofrece el micrófono del teclado.
 *   Solo viaja el texto (≤ 140 B): el audio nunca entra al reporte ni al canal.
 * - Voz: speechSynthesis usa las voces instaladas en el teléfono y funciona sin red. Es opcional
 *   porque quien usa lector de pantalla ya oye los avisos y dos voces a la vez se pisan.
 * - Vibración: confirma sin mirar la pantalla (personas sordas o con baja visión).
 */

/** Patrones de vibración (ms): un pulso al guardar y dos cuando la central confirma. */
export const VIBRACION = {
    guardado: [120],
    confirmado: [120, 80, 120],
};

/** Idioma del reconocedor y de la voz según el idioma de la interfaz. */
export function idiomaDeVoz(idioma) {
    return idioma === 'en' ? 'en-US' : 'es-CO';
}

/** @returns {(new () => any) | null} el reconocedor del navegador, si existe */
export function reconocedorDisponible(ventana = globalThis) {
    return ventana.SpeechRecognition ?? ventana.webkitSpeechRecognition ?? null;
}

export function vozDisponible(ventana = globalThis) {
    return typeof ventana.speechSynthesis !== 'undefined' && typeof ventana.SpeechSynthesisUtterance !== 'undefined';
}

/** Une lo dictado a la nota existente con un solo espacio. */
export function unirDictado(textoActual, dictado) {
    const limpio = String(dictado ?? '').replace(/\s+/g, ' ').trim();
    if (!limpio) {
        return textoActual;
    }
    const base = String(textoActual ?? '').trimEnd();

    return base ? `${base} ${limpio}` : limpio;
}

/**
 * Frase (en español, se traduce con t()) para cada error del reconocedor.
 * Ver https://developer.mozilla.org/docs/Web/API/SpeechRecognitionErrorEvent/error
 */
export function mensajeErrorDictado(codigo) {
    switch (codigo) {
        case 'network':
        case 'sin-red':
            return 'El dictado necesita señal. Escribe la nota o usa el micrófono de tu teclado.';
        case 'not-allowed':
        case 'service-not-allowed':
            return 'Permite el uso del micrófono para dictar, o escribe la nota.';
        case 'no-speech':
            return 'No se escuchó nada. Toca «Dictar» y habla cerca del teléfono.';
        case 'audio-capture':
            return 'No se encontró un micrófono en este teléfono.';
        case 'aborted':
            return null;
        default:
            return 'No se pudo dictar. Escribe la nota o usa el micrófono de tu teclado.';
    }
}

/**
 * Inicia un dictado de una frase. Devuelve una función para detenerlo.
 *
 * @param {{ lang: string, alTexto: (texto: string, final: boolean) => void, alTerminar: (error: string|null) => void }} opciones
 */
export function dictar({ lang, alTexto, alTerminar }, Reconocedor = reconocedorDisponible()) {
    if (!Reconocedor) {
        alTerminar('no-soportado');
        return () => {};
    }

    const reconocedor = new Reconocedor();
    reconocedor.lang = lang;
    reconocedor.continuous = false;
    reconocedor.interimResults = true;
    reconocedor.maxAlternatives = 1;

    let error = null;
    reconocedor.onresult = (evento) => {
        let texto = '';
        let final = false;
        for (let i = evento.resultIndex; i < evento.results.length; i++) {
            texto += evento.results[i][0].transcript;
            final = final || evento.results[i].isFinal;
        }
        alTexto(texto, final);
    };
    reconocedor.onerror = (evento) => {
        error = evento.error;
    };
    reconocedor.onend = () => alTerminar(error);

    try {
        reconocedor.start();
    } catch (excepcion) {
        alTerminar('no-soportado');
    }

    return () => reconocedor.stop();
}

/**
 * Resumen hablado de un reporte guardado en el teléfono: tipo, banderas, personas, nota, estado y hora.
 * Frases cortas separadas por punto para que la voz haga pausas.
 *
 * @param {{ tipo: number, banderas: number, personas: number, texto: string, sin_ubicacion: boolean }} registro
 * @param {{ tipo: string, banderas: string[], estado: string, hora: string }} nombres textos ya traducidos
 * @param {(frase: string, params?: Record<string, unknown>) => string} t
 */
export function resumenHablado(registro, nombres, t) {
    const frases = [t('Tu último reporte: :tipo.', { tipo: nombres.tipo })];

    if (nombres.banderas.length) {
        frases.push(t('Marcaste: :banderas.', { banderas: nombres.banderas.join(', ') }));
    }
    if (registro.personas === 1) {
        frases.push(t('1 persona afectada.'));
    } else if (registro.personas > 1) {
        frases.push(t(':n personas afectadas.', { n: registro.personas }));
    } else {
        frases.push(t('Personas afectadas: no sabes.'));
    }
    if (registro.texto) {
        frases.push(t('Tu nota dice: :nota.', { nota: registro.texto.replace(/[.\s]+$/, '') }));
    }
    if (registro.sin_ubicacion) {
        frases.push(t('Se envió sin ubicación.'));
    }
    frases.push(t('Estado: :estado.', { estado: nombres.estado.replace(/[.…\s]+$/, '') }));
    frases.push(t('Hora: :hora.', { hora: nombres.hora.replace(/\.$/, '') }));

    return frases.join(' ');
}

/** Lee un texto en voz alta (interrumpe lo que se estuviera leyendo). */
export function hablar(texto, lang, ventana = globalThis) {
    if (!texto || !vozDisponible(ventana)) {
        return false;
    }
    const frase = new ventana.SpeechSynthesisUtterance(texto);
    frase.lang = lang;
    ventana.speechSynthesis.cancel();
    ventana.speechSynthesis.speak(frase);

    return true;
}

export function vibrar(patron, navegador = globalThis.navigator) {
    try {
        return typeof navegador?.vibrate === 'function' ? navegador.vibrate(patron) : false;
    } catch (error) {
        return false;
    }
}
