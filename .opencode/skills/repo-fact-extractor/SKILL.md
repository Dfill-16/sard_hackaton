---
name: repo-fact-extractor
description: Extrae del código del repo los hechos técnicos (endpoints, esquema SQLite, triggers, layout del payload, CRC, puertos, procesos) con referencia archivo:línea. Úsala antes de redactar cualquier documento de arquitectura.
---
## Reglas
- Todo dato (endpoint, campo, tamaño en bytes, puerto, trigger) debe salir del código y citar `ruta/archivo:línea`.
- Si algo no existe en el repo, márcalo como PENDIENTE; nunca lo inventes.
- Si el código y la documentación existente se contradicen, repórtalo como discrepancia.
## Salida
Escribe `docs/e3/hechos.md` con secciones: Endpoints (tabla S-19), Modelo de datos, Event log y hash chain, Payload v1 (offset, campo, tipo, bytes), Canal (pérdida, latencia, reintentos), CRC-16 (polinomio, init, alcance), Fencing (épocas, latidos), Triggers, Procesos y puertos, Discrepancias.
