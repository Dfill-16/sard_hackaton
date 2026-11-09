<#
.SYNOPSIS
    Detiene los procesos que levantó ./scripts/start.ps1 (PID en storage/framework/sard-pids-<nodo>.txt).

.PARAMETER Nodos
    Nodos a detener (por defecto todos).

.EXAMPLE
    ./scripts/stop.ps1
    ./scripts/stop.ps1 -Nodos B
#>
param(
    [ValidateSet('A', 'CANAL', 'B', 'C', 'D')]
    [string[]]$Nodos = @('A', 'CANAL', 'B', 'C', 'D')
)

$raiz = Split-Path -Parent $PSScriptRoot
$archivos = @($Nodos | ForEach-Object { Join-Path $raiz "storage/framework/sard-pids-$($_.ToLower()).txt" })

# Archivo único de la versión anterior del script.
if ($Nodos.Count -eq 5) {
    $archivos += Join-Path $raiz 'storage/framework/sard-pids.txt'
}

$detenidos = 0
foreach ($archivo in $archivos | Where-Object { Test-Path $_ }) {
    foreach ($id in Get-Content $archivo) {
        Stop-Process -Id ([int]$id) -Force -ErrorAction SilentlyContinue
        $detenidos++
    }
    Remove-Item $archivo
}

Write-Host ("Procesos de SARD detenidos ({0}): {1}" -f $detenidos, ($Nodos -join ', '))
