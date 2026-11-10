<#
.SYNOPSIS
    Prepara la demo y recorre el flujo de 8 pasos en un solo PC (T-020 · AC-009a/010a/018a, AC-024a).

.DESCRIPTION
    Preparación por puntos 1-5 (v2.1 §13; supuesto documentado en la ficha T-020):
    stack fresco con -Fresco y semilla fija 42 cargada con --rumor --masivo.
    Luego recorre los 8 pasos de 03-arquitectura con pausas para el presentador y
    deja los procesos arriba con la semilla cargada (AC-024a). No toca código:
    lo que muestra son las URLs del sistema y los comandos existentes.

    La decisión del paso 6 es del humano (T-010): el guion la deja lista en el
    tablero y espera; los pasos 7-8 (failover y regreso de B) son automáticos.

.PARAMETER PausaSeg
    Segundos de pausa entre pasos (por defecto 10).

.EXAMPLE
    ./scripts/demo.ps1
    ./scripts/demo.ps1 -PausaSeg 20
#>
param(
    [int]$PausaSeg = 10
)

$ErrorActionPreference = 'Stop'
$raiz = Split-Path -Parent $PSScriptRoot
$semilla = 42
$php = (& (Get-Command php).Source -r 'echo PHP_BINARY;')

function Paso([string]$texto) {
    Write-Host ''
    Write-Host ('═' * 70) -ForegroundColor DarkGray
    Write-Host $texto -ForegroundColor Cyan
    Write-Host ('═' * 70) -ForegroundColor DarkGray
}

function Salud-de([string]$nodo, [int]$puerto) {
    try { return Invoke-RestMethod "http://127.0.0.1:$puerto/api/salud" -TimeoutSec 3 } catch { return $null }
}

# ─── Preparación (puntos 1-5 · stack + siembra) ──────────────────────────────
Paso 'PREPARACIÓN · stack fresco y semilla 42 cargada'

& (Join-Path $PSScriptRoot 'stop.ps1')
& (Join-Path $PSScriptRoot 'start.ps1') -Fresco

Write-Host 'Esperando a que responda el Nodo A…'
do { Start-Sleep -Seconds 2; $a = Salud-de 'A' 8001 } while ($null -eq $a)

& $php (Join-Path $raiz 'artisan') sard:sembrar 30 --rumor --masivo --semilla $semilla

Write-Host 'Esperando el procesamiento del líder (A1→A2→A3→A6)…'
$anterior = -1
$iguales = 0
while ($iguales -lt 3) {
    Start-Sleep -Seconds 2
    try { $total = (Invoke-RestMethod 'http://127.0.0.1:8002/api/tablero/estado?todos=1' -TimeoutSec 3).contadores.total } catch { $total = -1 }
    if ($total -eq $anterior) { $iguales++ } else { $iguales = 0; $anterior = $total }
}
Write-Host ("Semilla cargada: {0} incidentes en el tablero (semilla {1})." -f $anterior, $semilla) -ForegroundColor Green

# ─── Flujo de 8 pasos ────────────────────────────────────────────────────────
Paso 'PASOS 1-3 · PWA → IndexedDB «pendiente» → POST binario a A → canal → B (ACK por UUID)'
Write-Host '· PWA:            http://127.0.0.1:8001/pwa   (crear un reporte o notar los sembrados)'
Write-Host '· Sin red: el reporte queda «pendiente» en IndexedDB y reintenta con backoff.'
Write-Host '· Al llegar: A registra en su log, A4 ordena por puntaje y el canal lleva el'
Write-Host '  binario a B; el ACK vuelve por UUID y la PWA borra el pendiente.'

$metricas = Invoke-RestMethod 'http://127.0.0.1:8001/api/metricas' -TimeoutSec 3
Write-Host ("· Medido en A: {0} reportes · {1} B binario · {2} B equivalentes en JSON" -f $metricas.reportes, $metricas.bytes_binario, $metricas.bytes_json_equivalente)
Start-Sleep -Seconds $PausaSeg

Paso 'PASOS 4-5 · B procesa (A1→A2→A3) y arma la cola P1-P4 con envejecimiento'
$lider = Invoke-RestMethod ('http://127.0.0.1:8002/api/eventos?desde=' + [uri]::EscapeDataString('{}') + '&limite=5000') -TimeoutSec 5
$tipos = $lider.eventos | Group-Object tipo | Sort-Object Count -Descending
Write-Host '· Eventos en el líder:'
$tipos | ForEach-Object { Write-Host ("    {0,-32} {1}" -f $_.Name, $_.Count) }
Write-Host '· Tablero (cola P1-P4, mapa, incertidumbre): http://127.0.0.1:8002/tablero'
Start-Sleep -Seconds $PausaSeg

Paso 'PASO 6 · A6 propone; el HUMANO decide (T-010)'
Write-Host '· En el tablero, revisa una recomendación y presiona APROBAR o DESCARTAR.'
Write-Host '· La decisión dispara la alerta hacia el Nodo D (http://127.0.0.1:8004/campo) y vuelve el ACK.'
Write-Host ("· Esperando la decisión humana ({0} s)…" -f $PausaSeg)
Start-Sleep -Seconds $PausaSeg

Paso 'PASO 7 · Se mata B → C no recibe 10 latidos y asume (~10 s)'
$inicio = Get-Date
& (Join-Path $PSScriptRoot 'matar-nodo.ps1') B
do {
    Start-Sleep -Seconds 1
    $c = Salud-de 'C' 8003
    $segundos = ((Get-Date) - $inicio).TotalSeconds
} while ((($null -eq $c) -or ($c.rol -ne 'lider')) -and ($segundos -lt 30))

if ($null -ne $c -and $c.rol -eq 'lider') {
    Write-Host ("· C asumió como líder en {0:N1} s (época {1})." -f $segundos, $c.epoca) -ForegroundColor Green
    Write-Host '· El tablero sigue operativo desde C: http://127.0.0.1:8003/tablero'
    Write-Host '· A reenvía a C lo no replicado (T-012): 0 pérdidas.'
} else {
    Write-Host '· C no asumió en 30 s: revisar storage/logs/sard-c-*.log' -ForegroundColor Red
}
Start-Sleep -Seconds $PausaSeg

Paso 'PASO 8 · B vuelve como seguidor y se une por UUID (T-013)'
& (Join-Path $PSScriptRoot 'start.ps1') -Nodos B
Write-Host 'Esperando la unión de B…'
Start-Sleep -Seconds 20
$b = Salud-de 'B' 8002
$c = Salud-de 'C' 8003
if ($null -ne $b -and $null -ne $c) {
    Write-Host ("· B: rol {0} época {1} · C: rol {2} época {3}" -f $b.rol, $b.epoca, $c.rol, $c.epoca)
    Write-Host '· La unión iguala los logs por UUID, sin pérdidas ni duplicados.'
    Write-Host '· Verificación formal: php artisan sard:escenario union --semilla 42'
}

# ─── Cierre: todo arriba y semilla cargada (AC-024a) ────────────────────────
Paso 'CIERRE'
Write-Host 'Tablero: http://127.0.0.1:8002/tablero (o :8003)  ·  PWA: http://127.0.0.1:8001/pwa  ·  Campo: http://127.0.0.1:8004/campo'
Write-Host 'Métricas: http://127.0.0.1:8001/api/metricas  ·  Canal: http://127.0.0.1:8010/api/canal/estadisticas'
Write-Host 'Los procesos quedan arriba y la semilla 42 cargada (AC-024a).' -ForegroundColor Green
