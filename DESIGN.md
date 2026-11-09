# DESIGN — Sard System Design

System design de Sard. Define **tokens, componentes y reglas de maquetado** que aplican a
cualquier vista. No prescribe qué vistas existen ni cómo funciona la app; solo establece
el lenguaje visual común para que nuevas pantallas se construyan sobre él de forma
coherente.

---

## 1. Principios

- **Claridad técnica.** UI de infraestructura: datos precisos, monospace para identificadores
  y hashes, estados legibles de un vistazo.
- **Tema claro/oscuro de primera clase.** Ambas variantes igual de pulidas.
- **Español.** Copy de interfaz en español, directo y sin tecnicismos innecesarios.
- **Componible.** Cada componente es independiente; se combina libremente en cualquier vista.
- **Realista.** Diseñado para Tailwind v4 (ya presente en `resources/css/app.css`) y Alpine.js
  (dependencia ya instalada). Nada que requiera librerías nuevas.

---

## 2. Tokens (en `resources/css/app.css`, dentro de `@theme`)

Los tokens son semánticos. Las vistas los usan por nombre, no por valor hex, para que un
cambio de tema se refleje en toda la app.

### Color

| Token | Uso | Light | Dark |
|-------|-----|-------|------|
| `--color-background` | fondo de página | `#FDFDFC` | `#0a0a0a` |
| `--color-foreground` | texto principal | `#1b1b18` | `#EDEDEC` |
| `--color-muted` | texto secundario | `#706f6c` | `#A1A09A` |
| `--color-surface` | fondo de tarjetas/paneles | `#ffffff` | `#161615` |
| `--color-border` | bordes sutiles | `#19140035` | `#3E3E3A` |
| `--color-border-hover` | bordes en hover/focus | `#1915014a` | `#62605b` |
| `--color-accent` | acción primaria, líder | `#f53003` | `#FF4433` |
| `--color-accent-hover` | acción primaria hover | `#c42300` | `#F61500` |
| `--color-status-healthy` | OK / saludable | `#15803d` | `#4ade80` |
| `--color-status-degraded` | degradado / advertencia | `#d97706` | `#fbbf24` |
| `--color-status-down` | caído / error | `#dc2626` | `#ef4444` |
| `--color-node-leader` | resaltar líder | `#f53003` | `#FF750F` |
| `--color-node-follower` | seguidor neutro | `#1b1b18` | `#EDEDEC` |

### Tipografía

- `--font-sand`: `Instrument Sans` (ya definido en `app.css`; mantener).
- `--font-mono`: `ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace` — para
  UUIDs, hashes, seq, épocas, timestamps.
- Escala (`rem`): `xs 0.75`, `sm 0.875`, `base 1`, `lg 1.125`, `xl 1.25`, `2xl 1.5`,
  `3xl 1.875`.
- Pesos: `400` body, `500` etiquetas/títulos, `600` valores numéricos, `700` IDs de nodo.

### Espaciado (4px grid)

`space-1` = 4px. Usar `p-2/p-3/p-4` en tarjetas, `gap-2/gap-3` en listas, `px-3 py-2` en
tablas densas.

### Radio y sombras

- `rounded-sm` — botones, badges.
- `rounded-lg` — tarjetas, paneles.
- Sombra de borde (reutiliza la del welcome): `shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)]`
  light / `dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d]` dark.

---

## 3. Componentes

Reutilizar antes de crear uno nuevo. Todos viven en `resources/views/partials/` como
partials de Blade y se invocan con `@include`.

### Botón
- **Primario:** `bg-accent text-white hover:bg-accent-hover rounded-sm px-5 py-1.5 text-sm font-medium`
- **Secundario:** `border border-border text-foreground hover:border-border-hover rounded-sm px-5 py-1.5 text-sm`

### Badge de estado / rol
- Contenedor: `inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-[10px] font-medium`
- Estados: `healthy` (bg-healthy/10 text-healthy), `degraded` (bg-degraded/10 text-degraded),
  `down` (bg-down/10 text-down).
- Rol: punto `w-2 h-2 rounded-full` — líder = accent, follower = muted.

### Tarjeta (card)
- `bg-surface border border-border rounded-lg p-4` con shadow de borde.
- Variante líder: `border-node-leader` + línea superior de acento.

### Métrica
- Label `text-[10px] text-muted uppercase tracking-wider`, valor `text-lg font-semibold font-mono`,
  delta opcional `text-xs text-muted`.

### Tabla
- `text-[13px]`, `border-b border-border`, `px-3 py-2`, alineación izquierda.
- UUID/hash/seq en `font-mono text-muted`; celdas largas truncadas con `title`.

### Barra superior / navegación
- Flex, justify-between, `border-b border-border`, `pb-4 mb-6`. Marca a la izquierda,
  acciones (botones / chip de conexión) a la derecha.

### Chip de conexión
- `inline-flex items-center gap-2 px-3 py-1 rounded-full border border-border bg-surface text-sm`
  con punto de estado (`w-2 h-2 rounded-full`, color healthy/degraded/down).

---

## 4. Primitivas de maquetado (vistas agnósticas)

Estas clases se aplican en cualquier vista; no dictan estructura de navegación:

- **Stack vertical:** `flex flex-col gap-4`
- **Grid de tarjetas:** `grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-3`
- **Dos columnas:** `grid grid-cols-1 lg:grid-cols-2 gap-6`
- **Contenido centrado (página de aterrizaje):** `max-w-[335px] lg:max-w-4xl mx-auto items-center`
- **Panel lateral opcional:** `hidden lg:block w-64` cuando la vista lo justifique; no es obligatorio.

---

## 5. Dark mode

Estrategia `class` de Tailwind (clase `dark` en `<html>`). Cada token tiene su par dark;
los componentes usan `dark:` para bordes, fondos y texto. Ambas variantes reciben el mismo
acento (`#f53003` / `#FF4433`); solo invierten superficie, borde y texto.

---

## 6. Copy (español)

Tono corto, infraestructura, directo. Ejemplos de estado y rol:

- Salud: `saludable`, `degradado`, `caído`.
- Rol: `líder`, `seguidor`.
- Datos: `último latido`, `época`, `seq`, `uuid`.
- Acciones: `ingresar evento`, `ver todo`, `conectado`, `desconectado`.

---

## 7. Cómo usar en una vista nueva

1. Escribe el Blade en `resources/views/` usando solo tokens y componentes de §2 y §3.
2. Usa clases de maquetado de §4; no asumas sidebar, topbar fija ni número de columnas.
3. Para datos técnicos (UUID, hash, seq), aplica `font-mono`.
4. Para estados de nodo, usa el badge de §3 con los colores de estado.
5. Añade `dark:` variantes para cada color de fondo/borde/texto.

Si una vista necesita un componente que no existe, agrégalo a `resources/views/partials/`
y documenta aquí su token/clase antes de usarlo en otra vista.
