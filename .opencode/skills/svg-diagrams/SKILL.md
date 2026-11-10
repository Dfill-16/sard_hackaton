---
name: svg-diagrams
description: Dibuja diagramas técnicos como SVG inline escrito a mano (componentes, capas, despliegue, secuencia, layout binario, línea de tiempo) para documentos impresos. Sin dependencias externas.
---
## Reglas visuales
- viewBox fijo, ancho máximo 170 mm para A4. Fuente sans-serif, mínimo 9 pt equivalente.
- Paleta fija de 5 colores: azul (nodos), verde (datos/OK), ámbar (canal/riesgo), rojo (fallo), gris (fondo/ejes). Nunca depender solo del color: añadir etiquetas.
- Máximo ~12 cajas por diagrama; si hay más, dividir en dos figuras.
- Flechas con punta (marker), etiquetas sobre la línea con fondo blanco, sin cruces innecesarios.
- Cada figura: título "Figura N. ..." y leyenda breve.
## Diagramas requeridos
1. Componentes (5 nodos, PWA, tablero, campo).
2. Topología (B↔C vía canal con pérdida/latencia).
3. Capas: ciudadano → nodo A → canal → líder → tablero → campo.
4. Secuencia reporte→acuse (incluye pérdida y reintento).
5. Despliegue: 5 procesos y puertos.
6. Payload v1 como barra de bytes proporcional con offsets, tamaño total y zona cubierta por CRC-16.
7. Hash chain del event log y trigger que bloquea UPDATE/DELETE.
8. Línea de tiempo de fencing (épocas, latidos, takeover).
