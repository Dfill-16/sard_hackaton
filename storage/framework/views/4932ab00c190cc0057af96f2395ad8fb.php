<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Coordinación · SARD (<?php echo e($nodo); ?>)</title>
    <script>
        (() => {
            let tema = null;
            let idioma = null;
            try { tema = localStorage.getItem('sard-tema'); idioma = localStorage.getItem('sard-idioma'); } catch (e) {}
            if (tema === 'oscuro' || (tema === null && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
            if (idioma === 'en') {
                document.documentElement.lang = 'en';
            }
        })();
    </script>
    <?php echo app('Illuminate\Foundation\Vite')->fonts(); ?>
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/tablero.css', 'resources/js/tablero/app.js']); ?>
</head>
<body class="bg-background font-sans text-foreground antialiased">
<div x-data="tablero(<?php echo \Illuminate\Support\Js::from(['nodo' => $nodo, 'pollingMs' => $pollingMs, 'urls' => $urlsRespaldo, 'teselas' => $teselas])->toHtml() ?>)" x-init="iniciar()"
    @keydown.escape.window="cerrar()"
    class="flex min-h-dvh flex-col lg:h-dvh">

    
    <header class="flex flex-wrap items-center gap-x-6 gap-y-3 border-b border-border bg-surface px-4 py-3">
        <div class="flex items-baseline gap-2">
            <span class="text-base font-bold tracking-tight">SARD</span>
            <h1 class="text-sm text-muted" x-t>Coordinación · Manizales</h1>
        </div>

        <nav class="flex items-center gap-1" aria-label="Incidentes por prioridad" x-t:aria-label>
            <?php $__currentLoopData = ['P1', 'P2', 'P3', 'P4']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $prioridad): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <span class="inline-flex items-center gap-1.5 rounded-sm bg-surface-2 py-1 pr-2.5 pl-1">
                    <span class="inline-flex h-5 items-center rounded-sm px-1.5 font-mono text-[11px] font-semibold bg-<?php echo e(strtolower($prioridad)); ?> text-<?php echo e(strtolower($prioridad)); ?>-ink"><?php echo e($prioridad); ?></span>
                    <span class="font-mono text-sm font-semibold" x-text="estado ? estado.contadores.<?php echo e($prioridad); ?> : '–'">–</span>
                </span>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            <span class="ml-2 inline-flex items-center gap-1.5 py-1 text-sm text-muted">
                <?php echo $__env->make('tablero.partials.icono', ['nombre' => 'sin-ubicacion'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                <span><span class="font-mono font-semibold text-foreground" x-text="estado ? estado.contadores.sin_ubicacion : '–'">–</span> <span x-t>sin ubicación</span></span>
            </span>
            <span class="ml-2 hidden text-sm text-muted sm:inline">
                <span class="font-mono font-semibold text-foreground" x-text="estado ? estado.contadores.total : '–'">–</span> <span x-t>incidentes</span>
            </span>
        </nav>

        <div class="ml-auto flex flex-wrap items-center gap-2 text-xs">
            <label class="inline-flex items-center gap-1.5 rounded-sm border border-border py-0.5 pr-0.5 pl-2 text-muted focus-within:border-border-hover">
                <span x-t>Operador</span>
                <input type="text" x-model="operador" @change="guardarOperador()" maxlength="60" placeholder="tu nombre" x-t:placeholder
                    class="w-28 rounded-sm bg-surface-2 px-1.5 py-1 text-xs text-foreground placeholder:text-muted focus:outline-none">
            </label>
            <span class="inline-flex items-center gap-2 rounded-full border border-border px-3 py-1 text-sm" role="status" aria-live="polite">
                <span class="size-2 rounded-full" :class="claseConexion()"></span>
                <span x-text="textoConexion()">Conectando…</span>
            </span>
            <template x-if="estado">
                <span class="flex items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-sm border border-border px-2 py-1">
                        <span class="size-2 rounded-full" :class="estado.rol === 'lider' ? 'bg-accent' : 'bg-muted'"></span>
                        <span x-text="`${estado.nodo} ${estado.rol === 'lider' ? $t('líder') : $t('seguidor')}`"></span>
                    </span>
                    <span class="rounded-sm border border-border px-2 py-1 font-mono" x-text="$t('época :n', { n: estado.epoca })"></span>
                    <span class="rounded-sm bg-degraded/12 px-2 py-1 font-medium text-degraded" x-show="estado.modo === 'reglas'"
                        :title="$t('Sin LLM: triaje y correlación por reglas')" x-text="$t('modo reglas')">modo reglas</span>
                    <span class="rounded-sm border border-border px-2 py-1 font-medium" x-show="estado.modo === 'llm'"
                        :title="estado.llm_proveedor ? $t('Último proveedor: :proveedor. El LLM solo eleva prioridades y redacta; nunca decide.', { proveedor: estado.llm_proveedor }) : $t('El LLM solo eleva prioridades y redacta; nunca decide.')"
                        x-text="$t('modo LLM')">modo LLM</span>
                    <span class="rounded-sm border border-border px-2 py-1 font-mono"
                        :class="estado.rol !== 'lider' && estado.ultimo_latido_hace_s >= 3 ? 'border-degraded text-degraded' : ''"
                        :title="estado.rol === 'lider' ? $t('Último latido confirmado por el seguidor') : $t('Último latido recibido del líder; a los 10 s este nodo asume')"
                        x-text="estado.ultimo_latido_hace_s === null ? $t('último latido –') : $t('último latido hace :s s', { s: estado.ultimo_latido_hace_s })"></span>
                    <span class="rounded-sm bg-down/12 px-2 py-1 font-medium text-down" x-show="estado.aislado" x-text="$t('aislado (demo)')">aislado (demo)</span>
                </span>
            </template>
            <?php echo $__env->make('partials.selector-idioma', ['clase' => 'h-8 px-2 text-xs'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            <button type="button" @click="alternarTema()" class="inline-flex size-8 items-center justify-center rounded-sm border border-border text-muted hover:border-border-hover hover:text-foreground"
                :aria-label="oscuro ? $t('Usar tema claro') : $t('Usar tema oscuro')" :title="oscuro ? $t('Tema claro') : $t('Tema oscuro')">
                <span x-show="oscuro"><?php echo $__env->make('tablero.partials.icono', ['nombre' => 'sol', 'clase' => 'size-4'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?></span>
                <span x-show="!oscuro"><?php echo $__env->make('tablero.partials.icono', ['nombre' => 'luna', 'clase' => 'size-4'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?></span>
            </button>
        </div>
    </header>

    <main class="flex min-h-0 grow flex-col lg:flex-row" :class="arrastrando ? 'cursor-col-resize select-none' : ''">

        
        <aside x-ref="lista" class="panel-scroll flex min-h-0 flex-col gap-5 overflow-y-auto border-border p-3 lg:w-(--ancho-lista) lg:shrink-0"
            :class="listaPlegada ? 'lg:hidden' : ''" :style="`--ancho-lista: ${anchoLista}px`"
            aria-label="Incidentes" x-t:aria-label id="lista-incidentes">
            <section aria-labelledby="titulo-cola">
                <div class="flex items-center justify-between gap-2 px-2.5 pb-1.5">
                    <h2 id="titulo-cola" class="text-sm font-semibold"><span x-t>Atender primero</span>
                        <span class="font-mono font-normal text-muted" x-text="estado ? `P1–P2 · ${estado.cola_total}` : ''"></span></h2>
                    <span class="flex items-center gap-1">
                        <button type="button" class="rounded-sm px-2 py-1 text-xs text-muted hover:bg-surface-2 hover:text-foreground"
                            x-show="estado && estado.cola_total > 10" @click="alternarTodos()"
                            x-text="verTodos ? $t('Ver solo 10') : $t('Ver los :n', { n: estado?.cola_total })"></button>
                        <button type="button" @click="plegarLista(true)" aria-controls="lista-incidentes" :aria-expanded="!listaPlegada"
                            :aria-label="$t('Ocultar la lista de incidentes')" :title="$t('Ocultar la lista de incidentes')"
                            class="hidden size-7 items-center justify-center rounded-sm text-muted hover:bg-surface-2 hover:text-foreground lg:inline-flex">
                            <?php echo $__env->make('tablero.partials.icono', ['nombre' => 'plegar', 'clase' => 'size-4'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                        </button>
                    </span>
                </div>
                <div class="flex flex-col gap-0.5" x-show="!estado">
                    <template x-for="i in 5"><div class="esqueleto mx-2.5 my-1.5 h-12"></div></template>
                </div>
                <div class="flex flex-col gap-0.5">
                    <template x-for="inc in (estado ? estado.cola : [])" :key="inc.uuid">
                        <?php echo $__env->make('tablero.partials.tarjeta', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                    </template>
                </div>
                <p class="mx-2.5 rounded-md border border-dashed border-border px-3 py-2.5 text-[13px] text-muted" x-show="estado && estado.cola.length === 0" x-t>
                    No hay incidentes P1 ni P2. Los nuevos reportes aparecen aquí en cuanto el líder los clasifica.</p>
            </section>

            <section aria-labelledby="titulo-sin-ubicacion">
                <div class="px-2.5 pb-1.5">
                    <h2 id="titulo-sin-ubicacion" class="flex items-center gap-1.5 text-sm font-semibold">
                        <?php echo $__env->make('tablero.partials.icono', ['nombre' => 'sin-ubicacion'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                        <span x-t>Sin ubicación</span> <span class="font-mono font-normal text-muted" x-text="estado ? estado.sin_ubicacion.length : '–'"></span>
                    </h2>
                    <p class="mt-0.5 text-xs text-muted" x-t>Llegaron sin GPS y no se estima el lugar. Pide la referencia a la central.</p>
                </div>
                <div class="flex flex-col gap-0.5">
                    <template x-for="inc in (estado ? estado.sin_ubicacion : [])" :key="inc.uuid">
                        <div class="flex flex-col">
                            <?php echo $__env->make('tablero.partials.tarjeta', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                            <button type="button" @click="contactarCentral(inc.uuid)"
                                class="mr-2.5 mb-1 inline-flex items-center gap-1.5 self-end rounded-sm border border-border px-2.5 py-1 text-xs font-medium hover:border-border-hover">
                                <?php echo $__env->make('tablero.partials.icono', ['nombre' => 'telefono', 'clase' => 'size-3'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                                <span x-t>Contactar a la central</span>
                            </button>
                        </div>
                    </template>
                </div>
                <p class="px-2.5 text-xs text-muted" x-show="estado && estado.sin_ubicacion.length === 0" x-t>Ningún reporte sin ubicación.</p>
            </section>

            <section aria-labelledby="titulo-brechas">
                <div class="px-2.5 pb-1.5">
                    <h2 id="titulo-brechas" class="flex items-center gap-1.5 text-sm font-semibold">
                        <?php echo $__env->make('tablero.partials.icono', ['nombre' => 'alerta'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                        <span x-t>Brechas</span> <span class="font-mono font-normal text-muted" x-text="estado ? (estado.brechas ?? []).length : '–'"></span>
                    </h2>
                    <p class="mt-0.5 text-xs text-muted" x-t>P1–P2 sin unidad disponible del tipo necesario a ≤ 3 km. Solo informa; no cambia la recomendación.</p>
                </div>
                <ul class="flex flex-col gap-0.5">
                    <template x-for="b in (estado ? estado.brechas ?? [] : [])" :key="b.incidente_uuid">
                        <li>
                            <button type="button" @click="abrir(b.incidente_uuid)"
                                class="flex w-full items-start gap-3 rounded-md border border-dashed border-down/50 px-2.5 py-2 text-left hover:bg-surface-2">
                                <span class="mt-px inline-flex h-6 min-w-9 shrink-0 items-center justify-center rounded-sm px-1.5 font-mono text-xs font-semibold"
                                    :class="clasePrioridad(b.prioridad)" x-text="b.prioridad"></span>
                                <span class="flex min-w-0 flex-col gap-0.5">
                                    <span class="text-sm font-medium" x-text="$td(b.tipo_nombre)"></span>
                                    <span class="text-xs text-muted" x-text="textoBrecha(b)"></span>
                                </span>
                            </button>
                        </li>
                    </template>
                </ul>
                <p class="px-2.5 text-xs text-muted" x-show="estado && (estado.brechas ?? []).length === 0" x-t>Sin brechas: todo P1–P2 tiene una unidad disponible cerca.</p>
            </section>

            <section aria-labelledby="titulo-baja">
                <div class="px-2.5 pb-1.5">
                    <h2 id="titulo-baja" class="text-sm font-semibold"><span x-t>Baja prioridad</span>
                        <span class="font-mono font-normal text-muted" x-text="estado ? `P3–P4 · ${estado.baja_prioridad.length}` : ''"></span></h2>
                    <p class="mt-0.5 text-xs text-muted" x-t>Siguen en la cola y suben de nivel cada 10 min sin decisión (hasta P2).</p>
                </div>
                <div class="flex flex-col gap-0.5">
                    <template x-for="inc in (estado ? estado.baja_prioridad : [])" :key="inc.uuid">
                        <?php echo $__env->make('tablero.partials.tarjeta', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                    </template>
                </div>
                <p class="px-2.5 text-xs text-muted" x-show="estado && estado.baja_prioridad.length === 0" x-t>Sin casos P3–P4.</p>
            </section>
        </aside>

        
        <div x-show="listaPlegada" x-cloak class="hidden shrink-0 flex-col items-center gap-2 border-r border-border bg-surface py-3 lg:flex">
            <button type="button" @click="plegarLista(false)" aria-controls="lista-incidentes" :aria-expanded="!listaPlegada"
                :aria-label="$t('Mostrar la lista de incidentes')" :title="$t('Mostrar la lista de incidentes')"
                class="inline-flex size-8 items-center justify-center rounded-sm text-muted hover:bg-surface-2 hover:text-foreground">
                <?php echo $__env->make('tablero.partials.icono', ['nombre' => 'desplegar', 'clase' => 'size-4'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            </button>
            <?php $__currentLoopData = ['P1', 'P2']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $prioridad): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <button type="button" @click="plegarLista(false)" :title="$t('Mostrar la lista de incidentes')"
                    class="inline-flex h-6 min-w-8 items-center justify-center rounded-sm px-1 font-mono text-[11px] font-semibold bg-<?php echo e(strtolower($prioridad)); ?> text-<?php echo e(strtolower($prioridad)); ?>-ink">
                    <span x-text="estado ? `<?php echo e($prioridad); ?>·${estado.contadores.<?php echo e($prioridad); ?>}` : '<?php echo e($prioridad); ?>'"><?php echo e($prioridad); ?></span>
                </button>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>

        
        <div role="separator" aria-orientation="vertical" tabindex="0" aria-controls="lista-incidentes"
            aria-label="Ancho de la lista de incidentes" x-t:aria-label
            :aria-valuenow="listaPlegada ? 0 : anchoLista" aria-valuemin="0" :aria-valuemax="anchoMaximo()"
            :title="$t('Arrastra para cambiar el ancho · doble clic para ocultar o mostrar')"
            @pointerdown="empezarArrastre($event)" @dblclick="plegarLista(!listaPlegada)" @keydown="teclaSeparador($event)"
            class="group relative z-[600] -mx-1 hidden w-2 shrink-0 cursor-col-resize items-stretch justify-center focus-visible:outline-none lg:flex">
            <span class="w-px bg-border transition-colors group-hover:w-0.5 group-hover:bg-accent group-focus-visible:w-0.5 group-focus-visible:bg-accent"
                :class="arrastrando ? 'w-0.5 bg-accent' : ''"></span>
        </div>

        
        <section class="relative min-h-[360px] grow border-border lg:min-h-0" aria-label="Mapa de incidentes" x-t:aria-label>
            <div x-ref="mapa" class="mapa-neutro absolute inset-0"></div>
            <div class="absolute top-3 right-3 z-[500] flex flex-col items-end gap-1.5">
                <div class="flex gap-1 rounded-md bg-surface/95 p-1 text-xs shadow-[0_2px_8px_#0000001f]" role="group" aria-label="Capas del mapa" x-t:aria-label>
                    <button type="button" @click="alternarCapa('recursos')" :aria-pressed="verRecursos"
                        :class="verRecursos ? 'bg-foreground text-background' : 'text-muted hover:text-foreground'"
                        class="min-h-8 rounded-sm px-2.5 font-medium" x-t>Recursos</button>
                    <button type="button" @click="alternarCapa('unidades')" :aria-pressed="verUnidades"
                        :class="verUnidades ? 'bg-foreground text-background' : 'text-muted hover:text-foreground'"
                        class="min-h-8 rounded-sm px-2.5 font-medium" x-t>Unidades</button>
                </div>
                <span x-show="verRecursos || verUnidades" x-cloak x-t
                    class="rounded-sm bg-degraded/90 px-2 py-0.5 text-[11px] font-semibold text-black">Datos de demostración</span>
                <span x-show="verUnidades" x-cloak class="rounded-sm bg-surface/95 px-2 py-1 text-[11px] text-muted shadow-[0_2px_8px_#0000001f]">
                    <span x-t>Unidad:</span> <b class="font-medium text-healthy" x-t>verde disponible</b> · <b class="font-medium text-accent" x-t>roja asignada</b> · <span x-t>punteada ocupada</span>
                </span>
            </div>
            <div class="pointer-events-none absolute bottom-3 left-3 z-[500] flex flex-wrap items-center gap-3 rounded-md bg-surface/95 px-2.5 py-1.5 text-[11px] text-muted shadow-[0_2px_8px_#0000001f]">
                <?php $__currentLoopData = ['P1', 'P2', 'P3', 'P4']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $prioridad): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <span class="inline-flex items-center gap-1"><span class="size-2.5 rounded-full bg-<?php echo e(strtolower($prioridad)); ?>"></span><?php echo e($prioridad); ?></span>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                <span x-t>· anillo grueso = masivo · anillo rojo punteado = brecha</span>
                <span class="basis-full font-medium text-foreground" x-show="!detalle" x-t>Elige un incidente de la lista o del mapa para ver su detalle.</span>
                <span class="basis-full" x-t>Sólido = corroborado (≥ 60 %) · Punteado = dudoso · el halo marca el radio aproximado</span>
                <?php if (! ($hayTeselas)): ?>
                    <span x-t>· sin teselas: lienzo neutro</span>
                <?php endif; ?>
                <span x-show="fondo === 'cargando'" x-cloak x-t>· cargando mapa…</span>
                <span x-show="fondo === 'fallo'" x-cloak x-t>· mapa no disponible: lienzo neutro</span>
            </div>
        </section>

        
        <section x-show="detalle" x-cloak class="panel-scroll min-h-0 overflow-y-auto border-t border-border bg-surface lg:w-[clamp(340px,32vw,460px)] lg:shrink-0 lg:border-t-0 lg:border-l" aria-label="Detalle del incidente" x-t:aria-label x-ref="detalle">
            <p class="p-6 text-sm text-down" x-show="detalle && detalle.error" x-text="detalle?.error"></p>

            <template x-if="detalle && !detalle.error">
                <div class="flex flex-col gap-5 p-4">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex h-8 min-w-11 items-center justify-center rounded-sm px-2 font-mono text-sm font-semibold"
                            :class="clasePrioridad(detalle.prioridad)" x-text="detalle.prioridad"></span>
                        <div class="flex min-w-0 grow flex-col gap-1">
                            <h2 class="text-base leading-tight font-semibold" x-text="$td(detalle.tipo_nombre)"></h2>
                            <span class="truncate font-mono text-[11px] text-muted" :title="detalle.uuid" x-text="$t('incidente :uuid', { uuid: detalle.uuid })"></span>
                        </div>
                        <button type="button" @click="cerrar()" class="inline-flex size-8 shrink-0 items-center justify-center rounded-sm text-muted hover:bg-surface-2 hover:text-foreground" aria-label="Cerrar detalle" x-t:aria-label>
                            <?php echo $__env->make('tablero.partials.icono', ['nombre' => 'cerrar', 'clase' => 'size-4'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                        </button>
                    </div>

                    <div class="flex flex-wrap gap-1.5 text-xs" x-show="detalle.masivo || detalle.posible_rumor || detalle.sin_ubicacion || detalle.envejecido">
                        <template x-if="detalle.masivo">
                            <span class="inline-flex items-center gap-1 rounded-sm bg-down/12 px-2 py-1 font-medium text-down">
                                <?php echo $__env->make('tablero.partials.icono', ['nombre' => 'dispositivos', 'clase' => 'size-3'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                                <span x-text="$t('Incidente masivo · :n dispositivos', { n: detalle.dispositivos_n })"></span>
                            </span>
                        </template>
                        <template x-if="detalle.posible_rumor">
                            <span class="inline-flex items-center gap-1 rounded-sm bg-degraded/12 px-2 py-1 font-medium text-degraded">
                                <?php echo $__env->make('tablero.partials.icono', ['nombre' => 'rumor', 'clase' => 'size-3'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                                <span x-t>Posible rumor · 1 dispositivo · no se eleva</span>
                            </span>
                        </template>
                        <template x-if="detalle.envejecido">
                            <span class="inline-flex items-center gap-1 rounded-sm bg-surface-2 px-2 py-1 font-medium">
                                <?php echo $__env->make('tablero.partials.icono', ['nombre' => 'reloj', 'clase' => 'size-3'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                                <span x-text="$t('Envejecido: base :p', { p: detalle.prioridad_base })"></span>
                            </span>
                        </template>
                    </div>

                    <div class="rounded-md bg-surface-2 p-3 text-[13px]" x-show="detalle.sin_ubicacion || contactando === detalle.uuid">
                        <p class="flex items-center gap-1.5 font-medium">
                            <?php echo $__env->make('tablero.partials.icono', ['nombre' => 'telefono', 'clase' => 'size-3.5'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                            <span x-t>Contactar a la central</span>
                        </p>
                        <p class="mt-1 text-muted" x-t>El reporte llegó sin GPS y el sistema no inventa el lugar. Pide a la central una referencia (barrio, calle, punto conocido) antes de asignar recursos.</p>
                    </div>

                    <dl class="grid grid-cols-4 gap-px overflow-hidden rounded-md border border-border bg-border text-center">
                        <div class="bg-surface px-2 py-2"><dt class="text-[11px] text-muted" x-t>Reportes</dt><dd class="font-mono text-base font-semibold" x-text="detalle.reportes_n"></dd></div>
                        <div class="bg-surface px-2 py-2"><dt class="text-[11px] text-muted" x-t>Dispositivos</dt><dd class="font-mono text-base font-semibold" x-text="detalle.dispositivos_n"></dd></div>
                        <div class="bg-surface px-2 py-2"><dt class="text-[11px] text-muted" x-t>Confianza</dt><dd class="font-mono text-base font-semibold" x-text="porcentaje(detalle.confianza)"></dd>
                            <dd class="text-[10px] font-medium" :class="detalle.corroborado ? 'text-healthy' : 'text-degraded'" x-text="detalle.corroborado ? $t('corroborado') : $t('dudoso')"></dd></div>
                        <div class="bg-surface px-2 py-2"><dt class="text-[11px] text-muted" x-t>Base → hoy</dt><dd class="font-mono text-base font-semibold" x-text="`${detalle.prioridad_base}→${detalle.prioridad}`"></dd></div>
                    </dl>

                    <template x-if="esBrecha(detalle.uuid)">
                        <p class="flex items-start gap-1.5 rounded-md border border-dashed border-down/50 px-3 py-2 text-[13px]">
                            <span class="mt-0.5 text-down"><?php echo $__env->make('tablero.partials.icono', ['nombre' => 'alerta', 'clase' => 'size-3.5'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?></span>
                            <span x-text="$t('Brecha: :texto', { texto: textoBrecha(estado.brechas.find((b) => b.incidente_uuid === detalle.uuid)) })"></span>
                        </p>
                    </template>

                    <p class="text-xs text-muted" x-show="detalle.albergue_cercano">
                        <span x-t>Albergue más cercano:</span>
                        <span class="text-foreground" x-text="detalle.albergue_cercano ? $t(':nombre (:zona) a :m m', { nombre: detalle.albergue_cercano.nombre, zona: detalle.albergue_cercano.zona, m: detalle.albergue_cercano.distancia_m }) : ''"></span>
                        · <span x-t>datos de demostración</span>
                    </p>

                    
                    <section class="flex flex-col gap-2" aria-labelledby="titulo-fuentes">
                        <h3 id="titulo-fuentes" class="flex items-baseline gap-2 text-sm font-semibold">
                            <span x-t>Fuentes</span>
                            <span class="font-mono font-normal text-muted" x-text="detalle.fuentes.length === 1 ? $t('1 dispositivo') : $t(':n dispositivos', { n: detalle.fuentes.length })"></span>
                        </h3>
                        <p x-show="detalle.posible_rumor" class="flex items-start gap-1.5 rounded-md bg-degraded/12 px-3 py-2 text-[13px] text-foreground">
                            <span class="mt-0.5 text-degraded"><?php echo $__env->make('tablero.partials.icono', ['nombre' => 'rumor', 'clase' => 'size-3.5'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?></span>
                            <span x-text="$t('Posible rumor: un solo dispositivo envió :n reportes. Se muestra, pero no sube de prioridad.', { n: detalle.reportes_n })"></span>
                        </p>
                        <ul class="grid grid-cols-1 gap-px overflow-hidden rounded-md border text-xs sm:grid-cols-2"
                            :class="detalle.corroborado ? 'border-solid border-border bg-border' : 'border-dashed border-border-hover bg-border'">
                            <template x-for="f in detalle.fuentes" :key="f.device_id">
                                <li class="flex items-baseline justify-between gap-2 bg-surface px-2.5 py-1.5">
                                    <span class="font-mono" :title="$t('dispositivo anónimo :id', { id: f.device_id })" x-text="f.device_id"></span>
                                    <span class="text-muted">
                                        <b class="font-mono font-semibold text-foreground" x-text="f.reportes_n"></b>
                                        <span x-text="f.reportes_n === 1 ? $t('rep · :desde', { desde: hora(f.primero) }) : $t('rep · :desde–:hasta', { desde: hora(f.primero), hasta: hora(f.ultimo) })"></span>
                                    </span>
                                </li>
                            </template>
                        </ul>
                    </section>

                    
                    <section class="flex flex-col gap-3 rounded-md border border-border p-3" aria-labelledby="titulo-recomendacion">
                        <div class="flex items-baseline justify-between gap-2">
                            <h3 id="titulo-recomendacion" class="text-sm font-semibold" x-t>Recomendación</h3>
                            <span class="text-[11px] text-muted" x-show="detalle.recomendacion"
                                x-text="detalle.recomendacion ? $t('sugerencia · v:version · :modo', { version: detalle.recomendacion.version, modo: detalle.recomendacion.modo === 'llm' ? $t('texto redactado por IA') : $t('plantilla') }) : ''"></span>
                        </div>
                        <p class="text-[13px] text-muted" x-show="!detalle.recomendacion" x-t>A6 aún no propone una acción para este incidente.</p>

                        <template x-if="detalle.recomendacion">
                            <div class="flex flex-col gap-2">
                                <p class="text-base font-semibold first-letter:uppercase" x-text="$td(detalle.recomendacion.accion)"></p>
                                <p class="text-[13px] text-muted" lang="es" x-text="detalle.recomendacion.justificacion"></p>
                                <ul class="flex flex-wrap gap-1 text-[11px]" x-show="detalle.recomendacion.pendientes.length">
                                    <template x-for="p in detalle.recomendacion.pendientes" :key="p">
                                        <li class="rounded-sm bg-degraded/12 px-1.5 py-0.5 text-degraded" x-text="$t('pendiente: :p', { p: $td(p) })"></li>
                                    </template>
                                </ul>

                                <div class="mt-1 flex flex-wrap items-center gap-2">
                                    <button type="button" @click="decidir('aprobar')" :disabled="enviandoDecision || yaDecidi('aprobar')"
                                        :class="detalle.recomendacion.decidida ? 'border border-border hover:border-border-hover' : 'bg-accent text-white hover:bg-accent-hover'"
                                        class="inline-flex min-h-9 items-center rounded-sm px-4 text-sm font-medium disabled:opacity-50"
                                        x-text="yaDecidi('aprobar') ? $t('Ya la aprobaste') : (detalle.recomendacion.unidad_id ? $t('Aprobar y alertar') : $t('Aprobar'))"></button>
                                    <button type="button" @click="abrirDescarte()" :disabled="enviandoDecision || yaDecidi('descartar')"
                                        class="inline-flex min-h-9 items-center rounded-sm border border-border px-4 text-sm font-medium hover:border-border-hover disabled:opacity-50"
                                        x-text="yaDecidi('descartar') ? $t('Ya la descartaste') : $t('Descartar')">Descartar</button>
                                    <span class="text-xs text-muted" x-show="detalle.recomendacion.decidida" x-t>Ya tiene decisión: otra la deja en conflicto.</span>
                                </div>
                                <p class="text-xs text-down" role="alert" x-show="errorDecision" x-text="errorDecision"></p>
                            </div>
                        </template>

                        <template x-if="detalle.estado === 'en_conflicto'">
                            <p class="flex items-center gap-1.5 rounded-sm bg-down/12 px-2 py-1.5 text-xs font-medium text-down">
                                <?php echo $__env->make('tablero.partials.icono', ['nombre' => 'alerta', 'clase' => 'size-3.5'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                                <span x-t>En conflicto: hay decisiones distintas sobre la misma recomendación. Se conservan todas.</span>
                            </p>
                        </template>

                        <ol class="flex flex-col gap-1 border-t border-border pt-2 text-xs" x-show="detalle.decisiones.length || detalle.alertas.length">
                            <template x-for="d in detalle.decisiones" :key="d.uuid">
                                <li class="flex flex-wrap gap-x-2">
                                    <span class="font-mono text-muted" x-text="hora(d.creado_en)"></span>
                                    <span class="font-medium" :class="d.accion === 'aprobar' ? 'text-healthy' : 'text-foreground'" x-text="d.accion === 'aprobar' ? $t('Aprobada') : $t('Descartada')"></span>
                                    <span x-text="$t('por :operador en :nodo', { operador: d.operador, nodo: d.nodo })"></span>
                                    <span class="text-muted" lang="es" x-show="d.motivo" x-text="`· ${d.motivo}`"></span>
                                </li>
                            </template>
                            <template x-for="a in detalle.alertas" :key="a.uuid">
                                <li class="flex flex-wrap gap-x-2">
                                    <span class="font-mono text-muted" x-text="hora(a.enviada_en)"></span>
                                    <span class="font-medium" x-text="$t('Alerta a :unidad', { unidad: a.unidad_nombre ?? a.unidad_id })"></span>
                                    <span class="font-medium" :class="a.ack_en ? 'text-healthy' : 'text-degraded'" x-text="`· ${textoAcuse(a)}`"></span>
                                </li>
                            </template>
                        </ol>
                    </section>

                    <template x-if="detalle.contradicciones.length > 0">
                        <div class="rounded-md bg-degraded/10 p-3 text-[13px]">
                            <p class="flex items-center gap-1.5 font-medium text-degraded">
                                <?php echo $__env->make('tablero.partials.icono', ['nombre' => 'alerta', 'clase' => 'size-3.5'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                                <span x-t>Contradicciones (se conservan, nadie las resuelve solo)</span>
                            </p>
                            <ul class="mt-2 flex flex-col gap-1.5 text-foreground">
                                <template x-for="c in detalle.contradicciones" :key="c.reportes.join()">
                                    <li class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                        <span class="font-medium" x-text="$td(c.detalle)"></span>
                                        <span class="text-xs text-muted" x-t>entre</span>
                                        <template x-for="u in c.reportes" :key="u">
                                            <button type="button" @click="resaltarReporte(u)"
                                                class="rounded-sm border border-border bg-surface px-1.5 py-0.5 font-mono text-[11px] hover:border-border-hover"
                                                :title="$t('Ver el reporte :uuid', { uuid: u })" x-text="u.slice(0, 8)"></button>
                                        </template>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>

                    <div class="flex flex-col gap-2">
                        <h3 class="text-sm font-semibold"><span x-t>Reportes originales</span> <span class="font-mono font-normal text-muted" x-text="detalle.reportes.length"></span></h3>
                        <ol class="flex flex-col divide-y divide-border rounded-md border border-border">
                            <template x-for="r in detalle.reportes" :key="r.uuid">
                                <li class="flex flex-col gap-1.5 px-3 py-2.5 transition-colors duration-300" :id="`reporte-${r.uuid}`"
                                    :class="reporteResaltado === r.uuid ? 'bg-degraded/15' : ''">
                                    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[11px] text-muted">
                                        <span class="font-mono" x-text="hora(r.hora)"></span>
                                        <span class="font-mono" :title="$t('dispositivo anónimo :id', { id: r.device_id })" x-text="$t('disp :id', { id: r.device_id })"></span>
                                        <span class="inline-flex h-4 items-center rounded-sm px-1 font-mono text-[10px] font-semibold" :class="clasePrioridad(r.prioridad)" x-text="r.prioridad ?? '–'"></span>
                                        <span class="font-mono" x-text="$t(':n pts', { n: r.puntaje ?? 0 })"></span>
                                        <span class="rounded-sm border border-border px-1" x-text="r.origen === 'IA' ? $t('IA') : $t('reglas')"></span>
                                        <span class="ml-auto font-mono" :title="r.motivos_incertidumbre.map((m) => $td(m)).join(' · ') || $t('sin motivos')" x-text="$t('incert. :p', { p: porcentaje(r.incertidumbre) })"></span>
                                    </div>
                                    <p class="text-[13px]" :class="r.texto ? '' : 'italic text-muted'" :lang="r.texto ? 'es' : null" x-text="r.texto || $t('Sin texto')"></p>
                                    <template x-if="r.elevacion">
                                        <p class="rounded-sm border border-dashed border-border-hover px-2 py-1.5 text-xs">
                                            <b class="font-semibold" x-text="$t('Elevado por IA (sugerencia): :de → :a', { de: r.prioridad_reglas, a: r.elevacion.a })"></b>
                                            <span class="block text-muted" lang="es" x-text="r.elevacion.explicacion"></span>
                                            <span class="block text-[11px] text-muted" x-text="$t(':proveedor · las reglas daban :p', { proveedor: r.elevacion.proveedor, p: r.prioridad_reglas })"></span>
                                        </p>
                                    </template>
                                    <div class="flex flex-wrap gap-1 text-[11px]" x-show="r.banderas_claves.length || r.personas !== null || r.sin_ubicacion || r.truncado">
                                        <template x-for="b in r.banderas_claves" :key="b">
                                            <span class="rounded-sm bg-surface-2 px-1.5 py-0.5" x-text="$td(b)"></span>
                                        </template>
                                        <span class="rounded-sm bg-surface-2 px-1.5 py-0.5" x-show="r.personas !== null" x-text="$t(':n personas', { n: r.personas })"></span>
                                        <span class="rounded-sm bg-surface-2 px-1.5 py-0.5" x-show="r.sin_ubicacion" x-t>sin ubicación</span>
                                        <span class="rounded-sm bg-surface-2 px-1.5 py-0.5" x-show="r.truncado" x-t>texto truncado</span>
                                    </div>
                                </li>
                            </template>
                        </ol>
                    </div>

                    <details class="group text-[13px]">
                        <summary class="flex cursor-pointer list-none items-center gap-1.5 text-sm font-semibold">
                            <?php echo $__env->make('tablero.partials.icono', ['nombre' => 'cadena', 'clase' => 'size-3.5'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                            <span x-t>Rastro en el log</span> <span class="font-mono font-normal text-muted" x-text="detalle.eventos.length"></span>
                            <span class="ml-auto text-xs font-normal text-muted group-open:hidden" x-t>mostrar</span>
                            <span class="ml-auto hidden text-xs font-normal text-muted group-open:inline" x-t>ocultar</span>
                        </summary>
                        <table class="mt-2 w-full text-left text-xs">
                            <thead class="text-[11px] text-muted">
                                <tr class="border-b border-border"><th class="py-1.5 pr-2 font-medium" x-t>Evento</th><th class="py-1.5 pr-2 font-medium" x-t>Nodo · seq</th><th class="py-1.5 font-medium">Hash</th></tr>
                            </thead>
                            <tbody>
                                <template x-for="e in detalle.eventos" :key="e.uuid">
                                    <tr class="border-b border-border last:border-0">
                                        <td class="py-1.5 pr-2" :title="e.uuid" x-text="e.tipo"></td>
                                        <td class="py-1.5 pr-2 font-mono" x-text="`${e.nodo} · ${e.seq}`"></td>
                                        <td class="py-1.5 font-mono text-muted" x-text="e.hash"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </details>
                </div>
            </template>
        </section>
    </main>

    
    <dialog x-ref="dialogoDescarte" @close="motivoDescarte = ''"
        class="m-auto w-[min(92vw,28rem)] rounded-lg border border-border bg-surface p-0 text-foreground shadow-[0_12px_40px_#00000040] backdrop:bg-black/50">
        <form method="dialog" @submit.prevent="confirmarDescarte()" class="flex flex-col gap-3 p-4">
            <h2 class="text-base font-semibold" x-t>Descartar recomendación</h2>
            <p class="text-[13px] text-muted" x-t>El motivo queda en el log con tu nombre. La recomendación no se borra.</p>
            <label for="motivo-descarte" class="text-sm font-medium" x-t>Motivo</label>
            <textarea id="motivo-descarte" x-model="motivoDescarte" rows="3" required maxlength="500"
                placeholder="Ej.: la unidad ya va en camino por radio" x-t:placeholder
                class="rounded-md border border-border bg-background p-2.5 text-sm placeholder:text-muted focus:border-border-hover focus:outline-none"></textarea>
            <p class="text-xs text-down" role="alert" x-show="errorDecision" x-text="errorDecision"></p>
            <div class="flex justify-end gap-2">
                <button type="button" @click="$refs.dialogoDescarte.close()" class="min-h-9 rounded-sm border border-border px-4 text-sm hover:border-border-hover" x-t>Cancelar</button>
                <button type="submit" :disabled="!motivoDescarte.trim() || enviandoDecision"
                    class="min-h-9 rounded-sm bg-foreground px-4 text-sm font-medium text-background disabled:opacity-40" x-t>Descartar</button>
            </div>
        </form>
    </dialog>
</div>
</body>
</html>
<?php /**PATH D:\sard_hackaton\resources\views/tablero/index.blade.php ENDPATH**/ ?>