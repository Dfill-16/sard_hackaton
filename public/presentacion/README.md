# SARD GAMMA TECH · El Faro — presentación

Sitio estático, 100 % offline. **No se versiona** (`/public/presentacion/` está en `.gitignore`).

## Abrir

- Doble clic en `index.html` (`file://`), o
- con el nodo A levantado: `http://127.0.0.1:8001/presentacion/index.html`
  (el servidor de Laravel no sirve el índice del directorio: hay que incluir `index.html`).

Para abrir directamente una escena (útil al ensayar): `index.html#S07_canal`.

Parámetros de prueba:

- `index.html?depurar` — panel con FPS, nivel de calidad, draw calls, triángulos, memoria GPU y tweens vivos.
- `index.html?sin3d` — fuerza el modo texto (el mismo fallback que sin WebGL); no descarga Three.js.

La calidad gráfica se puede fijar en el índice (`Esc`): Auto / Alta / Media / Baja. En Auto baja
sola si la máquina no sostiene 45 FPS durante dos ventanas seguidas de 60 frames.

## Teclas

| Tecla | Acción |
|---|---|
| `→` `Espacio` `Av Pág` | Siguiente (o siguiente paso dentro de la escena) |
| `←` `Re Pág` | Anterior |
| `Inicio` / `Fin` | Apertura / cierre |
| `1`…`8` | Capítulos 0 a 7 |
| `Esc` `M` | Índice |
| `D` | Panel de la demo en vivo |
| `P` | Pausar / reanudar animación |
| `H` | Ocultar / mostrar botones |
| `F` | Pantalla completa |
| `R` | Reiniciar |
| `?` | Atajos y registro de errores |

## Tiempo

17 min de presentación + 8 min de demo en vivo = 25 min. En `S16_cierre`, `→` abre el
panel de la demo (URLs de los 4 nodos con vista y comandos para copiar).

## Estructura

Scripts clásicos (no módulos ES) en orden de dependencia, espacio de nombres `window.SARD`.
Textos en `content/es.js`; ningún texto en la lógica. Versiones y licencias en
`vendor/LICENSE-third-party.md`. Estado de los assets en `ASSETS_STATUS.md`.
