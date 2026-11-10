# ASSETS_STATUS.md — estado de los assets

Leyenda: ✅ listo · 🧱 procedural (definitivo: no habrá archivo) · ⏳ pendiente (hito) · — no se usa.
No hay modelos GLB ni texturas de archivo por diseño (ASSETS.md §4): los objetos 3D son
geometría procedural de Three.js con el mismo nombre y escala previstos.

## P0 · Bloqueantes

| # | Asset | Ruta | Estado |
|---|---|---|---|
| 1 | Three.js r170 + OrbitControls (IIFE) | `vendor/three.iife.min.js` | ✅ 706 KB |
| 2 | GSAP 3.13.0 | `vendor/gsap.min.js` | ✅ 72 KB |
| 3 | ScrollTrigger | `vendor/ScrollTrigger.min.js` | ✅ 44 KB |
| 3b | DrawSVGPlugin / SplitText | `vendor/DrawSVGPlugin.min.js`, `vendor/SplitText.min.js` | ✅ 4 KB / 7 KB |
| 5–8 | Instrument Sans 400/500/600/700 | `fonts/*.woff2` | ✅ 68 KB en total |
| — | Licencia OFL | `fonts/OFL.txt` | ✅ |
| 9 | Logo real | `icons/sard.svg` (copia literal de `public/icons/sard.svg`) | ✅ |
| 10 | `favicon.ico` | — | — se usa `sard.svg` |

## P1 · Iconografía

| Asset | Ruta | Estado |
|---|---|---|
| 20 iconos de la PWA + 14 del tablero (trazo 1,75 px, 24×24) | `js/core/iconos.js` (sprite inline) | ✅ copiados literalmente de los partials |
| `radio`, `gateway`, `sol-panel`, `reloj-10`, `faro` (nuevos) | `js/core/iconos.js` | ✅ mismo lenguaje de trazo |

> Desviación: el sprite va inline desde JS y no como `icons/sprite.svg`, porque
> `<use href="archivo.svg#id">` externo no carga desde `file://` en Chromium.

## P2–P4 · Escenario 3D (procedural)

| # | Objeto | Estado |
|---|---|---|
| 46–51 | Faro, linterna, halo (2 sprites), haz (cono aditivo, solo calidad 0), baliza | 🧱 ✅ `js/three/faro.js` |
| 50 | Torre gemela (fábrica `Faro.crearGemela()`) | 🧱 ✅ fábrica · se instancia en S09/S10 (H3) |
| 52–53 | Roca basáltica, relieve de Manizales (120×120, 64², 3 octavas, semilla 42) + mar con oleaje | 🧱 ✅ `js/three/terreno.js` |
| 59 | Partículas de niebla (520 en calidad 0) | 🧱 ✅ `js/three/niebla.js` |
| 53b | Ciudad: 150 plataformas escalonadas (`InstancedMesh`, 1 draw call) | 🧱 ✅ S01 |
| 54 | Cauce (`TubeGeometry` sobre Catmull-Rom) + luz verde-agua | 🧱 ✅ S01 |
| 60 | Boyas de incidente (esfera + anillo + halo) | 🧱 ✅ S01 (se reutilizan en S13) |
| — | Cadena de 6 nodos + 5 cables | 🧱 ✅ S02 |
| 62 | Silueta del operador (cápsula + esfera, sin rostro) + atril con pantalla | 🧱 ✅ `js/three/actores.js` · S03 (se reutiliza en S12, S13) |
| — | 5 placas de principios alrededor de la base | 🧱 ✅ S04 (sin icono en relieve: el icono va en la tarjeta HTML) |
| 113 | Diagrama SVG de 7 pasos (`viewBox 0 0 1200 220`, trazo 1,75) + lista textual equivalente | ✅ S05 |
| — | Ciudadano (punto #8fb8d8 + halo), paquete y ACK (sprites) | 🧱 ✅ S06 |
| 123 | Réplica de la PWA (HTML con los literales reales de PwaController / pwa/index.blade.php / pwa/app.js) | ✅ S06 |
| 56–58 | Anillo del canal, 3 repetidores ámbar, pool de 14 partículas de mensaje, emisor y destino | 🧱 ✅ S07 |
| — | 4 estaciones (rejilla, barras P1–P4, convergencia, vector + barrera) y punto viajero | 🧱 ✅ S08 |
| 50 | Torre gemela (nodo C) instanciada | 🧱 ✅ S09, S10 |
| — | Nodo A (cubo + anillo), anillo del CANAL, 5 líneas `TubeGeometry`, pulsos de latido/réplica/reporte | 🧱 ✅ S09 (`actores.js`) |
| 63 | Vehículo de campo (nodo D) | 🧱 ✅ `actores.js` · S09 (se reutiliza en S13) |
| — | Pilas de log de B y C, anillo de época | 🧱 ✅ S10 |
| 61 | Cilindro del log (12 capas con muesca) | 🧱 ✅ S11 |
| 122 | Tabla del rastro en el log (`<table>` con `<caption>` y `<th scope>`) | ✅ S11 (valores de muestra rotulados) |
| — | Autómata IA (esfera + 2 anillos), barrera de luz, señal | 🧱 ✅ S12 |
| 64 | Punto de derrumbe (6 icosaedros) + anillo P1, 3 boyas de reporte, vehículo en su posición relativa real | 🧱 ✅ S13 |
| 92, 95 | Unidades de la semilla + caso del derrumbe (haversine y confianza como A6) | ✅ `datos/caso.js` → Bomberos Estación 2 · 400 m |
| 55, 65–66 | Zonas, estaciones de radio, rejilla de suelo | 🧱 ⏳ H3f |
| 67–69 | Texturas `CanvasTexture`: halo, partícula, rejilla | 🧱 ✅ `js/three/materiales.js` |
| 70–72 | Texturas: tira de bytes, capa del log, embudo | 🧱 ⏳ H3 |
| 73–90 | Materiales cacheados (4 de ≤ 18 en uso en el escenario base) | 🧱 ✅ caché en `M3.mat()` |

Escenario base medido (1440×900, calidad 0): **11 draw calls · 11 144 triángulos** (presupuesto: ≤ 120 · ≤ 60 000).

## Datos y contenido

| Asset | Ruta | Estado |
|---|---|---|
| Textos de las 17 escenas + UI | `content/es.js` | ✅ (literales de la PWA a contrastar en H3) |
| Unidades y recursos (SemillaSeeder) | `datos/unidades.js` | ⏳ H3 (S13, S15) |
| Catálogos (Banderas.php) | `datos/catalogos.js` | ⏳ H3 (S06, S08) |
| Parámetros del canal (config/sard.php) | `datos/canal.js` | ⏳ H3 (S07) |
| Caso del derrumbe (punto de demostración) | `datos/caso-derrumbe.js` | ⏳ H3 (S13) |

## Audio (WebAudio, sin archivos)

| Sonido | Estado |
|---|---|
| Mar, viento, latido, envío, ACK, pérdida, alerta, época, clic | 🧱 ⏳ H4 |
