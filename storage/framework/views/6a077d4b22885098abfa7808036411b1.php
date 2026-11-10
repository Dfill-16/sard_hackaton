
<button type="button"
    @click="abrir(inc.uuid)"
    :aria-pressed="seleccionado === inc.uuid"
    :class="[
        seleccionado === inc.uuid ? 'bg-surface-2 border-border-hover' : 'hover:bg-surface-2',
        inc.corroborado ? 'border-solid' : 'border-dashed',
    ]"
    :title="inc.corroborado ? $t('Corroborado: confianza ≥ 60 %') : $t('Dudoso: confianza < 60 %')"
    class="group flex w-full items-start gap-3 rounded-md border border-border px-2.5 py-2 text-left transition-colors duration-150">
    <span class="mt-px inline-flex h-6 min-w-9 shrink-0 items-center justify-center rounded-sm px-1.5 font-mono text-xs font-semibold"
        :class="clasePrioridad(inc.prioridad)" x-text="inc.prioridad"></span>

    <span class="flex min-w-0 grow flex-col gap-0.5">
        <span class="flex items-baseline justify-between gap-2">
            <span class="truncate text-sm font-medium" x-text="$td(inc.tipo_nombre)"></span>
            <span class="shrink-0 font-mono text-[11px] text-muted" :title="inc.creado_en" x-text="haceCuanto(inc.creado_en)"></span>
        </span>
        <span class="truncate text-[13px] text-muted" :title="inc.resumen" :lang="inc.resumen ? 'es' : null" x-text="inc.resumen || $t('Sin texto: solo tipo y banderas')"></span>
        <span class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted">
            <span class="font-mono" x-text="$t(':r rep · :d disp', { r: inc.reportes_n, d: inc.dispositivos_n })"></span>
            <span class="font-mono" :class="inc.corroborado ? 'text-foreground' : 'text-degraded'"
                x-text="`${porcentaje(inc.confianza)} ${inc.corroborado ? $t('corroborado') : $t('dudoso')}`"></span>
            <template x-if="inc.masivo">
                <span class="inline-flex items-center gap-1 rounded-sm bg-down/12 px-1.5 py-0.5 font-medium text-down">
                    <?php echo $__env->make('tablero.partials.icono', ['nombre' => 'dispositivos', 'clase' => 'size-3'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                    <span x-text="$t('Incidente masivo · :n dispositivos', { n: inc.dispositivos_n })"></span>
                </span>
            </template>
            <template x-if="inc.posible_rumor">
                <span class="inline-flex items-center gap-1 rounded-sm bg-degraded/12 px-1.5 py-0.5 font-medium text-degraded">
                    <?php echo $__env->make('tablero.partials.icono', ['nombre' => 'rumor', 'clase' => 'size-3'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                    <span x-t>Posible rumor · 1 dispositivo</span>
                </span>
            </template>
            <template x-if="inc.envejecido">
                <span class="inline-flex items-center gap-1 font-medium text-foreground" :title="$t('Base :p: subió por tiempo sin decisión humana', { p: inc.prioridad_base })">
                    <?php echo $__env->make('tablero.partials.icono', ['nombre' => 'flecha-arriba', 'clase' => 'size-3'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                    <span x-text="$t('envejecido (base :p)', { p: inc.prioridad_base })"></span>
                </span>
            </template>
            <template x-if="inc.estado === 'en_conflicto'">
                <span class="inline-flex items-center gap-1 rounded-sm bg-down/12 px-1.5 py-0.5 font-medium text-down">
                    <?php echo $__env->make('tablero.partials.icono', ['nombre' => 'alerta', 'clase' => 'size-3'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                    <span x-t>en conflicto</span>
                </span>
            </template>
            <template x-if="inc.estado === 'en_atencion'">
                <span class="inline-flex items-center gap-1 font-medium text-healthy" x-t>aprobada · en atención</span>
            </template>
            <template x-if="inc.alerta">
                <span class="inline-flex items-center gap-1 font-medium" :class="inc.alerta.ack_en ? 'text-healthy' : 'text-degraded'"
                    x-text="textoAcuse(inc.alerta)"></span>
            </template>
            <template x-if="inc.recomendacion_pendiente && inc.estado === 'abierto'">
                <span class="inline-flex items-center gap-1 font-medium text-foreground">
                    <span class="size-1.5 rounded-full bg-accent" aria-hidden="true"></span>
                    <span x-t>recomendación por decidir</span>
                </span>
            </template>
            <template x-if="inc.contradicciones_n > 0">
                <span class="inline-flex items-center gap-1 font-medium text-degraded">
                    <?php echo $__env->make('tablero.partials.icono', ['nombre' => 'alerta', 'clase' => 'size-3'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                    <span x-text="inc.contradicciones_n === 1 ? $t('1 contradicción') : $t(':n contradicciones', { n: inc.contradicciones_n })"></span>
                </span>
            </template>
        </span>
    </span>
</button>
<?php /**PATH D:\sard_hackaton\resources\views/tablero/partials/tarjeta.blade.php ENDPATH**/ ?>