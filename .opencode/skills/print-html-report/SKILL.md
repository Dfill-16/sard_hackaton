---
name: print-html-report
description: Maqueta un documento técnico en un único HTML autocontenido listo para imprimir a PDF desde el navegador (A4, portada, páginas, tablas, figuras).
---
## Reglas
- Un solo `docs/e3/index.html`, CSS embebido, sin CDN ni fuentes remotas (usar fuentes del sistema).
- Usar `@page` con size A4 y margin 0; cada hoja es `<section class="page">` de 210x297 mm con padding interno y `break-after: page`.
- Portada a página completa; índice manual con números de página reales; pie con proyecto, autor y número de página en cada hoja.
- `break-inside: avoid` en figuras, tablas y callouts; títulos con `break-after: avoid`.
- Activar `-webkit-print-color-adjust: exact` y `print-color-adjust: exact`.
- Tablas con encabezado fijo, cebra suave, código en monoespaciada. Callouts para decisiones y riesgos.
- Regla de contenido: ninguna página de especificación solo con texto; lleva tabla o figura.
- Si cambia el orden de páginas, renumerar índice y pies.
