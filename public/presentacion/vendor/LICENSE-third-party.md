# Licencias de terceros

Todos los archivos se instalaron una sola vez con npm en una carpeta temporal
(`%TEMP%\sard-vendor`), sin tocar el `package.json` del proyecto. Versiones exactas en
`VERSIONES.txt`.

| Archivo | Paquete | Versión | Licencia |
|---|---|---|---|
| `three.iife.min.js` | `three` (núcleo + `examples/jsm/controls/OrbitControls.js`), empaquetado IIFE con `esbuild@0.24.0` | r170 (`0.170.0`) | MIT — texto completo en `LICENSE-three.txt` |
| `gsap.min.js` | `gsap` | 3.13.0 | GSAP Standard "No Charge" License — gsap.com/standard-license |
| `ScrollTrigger.min.js` | `gsap` | 3.13.0 | Ídem |
| `DrawSVGPlugin.min.js` | `gsap` | 3.13.0 | Ídem (incluido sin costo desde 3.13) |
| `SplitText.min.js` | `gsap` | 3.13.0 | Ídem (incluido sin costo desde 3.13) |
| `../fonts/InstrumentSans-{400,500,600,700}.woff2` | `@fontsource/instrument-sans` (subconjunto latin) | 5.3.0 | SIL Open Font License 1.1 — `../fonts/OFL.txt` |

## Cómo se generó `three.iife.min.js`

```js
// entrada-three.js
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
window.THREE = Object.assign({ OrbitControls }, THREE);
```

```powershell
npx esbuild entrada-three.js --bundle --minify --format=iife --target=es2020 --outfile=three.iife.min.js
```

Motivo: desde r160 Three.js no publica un build UMD y `OrbitControls` solo existe como módulo
ES; los módulos ES no cargan de forma fiable desde `file://` (TECH_PLAN §9.3).
