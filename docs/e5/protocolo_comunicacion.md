# E5 - Protocolo de comunicación (AC-022a)

**SARD — Sistema Agéntico de Respuesta y Resiliencia ante Desastres**

---

## 1. Introducción

Este documento describe el **protocolo de comunicación** que conecta los 5 procesos del sistema SARD:

- **Nodo A** (PWA ciudadana → Ingesta)
- **CANAL** (Transporte simulado con pérdida)
- **Nodos B y C** (Líder y seguidor)
- **Nodo D** (Vista de campo)

---

## 2. Resumen de los 6 puntos requeridos (AC-022a)

| # | Requisito | Detalle |
|---|-----------|---------|
| 1 | device_id de 4 bytes | Payload binario v1: offset 17-20 |
| 2 | ACK binario | 1 B tipo (0x41) + 16 B UUID = 17 B |
| 3 | Reintentos con backoff | Serie 1, 2, 4, 8, 16, 30 s + jitter ±20% |
| 4 | Dedup por UUID | Idempotencia: mismo UUID = mismo reporte |
| 5 | Sync entre nodos | Réplica por UUID vía eventos append-only |
| 6 | Prioridad por puntaje | A4 ordena por puntaje_efectivo |

---

## 3. Payload binario v1 (device_id)

### Formato del payload

| Offset | Bytes | Campo | Tipo | Rango/Valor |
|--------|-------|-------|------|-------------|
| 0 | 1 | versión | uint8 | = 1 |
| 1 | 16 | uuid | UUIDv7 | UUIDv7 canónico |
| 17 | 4 | device_id | uint32 | **Anónimo, aleatorio (4 bytes)** |
| 21 | 4 | lat | int32 | grados × 10⁷, 0x7FFFFFFF = sin ubicación |
| 25 | 4 | lon | int32 | idem |
| 29 | 1 | tipo | uint8 | 0..7 |
| 30 | 2 | banderas | uint16 | bits 0-15 |
| 32 | 1 | personas | uint8 | 0 = desconocido |
| 33 | 1 | largo_texto | uint8 | 0..140 |
| 34 | n | texto | UTF-8 | ≤140 bytes |
| 34+n | 2 | crc | CRC-16 | verificación |

**Total mínimo:** 36 bytes (sin texto)
**Total máximo:** 176 bytes

### Verificación en vivo

- Payload generado: **78 bytes**
- JSON equivalente: **185 bytes**
- **Ahorro: 67.9%**

---

## 4. ACK binario (canal de vuelta)

### Formato del ACK

```
1 B tipo (0x41) + 16 B uuid = 17 bytes
```

### Reglas

- Si CRC inválido → se descarta el paquete **sin ACK**
- Si ACK recibido → el cliente borra el reporte de IndexedDB
- Timeout de ACK: se reintenta con backoff exponencial

---

## 5. Reintentos con backoff

### Configuración

```php
serie = [1, 2, 4, 8, 16, 30]  // segundos
jitter = ±20% aleatorio
```

### Lógica

1. Sin ACK → reintento con backoff
2. Jitter evita thundering herd
3. Sin límite de intentos (hasta ACK válido)

---

## 6. Deduplicación por UUID

### idempotencia

- Mismo UUID (cliente) → mismo UUID (servidor)
- UUIDv7 ordenable por tiempo
- Insertar un evento con UUID existente **no cambia nada**

### Escenarios

| Escenario | Acción |
|-----------|--------|
| Mismo reporte, mismo UUID | Se actualiza el mismo UUID (PWA) |
| Mismo UUID ya recibido | ACK de nuevo, sin insertar |
| Reenvío por pérdida | Mismo comportamiento (idempotente) |

---

## 7. Sincronización (Sync) entre nodos

### Mecanismo de réplica

1. **Líder (B o C)** escribe eventos a su EventLog local
2. **Follower** recibe réplicas vía `POST /api/eventos/lote`
3. **Unión idempotente** con `INSERT OR IGNORE` por UUID

### Protocolo de unión

```
B caído → C asume (época + 1)
B vuelve como follower:
  1. POST /api/eventos/lote con eventos diferentes
  2. C responde {insertados: N, ignorados: M}
  3. B se une sin duplicar
```

### Verificación

- La tabla `eventos` es **append-only** (trigger)
- No se puede vaciar dentro de transacción
- La unión es idempotente: si el nodo ya tiene los eventos, se ignoran

---

## 8. Prioridad por puntaje (A4)

### Orden de salida

A4 ordena los reportes pendientes de ACK usando **puntaje_efectivo**:

```
prioridad = puntaje_base + minutos_en_cola + tamaño_bytes
```

### Ordenamiento

1. **puntaje_efectivo** (descendente) - primero los más urgentes
2. **bytes** (ascendente) - primero los más pequeños  
3. **UUID** (ascendente) - desempate

### Reglas especiales

- **Puntaje bajo nunca se descarta**: solo se pospone en la cola
- **Máximo LOTE = 10** reportes por ciclo de envío
- **Backoff máximo = 30 s** con jitter

### Recálculo tras failover

```
A4 recalcula puntaje_efectivo tras:
- Recibir ACK fallido
- Cambio de líder (época mayor)
```

---

## 9. API completa por nodo

### Nodo A :8001

| Ruta | Método | Entrada | Salida |
|------|--------|---------|--------|
| `/api/reportes` | POST | payload binario v1 | 200: ACK 17B |
| `/api/acks` | POST | {reporte_uuid, nodo, epoca} | 204 |
| `/api/lider` | POST | {nodo, epoca} | 204/409 |
| `/api/salud` | GET | — | {nodo, rol, lider, época, cola_pendiente} |
| `/api/metricas` | GET | — | métricas de bytes |
| `/pwa` | GET | — | PWA ciudadana |

### CANAL :8010

| Ruta | Método | Entrada | Salida |
|------|--------|---------|--------|
| `/api/canal/enviar` | POST | {origen, destino, ruta, cuerpo_b64} | 202: mensaje_id |
| `/api/canal/estadisticas` | GET | — | {recibidos, perdidos, entregados} |
| `/api/canal/config` | POST | {perdida?, ventana?} | 204 |

### Nodos B/C :8002-8003

| Ruta | Método | Entrada | Salida |
|------|--------|---------|--------|
| `/api/entrega/reporte` | POST | payload binario | 200/409 |
| `/api/latido` | POST | {nodo, época, seq_max} | 204 |
| `/api/lider` | GET | — | {nodo, época} |
| `/api/eventos` | GET | desde, límite | eventos[] |
| `/api/eventos/lote` | POST | {eventos[]} | {insertados, ignorados} |
| `/api/decisiones` | POST | {recomendacion_uuid, accion} | 201: decision_uuid |
| `/api/demo/aislar` | POST | {on: bool} | 204 |

### Nodo D :8004

| Ruta | Método | Entrada | Salida |
|------|--------|---------|--------|
| `/campo` | GET | — | vista de campo |
| `/api/entrega/alerta` | POST | {alerta_uuid, unidad_id} | 204 |
| `/api/campo/ack` | POST | {alerta_uuid} | 202 |

---

## 10. Cabeceras y fencing

### Cabeceras HTTP

- `X-Sard-Nodo`: A, B, C, D, CANAL
- `X-Sard-Epoca`: número de época

### Fencing

```php
if ($epocaHeader < $epocaLocal) {
    return response()->json(['error' => 'epoca_vieja'], 409);
}
```

- Un nodo con época menor **no puede escribir** como líder
- Evita conflictos de escritura

---

## 11. Parámetros configurables

| Parámetro | Valor | Uso |
|-----------|-------|-----|
| CANAL_PERDIDA | 0.30 | 30% de pérdida simulada |
| CANAL_LAT_MIN_MS | 200 | latencia mínima |
| CANAL_LAT_MAX_MS | 3000 | latencia máxima |
| CANAL_DESORDEN | 0.20 | prob. de desorden |
| LATIDO_S | 1 | segundos entre latidos |
| LATIDOS_PERDIDOS | 10 | umbral de caída |

---

## 12. Verificación en vivo

- **device_id**: 4 bytes aleatorio por instalación
- **ACK**: recibido vía canal → reporte borrado de IndexedDB
- **Reintentos**: 100% con ACK → 0 pérdidas conectadas
- **Dedup**: mismo UUID → 1 solo registro
- **Sync**: union con 0 duplicados, cadena verificada
- **Prioridad**: 632/1617 reportes confirmados tras estrés del canal

---

## Referencias

- `app/Protocol/PayloadReporte.php` - Codificación/decodificación
- `app/Channel/Canal.php` - Simulación de canal
- `app/Agents/A4/Comunicacion.php` - Orden de salida
- `app/Sard/Log/EventLog.php` - Append-only con triggers
- `specs/contratos/api.md` - Endpoints API
- `specs/contratos/payload-reporte.md` - Formato binario