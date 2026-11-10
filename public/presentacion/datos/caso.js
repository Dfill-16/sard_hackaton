/*
 * datos/caso.js — datos del caso del derrumbe (S13) y unidades de la semilla.
 *
 * Unidades: copia literal de database/seeders/SemillaSeeder.php (datos ficticios de demo).
 * Punto del derrumbe: PUNTO DE DEMOSTRACIÓN elegido para la presentación (sector Milán); el
 * repositorio no trae un caso fijo (ReportesDemoSeeder usa una semilla aleatoria).
 * La distancia se calcula aquí con la misma fórmula y el mismo radio que A6
 * (Vecindad::haversine, RADIO_TIERRA_M = 6 371 000) y el mismo orden: distancia ASC, id ASC.
 */
(function (SARD) {
    'use strict';

    const RADIO_TIERRA_M = 6371000;
    const rad = (g) => (g * Math.PI) / 180;

    /** Vecindad::haversine en JavaScript. */
    function haversine(lat1, lon1, lat2, lon2) {
        const dLat = rad(lat2 - lat1);
        const dLon = rad(lon2 - lon1);
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(dLon / 2) ** 2;
        return 2 * RADIO_TIERRA_M * Math.asin(Math.min(1, Math.sqrt(h)));
    }

    const unidades = [
        { id: 'U-01', tipo: 'ambulancia', nombre: 'Ambulancia Alfa', base: 'Centro', lat: 5.0688, lon: -75.5174, estado: 'disponible' },
        { id: 'U-02', tipo: 'ambulancia', nombre: 'Ambulancia Bravo', base: 'Palogrande', lat: 5.0560, lon: -75.4925, estado: 'disponible' },
        { id: 'U-03', tipo: 'ambulancia', nombre: 'Ambulancia Charlie', base: 'La Enea', lat: 5.0395, lon: -75.4670, estado: 'ocupada' },
        { id: 'U-04', tipo: 'ambulancia', nombre: 'Ambulancia Delta', base: 'Villamaría', lat: 5.0455, lon: -75.5160, estado: 'disponible' },
        { id: 'U-05', tipo: 'bomberos', nombre: 'Bomberos Estación 1', base: 'Centro', lat: 5.0695, lon: -75.5160, estado: 'disponible' },
        { id: 'U-06', tipo: 'bomberos', nombre: 'Bomberos Estación 2', base: 'Milán', lat: 5.0485, lon: -75.4830, estado: 'disponible' },
        { id: 'U-07', tipo: 'bomberos', nombre: 'Bomberos Villamaría', base: 'Villamaría', lat: 5.0440, lon: -75.5140, estado: 'disponible' },
    ];

    const caso = {
        es_demo: true,
        lat: 5.0505,
        lon: -75.4860,
        tipoUnidad: 'bomberos', // derrumbe con persona atrapada → bomberos (A6: banderas atrapada/derrumbe)
        dispositivos: 3,
        incertidumbre: 0,
    };

    /** A6 · la unidad disponible más cercana del tipo requerido (distancia ASC, id ASC). */
    function recomendar(punto = caso) {
        return unidades
            .filter((u) => u.tipo === punto.tipoUnidad && u.estado === 'disponible')
            .map((u) => ({ ...u, distancia_m: Math.round(haversine(punto.lat, punto.lon, u.lat, u.lon)) }))
            .sort((a, b) => a.distancia_m - b.distancia_m || a.id.localeCompare(b.id));
    }

    /** RecalculoIncidente::confianza: round((1 − prom(incertidumbre)) × min(1, 0.4 + 0.15 × n), 2). */
    function confianza(promedioIncertidumbre, dispositivos) {
        return Math.round((1 - promedioIncertidumbre) * Math.min(1, 0.4 + 0.15 * dispositivos) * 100) / 100;
    }

    /** Proyección local (m) de una coordenada respecto del punto del caso: este (+x), sur (+z). */
    function enMetros(lat, lon, origen = caso) {
        const norte = haversine(origen.lat, origen.lon, lat, origen.lon) * Math.sign(lat - origen.lat);
        const este = haversine(origen.lat, origen.lon, origen.lat, lon) * Math.sign(lon - origen.lon);
        return { x: este, z: -norte };
    }

    SARD.datos = { unidades, caso, haversine, recomendar, confianza, enMetros };
})(window.SARD = window.SARD || {});
