<#
.SYNOPSIS
    Levanta los procesos de SARD en un solo PC (ADR-001): A :8001, CANAL :8010, B :8002, C :8003, D :8004.

.DESCRIPTION
    Cada nodo corre con su NODE_ROLE y su propia base SQLite (contratos/config.md), tomando el resto
    de la configuración del .env de la raíz. Además arranca los procesos largos:
      - CANAL: php artisan sard:canal     (entrega con pérdida, latencia, desorden y ventanas)
      - A:     php artisan sard:a4        (cola de salida hacia el líder, reenvío tras failover)
      - B y C: php artisan queue:work     (ProcesarReporte: A2 + A3 + A6)
               php artisan sard:a5        (latidos, failover, unión al volver)
               php artisan sard:replicar  (réplica del log en el seguidor)
               php artisan sard:alertas   (alertas aprobadas hacia D, hasta el acuse)
      - D:     php artisan sard:campo     (acuses de campo hacia el líder)
    Los registros quedan en storage/logs/sard-<nodo>-<proceso>.log y los PID en
    storage/framework/sard-pids-<nodo>.txt.

.PARAMETER Fresco
    Borra las bases de los nodos que se arrancan (demo desde cero).

.PARAMETER Nodos
    Nodos a arrancar (por defecto todos). Ej.: -Nodos B para volver a levantar B tras matarlo.

.EXAMPLE
    ./scripts/start.ps1 -Fresco
    php artisan sard:sembrar 40 --masivo --rumor
    ./scripts/matar-nodo.ps1 B
    ./scripts/start.ps1 -Nodos B
    ./scripts/stop.ps1
#>
param(
    [switch]$Fresco,
    [ValidateSet('A', 'CANAL', 'B', 'C', 'D')]
    [string[]]$Nodos = @('A', 'CANAL', 'B', 'C', 'D')
)

$ErrorActionPreference = 'Stop'
$raiz = Split-Path -Parent $PSScriptRoot
# Se resuelve php.exe real (Herd y otros instalan un .bat que envuelve al ejecutable).
$php = (& (Get-Command php).Source -r 'echo PHP_BINARY;')
$router = Join-Path $raiz 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'
$logs = Join-Path $raiz 'storage/logs'

$trabajadoresBC = @(
    @('queue:work', '--sleep=1', '--tries=3'),
    @('sard:a5'),
    @('sard:replicar'),
    @('sard:alertas')
)

$catalogo = @(
    @{ Rol = 'A';     Puerto = 8001; Base = 'nodo_a.sqlite'; Workers = @(, @('sard:a4')) },
    @{ Rol = 'CANAL'; Puerto = 8010; Base = 'canal.sqlite';  Workers = @(, @('sard:canal')) },
    @{ Rol = 'B';     Puerto = 8002; Base = 'nodo_b.sqlite'; Workers = $trabajadoresBC },
    @{ Rol = 'C';     Puerto = 8003; Base = 'nodo_c.sqlite'; Workers = $trabajadoresBC },
    @{ Rol = 'D';     Puerto = 8004; Base = 'nodo_d.sqlite'; Workers = @(, @('sard:campo')) }
)

if (Test-Path (Join-Path $raiz 'storage/framework/sard-pids.txt')) {
    Write-Host 'Hay procesos de una versión anterior del script; ejecuta ./scripts/stop.ps1 primero.' -ForegroundColor Yellow
    exit 1
}

foreach ($nodo in $catalogo | Where-Object { $Nodos -contains $_.Rol }) {
    $archivoPids = Join-Path $raiz "storage/framework/sard-pids-$($nodo.Rol.ToLower()).txt"
    if (Test-Path $archivoPids) {
        Write-Host "$($nodo.Rol) ya está corriendo; ejecuta ./scripts/stop.ps1 -Nodos $($nodo.Rol) primero." -ForegroundColor Yellow
        continue
    }

    $base = Join-Path $raiz "database/$($nodo.Base)"
    if ($Fresco) {
        Remove-Item -Force -ErrorAction SilentlyContinue "$base", "$base-wal", "$base-shm"
    }
    if (-not (Test-Path $base)) {
        New-Item -ItemType File -Path $base | Out-Null
    }

    $env:NODE_ROLE = $nodo.Rol
    $env:DB_CONNECTION = 'sqlite'
    $env:DB_DATABASE = $base
    $env:APP_URL = "http://127.0.0.1:$($nodo.Puerto)"
    $env:QUEUE_CONNECTION = 'database'

    & $php (Join-Path $raiz 'artisan') migrate --force --no-interaction | Out-Null
    & $php (Join-Path $raiz 'artisan') db:seed --class=NodoEstadoSeeder --force --no-interaction | Out-Null
    if ($nodo.Rol -in @('B', 'C')) {
        & $php (Join-Path $raiz 'artisan') db:seed --class=SemillaSeeder --force --no-interaction | Out-Null
    }

    $etiqueta = $nodo.Rol.ToLower()
    $pids = @()
    $servidor = Start-Process -FilePath $php -ArgumentList @('-S', "127.0.0.1:$($nodo.Puerto)", $router) `
        -WorkingDirectory (Join-Path $raiz 'public') -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $logs "sard-$etiqueta-http.log") -RedirectStandardError (Join-Path $logs "sard-$etiqueta-http.err.log")
    $pids += $servidor.Id

    foreach ($worker in $nodo.Workers) {
        $nombre = ($worker[0] -replace '[:]', '-')
        $proceso = Start-Process -FilePath $php -ArgumentList (@((Join-Path $raiz 'artisan')) + $worker) `
            -WorkingDirectory $raiz -WindowStyle Hidden -PassThru `
            -RedirectStandardOutput (Join-Path $logs "sard-$etiqueta-$nombre.log") -RedirectStandardError (Join-Path $logs "sard-$etiqueta-$nombre.err.log")
        $pids += $proceso.Id
    }

    $pids | Set-Content -Path $archivoPids
    Write-Host ("{0,-6} http://127.0.0.1:{1}  ({2})" -f $nodo.Rol, $nodo.Puerto, $nodo.Base)
}

Remove-Item Env:NODE_ROLE, Env:DB_DATABASE, Env:APP_URL, Env:DB_CONNECTION, Env:QUEUE_CONNECTION -ErrorAction SilentlyContinue

Write-Host ''
Write-Host 'Tablero: http://127.0.0.1:8002/tablero (o :8003 en C)  -  PWA: http://127.0.0.1:8001/pwa  -  Campo: http://127.0.0.1:8004/campo'
Write-Host 'Matar B en vivo: ./scripts/matar-nodo.ps1 B  -  Detener todo: ./scripts/stop.ps1'
