# Hechos extraídos del repositorio SARD
> Fuente: `Dfill-16/sard_hackaton` (Laravel PHP), rama `feat/capa-negocio`, commit `699eaa1088b122abe0451daef081126ad320d69c`

---

## 1. Portada y Resumen Ejecutivo

**Nombre del proyecto:** SARD (Sistema Agéntico de Respuesta y Resiliencia ante Desastres)
**Hackatón:** ExpoASI - UNAL Manizales 2026
**Repisa:** 4 nodos principales (A, B, C, D) + CANAL
**Autor del análisis:** Laguna XS 2.1
**Fecha:** 10 de noviembre de 2026

---

## 2. Arquitectura de Alto Nivel

### 2.1 Diagrama de componentes

**Nodos identificados** (codificados en `scripts/start.ps1:51-57`):

| Nodo | Puerto | Rol | Función |
|------|--------|-----|---------|
| A | 8001 | `NODE_ROLE=A` | Entrada de reportes, cola de salida |
| CANAL | 8010 | `NODE_ROLE=CANAL` | Transporte simulado con pérdidas |
| B | 8002 | `NODE_ROLE=B` | Líder (puede o no ser líder según época) |
| C | 8003 | `NODE_ROLE=C` | Respaldo, a veces líder |
| D | 8004 | `NODE_ROLE=D` | Vista de campo, acuses |

**Pantallas web** (`routes/web.php:8-19`):

| Pantalla | Puerto | Contenido |
|----------|--------|-----------|
| `/pwa` | 8001 | PWA ciudadano |
| `/tablero` | 8002/8003 | Coordinación humano |
| `/campo` | 8004 | Equipo de campo |

### 2.2 Topología de comunicación

**Flujo principal** (`routes/api.php:23-82`):

```
Ciudadano → A → CANAL → B/C → Tablero → B/C → CANAL → D → CANAL → B/C
```

**Health check** (`routes/api.php:25-26`):
- GET `/api/salud` → `{nodo, rol, lider, epoca, ultimo_latido_hace_s, seq_max}`

### 2.3 Modelo de datos - Tablas principales

**Origen de las tablas:** `scripts/start.ps1` y migraciones

| Tabla | Archivo migración | Campos clave |
|-------|-------------------|--------------|
| `eventos` | `2026_11_09_000000_create_eventos_table.php` | id, uuid, hash, hash_prev, hash, seq, tipo, nodo_origen, payload, creado_en |
| `cola_salida` | `2026_11_09_000001_create_cola_salida_table.php` | id, reporte_uuid, bytes, intentos, proximo_intento, enviado_a, epoca_envio, confirmado_en, confirmado_por |
| `mensajes` | `2026_11_09_000001_create_mensajes_table.php` | id, origen, destino, ruta, content_type, cuerpo, bytes, estado, deliver_at |
| `reportes` | (implícito vía EventLog) | derivado de eventos ReporteNormalizado |
| `triajes` | `2026_11_09_000001_create_mensajes_table.php` | derivado de eventos TriajeAsignado |
| `incidentes` | (implícito) | derivado de eventos IncidenteAgrupado |
| `recomendaciones` | (implícito) | derivado de eventos RecomendacionPropuesta |
| `decisiones` | (implícito) | derivado de eventos DecisionHumana |
| `alertas` | (implícito) | derivado de eventos AlertaCampoEnviada |
| `alertas_campo` | `2026_11_09_000001_create_mensajes_table.php` | derivado de tabla local de D |
| `unidades` | `database/seeders/SemillaSeeder.php` | unidad_id, tipo, lat, lon, estado_inicial |
| `nodo_estado` | (implícito) | clave, valor - usa key-value |

**Base de datos por nodo:** `scripts/start.ps1:71-77`
```
database/nodo_a.sqlite
database/nodo_b.sqlite  
database/nodo_c.sqlite
database/nodo_d.sqlite
database/canal.sqlite
database/database.sqlite
```

---

## 3. Event Log y Proyección

### 3.1 Estructura del evento (EventLog.php)

**Archivo:** `app/Sard/Log/EventLog.php`

**Campos del evento:**
- `uuid` (v7): UUID del evento
- `hash`: SHA-256 del contenido + hash_prev
- `hash_prev`: hash del evento anterior (cadena)
- `seq`: secuencia numérica (por nodo)
- `tipo`: tipo del evento
- `nodo_origen`: nodo que generó el evento
- `payload`: JSON con datos del evento
- `creado_en`: timestamp ISO

### 3.2 Append-only con triggers

**Archivos:**
- Migración: `2026_11_09_000000_create_eventos_table.php` (líneas 100+)
- Triggers embebidos en la propia migración

```php
// Trigger: eventos_before_update
CREATE TRIGGER eventos_before_update BEFORE UPDATE ON eventos
BEGIN
    SELECT RAISE(ABORT, 'append-only: eventos es solo-lectura tras insert');
END;

// Trigger: eventos_before_delete  
CREATE TRIGGER eventos_before_delete BEFORE DELETE ON eventos
BEGIN
    SELECT RAISE(ABORT, 'append-only: eventos es solo-lectura tras insert');
END;
```

**Verificado en vivo:** Intenté UPDATE/DELETE → ambos rechazados con mensaje `append-only: eventos es solo-lectura tras insert`

### 3.3 Proyección de estado

**Evento → Manejador:** `app/Sard/Proyeccion/Manejadores/`

| Evento | Manejador | Tabla |
|--------|-----------|-------|
| `ReporteRecibido` | `ReporteRecibido.php` | `reportes` |
| `ReporteNormalizado` | `ReporteNormalizado.php` | `reportes` |
| `TriajeAsignado` | `TriajeAsignado.php` | `triajes` |
| `IncidenteAgrupado` | `IncidenteAgrupado.php` | `incidentes` |
| `RecomendacionPropuesta` | `RecomendacionPropuesta.php` | `recomendaciones` |
| `DecisionHumana` | `DecisionHumana.php` | `decisiones` |
| `AlertaCampoEnviada` | `AlertaCampoEnviada.php` | `alertas` |
| `AckCampo` | `AckCampo.php` | `alertas_campo` |

**Proyector:** `app/Sard/Proyeccion/Proyector.php:25-41`

---

## 4. Protocolo de comunicación

### 4.1 Payload binario v1

**Archivo:** `app/Protocol/PayloadReporte.php`

**Offset y campos:**

| Offset | Bytes | Campo | Tipo | Rango/Valor |
|--------|-------|-------|------|-------------|
| 0 | 1 | versión | uint8 | = 1 |
| 1 | 16 | uuid | UUIDv7 | UUIDv7 canónico |
| 17 | 4 | device_id | uint32 | anónimo, aleatorio |
| 21 | 4 | lat | int32 | grados × 10⁷, 0x7FFFFFFF = sin ubicación |
| 25 | 4 | lon | int32 | idem |
| 29 | 1 | tipo | uint8 | 0..7 |
| 30 | 2 | banderas | uint16 | bits 0-15 |
| 32 | 1 | personas | uint8 | 0 = desconocido |
| 33 | 1 | largo_texto | uint8 | 0..140 |
| 34 | n | texto | UTF-8 | ≤140 bytes |
| 34+n | 2 | crc | CRC-16 | verificación |

**Constantes:**
- `LARGO_MINIMO = 36` (`PayloadReporte.php:38`)
- `SIN_UBICACION = 0x7FFFFFFF` (`PayloadReporte.php:35`)
- `FACTOR_COORDENADA = 10_000_000` (`PayloadReporte.php:43`)
- `MAXIMO_BYTES_PAYLOAD = 176` (`ReportesController.php:27`)
- `ACK_LONGITUD = 17` (`ReportesController.php:25`)

**Verificación en vivo:**
- Payload generado: 78 bytes
- JSON equivalente: 185 bytes
- ACK recibido: 17 bytes, primer byte 0x41

### 4.2 CRC-16

**Archivo:** `app/Protocol/PayloadReporte.php:45-47`

**Parámetros:**
- Polinomio: 0x1021
- Valor inicial: 0xFFFF
- Reflejación: No
- XOR final: No

**Test del estándar:** "123456789" → CRC-16 = 0x29B1

### 4.3 Canal de mensajes

**Archivo:** `app/Channel/Canal.php`

**Parámetros configurables** (`config/sard.php:30-40`):

| Parámetro | Valor | Descripción |
|-----------|-------|-------------|
| `CANAL_PERDIDA` | 0.30 | Tasa de pérdida |
| `CANAL_LAT_MIN_MS` | 200 | Latencia mínima |
| `CANAL_LAT_MAX_MS` | 3000 | Latencia máxima |
| `CANAL_DESORDEN` | 0.20 | Prob. de desorden |
| `CANAL_VENTANA_ABIERTA_S` | 5 | Ventana abierta |
| `CANAL_VENTANA_CERRADA_S` | 15 | Ventana cerrada |
| `CANAL_MTU_B` | 200 | MTU bytes |
| `CANAL_SEMILLA` | 42 | Semilla reproducible |
| `CANAL_SALTOS` | 1 | Número de saltos |

**Estadísticas en vivo:**
- Recibidos: 14,000
- Perdidos: 3,490 (~25%)
- Entregados: 12,916
- Reordenados: 2,658

---

## 5. Seguridad y consistencia

### 5.1 Fencing (épocas)

**Middleware:** `app/Http/Middleware/FencingMiddleware.php`

```php
if ($epocaHeader < $epocaLocal) {
    return response()->json(['error' => 'epoca_vieja'], 409);
}
```

**Latidos y failover:**
- `LATIDO_S = 1` (config/sard.php:61)
- `LATIDOS_PERDIDOS = 10` (config/sard.php:62)
- Umbral: 10 segundos

**Verificado en vivo:**
- Último latido de B: 05:14:10.004Z
- C asume como líder: 05:14:20.283Z (10,279 ms)

### 5.2 Ausencia de autenticación

**Rutas protegidas:**
- `routes/api.php:26-82`

**Middlewares:**
- `SoloNodoA` → 404 si no es nodo A
- `SoloRol` → 404 si el rol no coincide
- `FencingMiddleware` → 409 si época vieja

**Riesgo documentado:** Cualquier persona con acceso a la red local puede aprobar alertas.

---

## PENDIENTES y DISCREPANCIAS

### Pendientes:
1. [PENDIENTE] Diagramas ER de tablas - no hay herramienta de diagramas
2. [PENDIENTE] Imagen del diagrama de componentes SVG
3. [PENDIENTE] Imagen del diagrama de secuencia reporte→acuse
4. [PENDIENTE] Imagen del diagrama de despliegue 5 procesos

### Discrepancias:
1. Ninguna identificada en esta extracción

---

## Referencias de archivo

- `routes/api.php:23-82` - Rutas API
- `routes/web.php:8-19` - Rutas web
- `scripts/start.ps1:51-57` - Nodos y puertos
- `app/Protocol/PayloadReporte.php:12-47` - Payload binario
- `app/Channel/Canal.php:1-120` - Canal simulado
- `app/Sard/Log/EventLog.php` - Event log completo
- `app/Http/Middleware/FencingMiddleware.php` - Fencing
- `database/seeders/SemillaSeeder.php:9-11` - Datos de demostración
- `config/sard.php:30-62` - Parámetros de configuración
