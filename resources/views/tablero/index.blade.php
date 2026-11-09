<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Coordinación · SARD ({{ $nodo }})</title>
    <script>
        (() => {
            let tema = null;
            try { tema = localStorage.getItem('sard-tema'); } catch (e) {}
            if (tema === 'oscuro' || (tema === null && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    @fonts
    @vite(['resources/css/tablero.css', 'resources/js/tablero/app.js'])
</head>
<body class="bg-background font-sans text-foreground antialiased">
<div x-data="tablero(@js(['nodo' => $nodo, 'pollingMs' => $pollingMs, 'urls' => $urlsRespaldo, 'teselas' => $teselas]))" x-init="iniciar()"
    @keydown.escape.window="cerrar()"
    class="flex min-h-dvh flex-col lg:h-dvh">

    {{-- Barra superior: identidad, semáforo y estado del nodo --}}
    <header class="flex flex-wrap items-center gap-x-6 gap-y-3 border-b border-border bg-surface px-4 py-3">
        <div class="flex items-baseline gap-2">
            <span class="text-base font-bold tracking-tight">SARD</span>
            <h1 class="text-sm text-muted">Coordinación · Manizales</h1>
        </div>

        <nav class="flex items-center gap-1" aria-label="Incidentes por prioridad">
            @foreach (['P1', 'P2', 'P3', 'P4'] as $prioridad)
                <span class="inline-flex items-center gap-1.5 rounded-sm bg-surface-2 py-1 pr-2.5 pl-1">
                    <span class="inline-flex h-5 items-center rounded-sm px-1.5 font-mono text-[11px] font-semibold bg-{{ strtolower($prioridad) }} text-{{ strtolower($prioridad) }}-ink">{{ $prioridad }}</span>
                    <span class="font-mono text-sm font-semibold" x-text="estado ? estado.contadores.{{ $prioridad }} : '–'">–</span>
                </span>
            @endforeach
            <span class="ml-2 inline-flex items-center gap-1.5 py-1 text-sm text-muted">
                @include('tablero.partials.icono', ['nombre' => 'sin-ubicacion'])
                <span><span class="font-mono font-semibold text-foreground" x-text="estado ? estado.contadores.sin_ubicacion : '–'">–</span> sin ubicación</span>
            </span>
            <span class="ml-2 hidden text-sm text-muted sm:inline">
                <span class="font-mono font-semibold text-foreground" x-text="estado ? estado.contadores.total : '–'">–</span> incidentes
            </span>
        </nav>

        <div class="ml-auto flex flex-wrap items-center gap-2 text-xs">
            <label class="inline-flex items-center gap-1.5 rounded-sm border border-border py-0.5 pr-0.5 pl-2 text-muted focus-within:border-border-hover">
                Operador
                <input type="text" x-model="operador" @change="guardarOperador()" maxlength="60" placeholder="tu nombre"
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
                        <span x-text="`${estado.nodo} ${estado.rol === 'lider' ? 'líder' : 'seguidor'}`"></span>
                    </span>
                    <span class="rounded-sm border border-border px-2 py-1 font-mono" x-text="`época ${estado.epoca}`"></span>
                    <span class="rounded-sm bg-degraded/12 px-2 py-1 font-medium text-degraded" x-show="estado.modo === 'reglas'"
                        title="Sin LLM: triaje y correlación por reglas">modo reglas</span>
                    <span class="rounded-sm border border-border px-2 py-1 font-mono"
                        :class="estado.rol !== 'lider' && estado.ultimo_latido_hace_s >= 3 ? 'border-degraded text-degraded' : ''"
                        :title="estado.rol === 'lider' ? 'Último latido confirmado por el seguidor' : 'Último latido recibido del líder; a los 10 s este nodo asume'"
                        x-text="estado.ultimo_latido_hace_s === null ? 'último latido –' : `último latido hace ${estado.ultimo_latido_hace_s} s`"></span>
                    <span class="rounded-sm bg-down/12 px-2 py-1 font-medium text-down" x-show="estado.aislado">aislado (demo)</span>
                </span>
            </template>
            <button type="button" @click="alternarTema()" class="inline-flex size-8 items-center justify-center rounded-sm border border-border text-muted hover:border-border-hover hover:text-foreground"
                :aria-label="oscuro ? 'Usar tema claro' : 'Usar tema oscuro'" :title="oscuro ? 'Tema claro' : 'Tema oscuro'">
                <span x-show="oscuro">@include('tablero.partials.icono', ['nombre' => 'sol', 'clase' => 'size-4'])</span>
                <span x-show="!oscuro">@include('tablero.partials.icono', ['nombre' => 'luna', 'clase' => 'size-4'])</span>
            </button>
        </div>
    </header>

    <main class="grid min-h-0 grow grid-cols-1 lg:grid-cols-[minmax(340px,400px)_1fr] xl:grid-cols-[minmax(340px,400px)_1fr_minmax(380px,460px)]">

        {{-- Columna 1: lista priorizada. Nada se oculta: P3-P4 y «sin ubicación» siempre tienen su encabezado con conteo. --}}
        <aside class="panel-scroll flex min-h-0 flex-col gap-5 overflow-y-auto border-border p-3 lg:border-r" aria-label="Incidentes">
            <section aria-labelledby="titulo-cola">
                <div class="flex items-center justify-between px-2.5 pb-1.5">
                    <h2 id="titulo-cola" class="text-sm font-semibold">Atender primero
                        <span class="font-mono font-normal text-muted" x-text="estado ? `P1–P2 · ${estado.cola_total}` : ''"></span></h2>
                    <button type="button" class="rounded-sm px-2 py-1 text-xs text-muted hover:bg-surface-2 hover:text-foreground"
                        x-show="estado && estado.cola_total > 10" @click="alternarTodos()"
                        x-text="verTodos ? 'Ver solo 10' : `Ver los ${estado?.cola_total}`"></button>
                </div>
                <div class="flex flex-col gap-0.5" x-show="!estado">
                    <template x-for="i in 5"><div class="esqueleto mx-2.5 my-1.5 h-12"></div></template>
                </div>
                <div class="flex flex-col gap-0.5">
                    <template x-for="inc in (estado ? estado.cola : [])" :key="inc.uuid">
                        @include('tablero.partials.tarjeta')
                    </template>
                </div>
                <p class="mx-2.5 rounded-md border border-dashed border-border px-3 py-2.5 text-[13px] text-muted" x-show="estado && estado.cola.length === 0">
                    No hay incidentes P1 ni P2. Los nuevos reportes aparecen aquí en cuanto el líder los clasifica.</p>
            </section>

            <section aria-labelledby="titulo-sin-ubicacion">
                <div class="px-2.5 pb-1.5">
                    <h2 id="titulo-sin-ubicacion" class="flex items-center gap-1.5 text-sm font-semibold">
                        @include('tablero.partials.icono', ['nombre' => 'sin-ubicacion'])
                        Sin ubicación <span class="font-mono font-normal text-muted" x-text="estado ? estado.sin_ubicacion.length : '–'"></span>
                    </h2>
                    <p class="mt-0.5 text-xs text-muted">Llegaron sin GPS y no se estima el lugar. Pide la referencia a la central.</p>
                </div>
                <div class="flex flex-col gap-0.5">
                    <template x-for="inc in (estado ? estado.sin_ubicacion : [])" :key="inc.uuid">
                        <div class="flex flex-col">
                            @include('tablero.partials.tarjeta')
                            <button type="button" @click="contactarCentral(inc.uuid)"
                                class="mr-2.5 mb-1 inline-flex items-center gap-1.5 self-end rounded-sm border border-border px-2.5 py-1 text-xs font-medium hover:border-border-hover">
                                @include('tablero.partials.icono', ['nombre' => 'telefono', 'clase' => 'size-3'])
                                Contactar a la central
                            </button>
                        </div>
                    </template>
                </div>
                <p class="px-2.5 text-xs text-muted" x-show="estado && estado.sin_ubicacion.length === 0">Ningún reporte sin ubicación.</p>
            </section>

            <section aria-labelledby="titulo-baja">
                <div class="px-2.5 pb-1.5">
                    <h2 id="titulo-baja" class="text-sm font-semibold">Baja prioridad
                        <span class="font-mono font-normal text-muted" x-text="estado ? `P3–P4 · ${estado.baja_prioridad.length}` : ''"></span></h2>
                    <p class="mt-0.5 text-xs text-muted">Siguen en la cola y suben de nivel cada 10 min sin decisión (hasta P2).</p>
                </div>
                <div class="flex flex-col gap-0.5">
                    <template x-for="inc in (estado ? estado.baja_prioridad : [])" :key="inc.uuid">
                        @include('tablero.partials.tarjeta')
                    </template>
                </div>
                <p class="px-2.5 text-xs text-muted" x-show="estado && estado.baja_prioridad.length === 0">Sin casos P3–P4.</p>
            </section>
        </aside>

        {{-- Columna 2: mapa --}}
        <section class="relative min-h-[360px] border-border lg:min-h-0 xl:border-r" aria-label="Mapa de incidentes">
            <div x-ref="mapa" class="mapa-neutro absolute inset-0"></div>
            <div class="pointer-events-none absolute bottom-3 left-3 z-[500] flex flex-wrap items-center gap-3 rounded-md bg-surface/95 px-2.5 py-1.5 text-[11px] text-muted shadow-[0_2px_8px_#0000001f]">
                @foreach (['P1', 'P2', 'P3', 'P4'] as $prioridad)
                    <span class="inline-flex items-center gap-1"><span class="size-2.5 rounded-full bg-{{ strtolower($prioridad) }}"></span>{{ $prioridad }}</span>
                @endforeach
                <span>· anillo grueso = masivo</span>
                @unless ($hayTeselas)
                    <span>· sin teselas: lienzo neutro</span>
                @endunless
                <span x-show="fondo === 'cargando'" x-cloak>· cargando mapa…</span>
                <span x-show="fondo === 'fallo'" x-cloak>· mapa no disponible: lienzo neutro</span>
            </div>
        </section>

        {{-- Columna 3: detalle del incidente (debajo del mapa en pantallas medianas) --}}
        <section class="panel-scroll min-h-0 overflow-y-auto border-t border-border bg-surface lg:col-span-2 xl:col-span-1 xl:border-t-0" aria-label="Detalle del incidente" x-ref="detalle">
            <div class="flex h-full flex-col items-start justify-center gap-2 p-6 text-sm text-muted" x-show="!detalle">
                @include('tablero.partials.icono', ['nombre' => 'capas', 'clase' => 'size-5'])
                <p class="max-w-[38ch]">Elige un incidente de la lista o del mapa para ver sus reportes originales, la confianza y su rastro en el log.</p>
            </div>

            <p class="p-6 text-sm text-down" x-show="detalle && detalle.error" x-text="detalle?.error"></p>

            <template x-if="detalle && !detalle.error">
                <div class="flex flex-col gap-5 p-4">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex h-8 min-w-11 items-center justify-center rounded-sm px-2 font-mono text-sm font-semibold"
                            :class="clasePrioridad(detalle.prioridad)" x-text="detalle.prioridad"></span>
                        <div class="flex min-w-0 grow flex-col gap-1">
                            <h2 class="text-base leading-tight font-semibold" x-text="detalle.tipo_nombre"></h2>
                            <span class="truncate font-mono text-[11px] text-muted" :title="detalle.uuid" x-text="`incidente ${detalle.uuid}`"></span>
                        </div>
                        <button type="button" @click="cerrar()" class="inline-flex size-8 shrink-0 items-center justify-center rounded-sm text-muted hover:bg-surface-2 hover:text-foreground" aria-label="Cerrar detalle">
                            @include('tablero.partials.icono', ['nombre' => 'cerrar', 'clase' => 'size-4'])
                        </button>
                    </div>

                    <div class="flex flex-wrap gap-1.5 text-xs" x-show="detalle.masivo || detalle.posible_rumor || detalle.sin_ubicacion || detalle.envejecido">
                        <template x-if="detalle.masivo">
                            <span class="inline-flex items-center gap-1 rounded-sm bg-down/12 px-2 py-1 font-medium text-down">
                                @include('tablero.partials.icono', ['nombre' => 'dispositivos', 'clase' => 'size-3'])
                                <span x-text="`Incidente masivo · ${detalle.dispositivos_n} dispositivos`"></span>
                            </span>
                        </template>
                        <template x-if="detalle.posible_rumor">
                            <span class="inline-flex items-center gap-1 rounded-sm bg-degraded/12 px-2 py-1 font-medium text-degraded">
                                @include('tablero.partials.icono', ['nombre' => 'rumor', 'clase' => 'size-3'])
                                Posible rumor · 1 dispositivo · no se eleva
                            </span>
                        </template>
                        <template x-if="detalle.envejecido">
                            <span class="inline-flex items-center gap-1 rounded-sm bg-surface-2 px-2 py-1 font-medium">
                                @include('tablero.partials.icono', ['nombre' => 'reloj', 'clase' => 'size-3'])
                                <span x-text="`Envejecido: base ${detalle.prioridad_base}`"></span>
                            </span>
                        </template>
                    </div>

                    <div class="rounded-md bg-surface-2 p-3 text-[13px]" x-show="detalle.sin_ubicacion || contactando === detalle.uuid">
                        <p class="flex items-center gap-1.5 font-medium">
                            @include('tablero.partials.icono', ['nombre' => 'telefono', 'clase' => 'size-3.5'])
                            Contactar a la central
                        </p>
                        <p class="mt-1 text-muted">El reporte llegó sin GPS y el sistema no inventa el lugar. Pide a la central una referencia (barrio, calle, punto conocido) antes de asignar recursos.</p>
                    </div>

                    <dl class="grid grid-cols-4 gap-px overflow-hidden rounded-md border border-border bg-border text-center">
                        <div class="bg-surface px-2 py-2"><dt class="text-[11px] text-muted">Reportes</dt><dd class="font-mono text-base font-semibold" x-text="detalle.reportes_n"></dd></div>
                        <div class="bg-surface px-2 py-2"><dt class="text-[11px] text-muted">Dispositivos</dt><dd class="font-mono text-base font-semibold" x-text="detalle.dispositivos_n"></dd></div>
                        <div class="bg-surface px-2 py-2"><dt class="text-[11px] text-muted">Confianza</dt><dd class="font-mono text-base font-semibold" x-text="porcentaje(detalle.confianza)"></dd></div>
                        <div class="bg-surface px-2 py-2"><dt class="text-[11px] text-muted">Base → hoy</dt><dd class="font-mono text-base font-semibold" x-text="`${detalle.prioridad_base}→${detalle.prioridad}`"></dd></div>
                    </dl>

                    {{-- Recomendación de A6: es una sugerencia; solo un humano la aprueba o descarta --}}
                    <section class="flex flex-col gap-3 rounded-md border border-border p-3" aria-labelledby="titulo-recomendacion">
                        <div class="flex items-baseline justify-between gap-2">
                            <h3 id="titulo-recomendacion" class="text-sm font-semibold">Recomendación</h3>
                            <span class="text-[11px] text-muted" x-show="detalle.recomendacion"
                                x-text="detalle.recomendacion ? `sugerencia · v${detalle.recomendacion.version} · ${detalle.recomendacion.modo}` : ''"></span>
                        </div>
                        <p class="text-[13px] text-muted" x-show="!detalle.recomendacion">A6 aún no propone una acción para este incidente.</p>

                        <template x-if="detalle.recomendacion">
                            <div class="flex flex-col gap-2">
                                <p class="text-base font-semibold first-letter:uppercase" x-text="detalle.recomendacion.accion"></p>
                                <p class="text-[13px] text-muted" x-text="detalle.recomendacion.justificacion"></p>
                                <ul class="flex flex-wrap gap-1 text-[11px]" x-show="detalle.recomendacion.pendientes.length">
                                    <template x-for="p in detalle.recomendacion.pendientes" :key="p">
                                        <li class="rounded-sm bg-degraded/12 px-1.5 py-0.5 text-degraded" x-text="`pendiente: ${p}`"></li>
                                    </template>
                                </ul>

                                <div class="mt-1 flex flex-wrap items-center gap-2">
                                    <button type="button" @click="decidir('aprobar')" :disabled="enviandoDecision"
                                        :class="detalle.recomendacion.decidida ? 'border border-border hover:border-border-hover' : 'bg-accent text-white hover:bg-accent-hover'"
                                        class="inline-flex min-h-9 items-center rounded-sm px-4 text-sm font-medium disabled:opacity-50"
                                        x-text="detalle.recomendacion.unidad_id ? 'Aprobar y alertar' : 'Aprobar'"></button>
                                    <button type="button" @click="abrirDescarte()" :disabled="enviandoDecision"
                                        class="inline-flex min-h-9 items-center rounded-sm border border-border px-4 text-sm font-medium hover:border-border-hover disabled:opacity-50">Descartar</button>
                                    <span class="text-xs text-muted" x-show="detalle.recomendacion.decidida">Ya tiene decisión: otra la deja en conflicto.</span>
                                </div>
                                <p class="text-xs text-down" role="alert" x-show="errorDecision" x-text="errorDecision"></p>
                            </div>
                        </template>

                        <template x-if="detalle.estado === 'en_conflicto'">
                            <p class="flex items-center gap-1.5 rounded-sm bg-down/12 px-2 py-1.5 text-xs font-medium text-down">
                                @include('tablero.partials.icono', ['nombre' => 'alerta', 'clase' => 'size-3.5'])
                                En conflicto: hay decisiones distintas sobre la misma recomendación. Se conservan todas.
                            </p>
                        </template>

                        <ol class="flex flex-col gap-1 border-t border-border pt-2 text-xs" x-show="detalle.decisiones.length || detalle.alertas.length">
                            <template x-for="d in detalle.decisiones" :key="d.uuid">
                                <li class="flex flex-wrap gap-x-2">
                                    <span class="font-mono text-muted" x-text="hora(d.creado_en)"></span>
                                    <span class="font-medium" :class="d.accion === 'aprobar' ? 'text-healthy' : 'text-foreground'" x-text="d.accion === 'aprobar' ? 'Aprobada' : 'Descartada'"></span>
                                    <span x-text="`por ${d.operador} en ${d.nodo}`"></span>
                                    <span class="text-muted" x-show="d.motivo" x-text="`· ${d.motivo}`"></span>
                                </li>
                            </template>
                            <template x-for="a in detalle.alertas" :key="a.uuid">
                                <li class="flex flex-wrap gap-x-2">
                                    <span class="font-mono text-muted" x-text="hora(a.enviada_en)"></span>
                                    <span class="font-medium" x-text="`Alerta a ${a.unidad_nombre ?? a.unidad_id}`"></span>
                                    <span class="text-muted" x-text="a.ack_en ? `· acuse de campo ${hora(a.ack_en)}` : '· sin acuse de campo aún'"></span>
                                </li>
                            </template>
                        </ol>
                    </section>

                    <template x-if="detalle.contradicciones.length > 0">
                        <div class="rounded-md bg-degraded/10 p-3 text-[13px]">
                            <p class="flex items-center gap-1.5 font-medium text-degraded">
                                @include('tablero.partials.icono', ['nombre' => 'alerta', 'clase' => 'size-3.5'])
                                Contradicciones (se conservan, nadie las resuelve solo)
                            </p>
                            <ul class="mt-1 list-inside list-disc text-foreground">
                                <template x-for="c in detalle.contradicciones" :key="c.reportes.join()">
                                    <li x-text="c.detalle"></li>
                                </template>
                            </ul>
                        </div>
                    </template>

                    <div class="flex flex-col gap-2">
                        <h3 class="text-sm font-semibold">Reportes originales <span class="font-mono font-normal text-muted" x-text="detalle.reportes.length"></span></h3>
                        <ol class="flex flex-col divide-y divide-border rounded-md border border-border">
                            <template x-for="r in detalle.reportes" :key="r.uuid">
                                <li class="flex flex-col gap-1.5 px-3 py-2.5">
                                    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[11px] text-muted">
                                        <span class="font-mono" x-text="hora(r.hora)"></span>
                                        <span class="font-mono" :title="`dispositivo anónimo ${r.device_id}`" x-text="`disp ${r.device_id}`"></span>
                                        <span class="inline-flex h-4 items-center rounded-sm px-1 font-mono text-[10px] font-semibold" :class="clasePrioridad(r.prioridad)" x-text="r.prioridad ?? '–'"></span>
                                        <span class="font-mono" x-text="`${r.puntaje ?? 0} pts`"></span>
                                        <span class="rounded-sm border border-border px-1" x-text="r.origen === 'IA' ? 'IA' : 'reglas'"></span>
                                        <span class="ml-auto font-mono" :title="r.motivos_incertidumbre.join(' · ') || 'sin motivos'" x-text="`incert. ${porcentaje(r.incertidumbre)}`"></span>
                                    </div>
                                    <p class="text-[13px]" :class="r.texto ? '' : 'italic text-muted'" x-text="r.texto || 'Sin texto'"></p>
                                    <div class="flex flex-wrap gap-1 text-[11px]" x-show="r.banderas_claves.length || r.personas !== null || r.sin_ubicacion || r.truncado">
                                        <template x-for="b in r.banderas_claves" :key="b">
                                            <span class="rounded-sm bg-surface-2 px-1.5 py-0.5" x-text="b"></span>
                                        </template>
                                        <span class="rounded-sm bg-surface-2 px-1.5 py-0.5" x-show="r.personas !== null" x-text="`${r.personas} personas`"></span>
                                        <span class="rounded-sm bg-surface-2 px-1.5 py-0.5" x-show="r.sin_ubicacion">sin ubicación</span>
                                        <span class="rounded-sm bg-surface-2 px-1.5 py-0.5" x-show="r.truncado">texto truncado</span>
                                    </div>
                                </li>
                            </template>
                        </ol>
                    </div>

                    <details class="group text-[13px]">
                        <summary class="flex cursor-pointer list-none items-center gap-1.5 text-sm font-semibold">
                            @include('tablero.partials.icono', ['nombre' => 'cadena', 'clase' => 'size-3.5'])
                            Rastro en el log <span class="font-mono font-normal text-muted" x-text="detalle.eventos.length"></span>
                            <span class="ml-auto text-xs font-normal text-muted group-open:hidden">mostrar</span>
                            <span class="ml-auto hidden text-xs font-normal text-muted group-open:inline">ocultar</span>
                        </summary>
                        <table class="mt-2 w-full text-left text-xs">
                            <thead class="text-[11px] text-muted">
                                <tr class="border-b border-border"><th class="py-1.5 pr-2 font-medium">Evento</th><th class="py-1.5 pr-2 font-medium">Nodo · seq</th><th class="py-1.5 font-medium">Hash</th></tr>
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

    {{-- Descartar exige motivo: diálogo nativo con foco protegido --}}
    <dialog x-ref="dialogoDescarte" @close="motivoDescarte = ''"
        class="m-auto w-[min(92vw,28rem)] rounded-lg border border-border bg-surface p-0 text-foreground shadow-[0_12px_40px_#00000040] backdrop:bg-black/50">
        <form method="dialog" @submit.prevent="confirmarDescarte()" class="flex flex-col gap-3 p-4">
            <h2 class="text-base font-semibold">Descartar recomendación</h2>
            <p class="text-[13px] text-muted">El motivo queda en el log con tu nombre. La recomendación no se borra.</p>
            <label for="motivo-descarte" class="text-sm font-medium">Motivo</label>
            <textarea id="motivo-descarte" x-model="motivoDescarte" rows="3" required maxlength="500"
                placeholder="Ej.: la unidad ya va en camino por radio"
                class="rounded-md border border-border bg-background p-2.5 text-sm placeholder:text-muted focus:border-border-hover focus:outline-none"></textarea>
            <p class="text-xs text-down" role="alert" x-show="errorDecision" x-text="errorDecision"></p>
            <div class="flex justify-end gap-2">
                <button type="button" @click="$refs.dialogoDescarte.close()" class="min-h-9 rounded-sm border border-border px-4 text-sm hover:border-border-hover">Cancelar</button>
                <button type="submit" :disabled="!motivoDescarte.trim() || enviandoDecision"
                    class="min-h-9 rounded-sm bg-foreground px-4 text-sm font-medium text-background disabled:opacity-40">Descartar</button>
            </div>
        </form>
    </dialog>
</div>
</body>
</html>
