{{-- Fila de incidente. Se usa dentro de <template x-for="inc in ..."> (Alpine). --}}
<button type="button"
    @click="abrir(inc.uuid)"
    :aria-pressed="seleccionado === inc.uuid"
    :class="seleccionado === inc.uuid ? 'bg-surface-2 ring-1 ring-border-hover' : 'hover:bg-surface-2'"
    class="group flex w-full items-start gap-3 rounded-md px-2.5 py-2 text-left transition-colors duration-150">
    <span class="mt-px inline-flex h-6 min-w-9 shrink-0 items-center justify-center rounded-sm px-1.5 font-mono text-xs font-semibold"
        :class="clasePrioridad(inc.prioridad)" x-text="inc.prioridad"></span>

    <span class="flex min-w-0 grow flex-col gap-0.5">
        <span class="flex items-baseline justify-between gap-2">
            <span class="truncate text-sm font-medium" x-text="inc.tipo_nombre"></span>
            <span class="shrink-0 font-mono text-[11px] text-muted" :title="inc.creado_en" x-text="haceCuanto(inc.creado_en)"></span>
        </span>
        <span class="truncate text-[13px] text-muted" :title="inc.resumen" x-text="inc.resumen || 'Sin texto: solo tipo y banderas'"></span>
        <span class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted">
            <span class="font-mono" x-text="`${inc.reportes_n} rep · ${inc.dispositivos_n} disp · conf. ${porcentaje(inc.confianza)}`"></span>
            <template x-if="inc.masivo">
                <span class="inline-flex items-center gap-1 rounded-sm bg-down/12 px-1.5 py-0.5 font-medium text-down">
                    @include('tablero.partials.icono', ['nombre' => 'dispositivos', 'clase' => 'size-3'])
                    <span x-text="`Incidente masivo · ${inc.dispositivos_n} dispositivos`"></span>
                </span>
            </template>
            <template x-if="inc.posible_rumor">
                <span class="inline-flex items-center gap-1 rounded-sm bg-degraded/12 px-1.5 py-0.5 font-medium text-degraded">
                    @include('tablero.partials.icono', ['nombre' => 'rumor', 'clase' => 'size-3'])
                    Posible rumor · 1 dispositivo
                </span>
            </template>
            <template x-if="inc.envejecido">
                <span class="inline-flex items-center gap-1 font-medium text-foreground" :title="`Base ${inc.prioridad_base}: subió por tiempo sin decisión humana`">
                    @include('tablero.partials.icono', ['nombre' => 'flecha-arriba', 'clase' => 'size-3'])
                    <span x-text="`envejecido (base ${inc.prioridad_base})`"></span>
                </span>
            </template>
            <template x-if="inc.estado === 'en_conflicto'">
                <span class="inline-flex items-center gap-1 rounded-sm bg-down/12 px-1.5 py-0.5 font-medium text-down">
                    @include('tablero.partials.icono', ['nombre' => 'alerta', 'clase' => 'size-3'])
                    en conflicto
                </span>
            </template>
            <template x-if="inc.estado === 'en_atencion'">
                <span class="inline-flex items-center gap-1 font-medium text-healthy">aprobada · en atención</span>
            </template>
            <template x-if="inc.recomendacion_pendiente && inc.estado === 'abierto'">
                <span class="inline-flex items-center gap-1 font-medium text-foreground">
                    <span class="size-1.5 rounded-full bg-accent" aria-hidden="true"></span>
                    recomendación por decidir
                </span>
            </template>
            <template x-if="inc.contradicciones_n > 0">
                <span class="inline-flex items-center gap-1 font-medium text-degraded">
                    @include('tablero.partials.icono', ['nombre' => 'alerta', 'clase' => 'size-3'])
                    <span x-text="inc.contradicciones_n === 1 ? '1 contradicción' : `${inc.contradicciones_n} contradicciones`"></span>
                </span>
            </template>
        </span>
    </span>
</button>
