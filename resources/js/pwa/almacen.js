/**
 * IndexedDB de la PWA (T-005): store `salida` (reportes) y `ajustes` (device_id, simular sin red).
 * El original solo se borra (bytes = null) cuando llega el ACK.
 */
const NOMBRE_BD = 'sard';
const VERSION_BD = 1;

let conexion = null;

function promesa(peticion) {
    return new Promise((resolver, rechazar) => {
        peticion.onsuccess = () => resolver(peticion.result);
        peticion.onerror = () => rechazar(peticion.error);
    });
}

export async function abrir() {
    if (conexion) {
        return conexion;
    }

    const peticion = indexedDB.open(NOMBRE_BD, VERSION_BD);
    peticion.onupgradeneeded = () => {
        const bd = peticion.result;
        if (!bd.objectStoreNames.contains('salida')) {
            bd.createObjectStore('salida', { keyPath: 'uuid' });
        }
        if (!bd.objectStoreNames.contains('ajustes')) {
            bd.createObjectStore('ajustes', { keyPath: 'clave' });
        }
    };
    conexion = await promesa(peticion);

    return conexion;
}

async function store(nombre, modo = 'readonly') {
    return (await abrir()).transaction(nombre, modo).objectStore(nombre);
}

export async function todos() {
    return promesa((await store('salida')).getAll());
}

export async function guardar(registro) {
    return promesa((await store('salida', 'readwrite')).put(registro));
}

export async function leerAjuste(clave, porDefecto = null) {
    const fila = await promesa((await store('ajustes')).get(clave));

    return fila ? fila.valor : porDefecto;
}

export async function guardarAjuste(clave, valor) {
    return promesa((await store('ajustes', 'readwrite')).put({ clave, valor }));
}
