<#
.SYNOPSIS
    Mata en vivo todos los procesos de un nodo (servidor, cola, sard:a5, sard:replicar) para la demo de failover.

.EXAMPLE
    ./scripts/matar-nodo.ps1 B      # C asume en ~10 s
    ./scripts/start.ps1 -Nodos B    # B vuelve como seguidor y se une por UUID
#>
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [ValidateSet('A', 'CANAL', 'B', 'C', 'D')]
    [string]$Nodo
)

$inicio = Get-Date
& (Join-Path $PSScriptRoot 'stop.ps1') -Nodos $Nodo
Write-Host ("{0} muerto a las {1:HH:mm:ss.fff}" -f $Nodo, $inicio)
