# Resultados de escenarios (T-020 · E6 · AC-023a)

Métricas medidas en vivo por `sard:escenario` — nada estimado. La última corrida de cada
escenario reemplaza sus filas; el historial completo queda en los JSON de storage/app/resultados/.

## Métricas medidas ✅

### Compresión de bytes

| Escenario | Semilla | Parámetros | Métrica | Valor | Fecha | Commit |
|---|---|---|---|---|---|---|
| bytes | 42 | n=100 | reportes_medidos | 100 | 2026-11-10T05:00:26.425Z | 699eaa1 |
| bytes | 42 | n=100 | bytes_binario_promedio | 59.2 B | 2026-11-10T05:00:26.425Z | 699eaa1 |
| bytes | 42 | n=100 | bytes_json_promedio | 184.6 B | 2026-11-10T05:00:26.425Z | 699eaa1 |
| bytes | 42 | n=100 | ahorro_pct | **67.9 %** | 2026-11-10T05:00:26.425Z | 699eaa1 |

### Pérdida y reintentos

| Escenario | Semilla | Parámetros | Métrica | Valor | Fecha | Commit |
|---|---|---|---|---|---|---|
| perdida | 42 | n=50/nivel niveles=0..0.6 ventana=180s | perdida_0_confirmados | 50 | 2026-11-10T05:13:02.589Z | 699eaa1 |
| perdida | 42 | n=50/nivel niveles=0..0.6 ventana=180s | perdida_0_confirmado_pct | 100 % | 2026-11-10T05:13:02.589Z | 699eaa1 |
| perdida | 42 | n=50/nivel niveles=0..0.6 ventana=180s | perdida_0_ack_promedio_s | 57.8 s | 2026-11-10T05:13:02.589Z | 699eaa1 |
| perdida | 42 | n=50/nivel niveles=0..0.6 ventana=180s | perdida_0_intentos_promedio | 4.14 | 2026-11-10T05:13:02.589Z | 699eaa1 |

*Los porcentajes > 100 se deben a rezagos del nivel anterior que siguen confirmándose.*

### Rumor masivo

| Escenario | Semilla | Parámetros | Métrica | Valor | Fecha | Commit |
|---|---|---|---|---|---|---|
| rumor-masivo | 42 | n=30 --rumor --masivo | reportes_en_a | 516 | 2026-11-10T05:14:00.449Z | 699eaa1 |
| rumor-masivo | 42 | n=30 --rumor --masivo | incidentes_total | 355 | 2026-11-10T05:14:00.449Z | 699eaa1 |
| rumor-masivo | 42 | n=30 --rumor --masivo | incidentes_masivos | 3 | 2026-11-10T05:14:00.449Z | 699eaa1 |
| rumor-masivo | 42 | n=30 --rumor --masivo | incidentes_masivos_declarados | 3 | 2026-11-10T05:14:00.449Z | 699eaa1 |
| rumor-masivo | 42 | n=30 --rumor --masivo | posibles_rumores | 0 | 2026-11-10T05:14:00.449Z | 699eaa1 |
| rumor-masivo | 42 | n=30 --rumor --masivo | no_elevados | 319 | 2026-11-10T05:14:00.449Z | 699eaa1 |
| rumor-masivo | 42 | n=30 --rumor --masivo | p1 | 2 | 2026-11-10T05:14:00.449Z | 699eaa1 |
| rumor-masivo | 42 | n=30 --rumor --masivo | p2 | 236 | 2026-11-10T05:14:00.449Z | 699eaa1 |
| rumor-masivo | 42 | n=30 --rumor --masivo | p3 | 103 | 2026-11-10T05:14:00.449Z | 699eaa1 |
| rumor-masivo | 42 | n=30 --rumor --masivo | p4 | 14 | 2026-11-10T05:14:00.449Z | 699eaa1 |
| rumor-masivo | 42 | n=30 --rumor --masivo | sin_ubicacion | 53 | 2026-11-10T05:14:00.449Z | 699eaa1 |

### Unión y sincronización

| Escenario | Semilla | Parámetros | Métrica | Valor | Fecha | Commit |
|---|---|---|---|---|---|---|
| union | 42 | reiniciar B | insertados_en_union | 1 | 2026-11-10T06:13:33.183Z | 699eaa1 |
| union | 42 | reiniciar B | ignorados_en_union | 0 | 2026-11-10T06:13:33.183Z | 699eaa1 |
| union | 42 | reiniciar B | eventos_b | 4789 | 2026-11-10T06:13:33.183Z | 699eaa1 |
| union | 42 | reiniciar B | eventos_c | 4789 | 2026-11-10T06:13:33.183Z | 699eaa1 |
| union | 42 | reiniciar B | duplicados_b | 0 | 2026-11-10T06:13:33.183Z | 699eaa1 |
| union | 42 | reiniciar B | duplicados_c | 0 | 2026-11-10T06:13:33.183Z | 699eaa1 |
| union | 42 | reiniciar B | cadena_a_en_b | ok | 2026-11-10T06:13:33.183Z | 699eaa1 |
| union | 42 | reiniciar B | cadena_b_en_b | ok | 2026-11-10T06:13:33.183Z | 699eaa1 |
| union | 42 | reiniciar B | cadena_a_en_c | ok | 2026-11-10T06:13:33.183Z | 699eaa1 |
| union | 42 | reiniciar B | cadena_b_en_c | ok | 2026-11-10T06:13:33.183Z | 699eaa1 |

### Escenarios no ejecutados

| Escenario | Semilla | Parámetros | Estado | Motivo |
|---|---|---|---|---|
| failover | 42 | n=100 + matar B | ❌ No ejecutado | El líder actual es C, no B: no hay failover que medir. |
| estres | 42 | n=1000 dispositivos=50 | ❌ No ejecutado | La cola del Nodo A no se vació en 600 s. |

## Errores observados

Ninguno en la última corrida.

## Limitaciones

1. El hash encadenado prueba integridad y orden del log, no autoría de cada evento (ADR-003: sin firmas por evento; la firma Ed25519 de T-019 cubre la huella del lote).

2. El canal es simulado (proceso propio con pérdida/latencia/desorden por semilla), no una red real.

3. Los reportes son datos sintéticos de demostración (GeneradorSintetico, barrios ficticios).

4. El estrés no se pudo ejecutar plenamente: el límite de 600 s es insuficiente para 1000 reportas con backoffs y reintentos.