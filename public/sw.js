/*
 * Service Worker de la PWA ciudadana (T-005).
 * - Precachea /pwa y los assets de `npm run build` que usa la PWA (leídos de build/manifest.json).
 * - /pwa: red primero y, sin red, la copia guardada. Assets con hash: caché primero.
 * - Nunca intercepta POST /api/reportes: la cola de reintentos vive en IndexedDB.
 */
const CACHE = 'sard-pwa-v1';
const BASICOS = ['/pwa', '/manifest.webmanifest', '/icons/sard.svg'];

async function assetsDeLaPwa() {
    try {
        const manifiesto = await (await fetch('/build/manifest.json', { cache: 'no-store' })).json();
        const archivos = new Set();
        const agregar = (clave) => {
            const entrada = manifiesto[clave];
            if (!entrada || archivos.has(`/build/${entrada.file}`)) {
                return;
            }
            archivos.add(`/build/${entrada.file}`);
            (entrada.css ?? []).forEach((css) => archivos.add(`/build/${css}`));
            (entrada.assets ?? []).forEach((asset) => archivos.add(`/build/${asset}`));
            (entrada.imports ?? []).forEach(agregar);
        };

        Object.keys(manifiesto)
            .filter((clave) => clave.startsWith('resources/js/pwa') || clave.startsWith('resources/css/pwa') || clave.includes('fonts'))
            .forEach(agregar);

        return [...archivos];
    } catch (error) {
        return [];
    }
}

self.addEventListener('install', (evento) => {
    evento.waitUntil((async () => {
        const cache = await caches.open(CACHE);
        await cache.addAll(BASICOS);
        await Promise.allSettled((await assetsDeLaPwa()).map((url) => cache.add(url)));
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (evento) => {
    evento.waitUntil((async () => {
        const nombres = await caches.keys();
        await Promise.all(nombres.filter((nombre) => nombre !== CACHE).map((nombre) => caches.delete(nombre)));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (evento) => {
    const peticion = evento.request;
    const url = new URL(peticion.url);
    if (peticion.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/')) {
        return;
    }

    if (url.pathname === '/pwa') {
        evento.respondWith((async () => {
            try {
                const respuesta = await fetch(peticion);
                (await caches.open(CACHE)).put('/pwa', respuesta.clone());
                return respuesta;
            } catch (error) {
                return (await caches.match('/pwa')) ?? Response.error();
            }
        })());
        return;
    }

    if (url.pathname.startsWith('/build/') || BASICOS.includes(url.pathname)) {
        evento.respondWith((async () => {
            const guardada = await caches.match(peticion);
            if (guardada) {
                return guardada;
            }
            const respuesta = await fetch(peticion);
            if (respuesta.ok) {
                (await caches.open(CACHE)).put(peticion, respuesta.clone());
            }
            return respuesta;
        })());
    }
});
