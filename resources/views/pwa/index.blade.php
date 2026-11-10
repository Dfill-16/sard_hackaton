<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0a0a0a">
    <meta name="description" content="Reporta una emergencia aunque no haya señal. Anónimo.">
    <title>Reportar emergencia · SARD</title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icons/sard.svg" type="image/svg+xml">
    <script>
        try { if (localStorage.getItem('sard-idioma') === 'en') { document.documentElement.lang = 'en'; } } catch (e) {}
    </script>
    @fonts
    @vite(['resources/css/pwa.css', 'resources/js/pwa/app.js'])
</head>
<body class="bg-background font-sans text-foreground antialiased">
<div x-data="pwa(@js($configuracion))" x-init="iniciar()" class="mx-auto flex min-h-dvh max-w-md flex-col">

    {{-- Encabezado: qué es y en qué condiciones está el teléfono --}}
    <header class="sticky top-0 z-10 flex flex-col gap-2 border-b border-border bg-background px-4 pt-3 pb-3">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-baseline gap-2">
                <span class="text-base font-bold tracking-tight">SARD</span>
                <h1 class="text-sm text-muted" x-t>Reportar emergencia</h1>
            </div>
            <div class="flex items-center gap-2">
            @include('partials.selector-idioma', ['clase' => 'min-h-8 px-2 text-xs'])
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border px-2.5 py-1 text-xs font-medium" role="status" aria-live="polite"
                :class="sinConexion() ? 'text-degraded' : 'text-healthy'">
                <span x-show="!sinConexion()">@include('pwa.partials.icono', ['nombre' => 'senal', 'clase' => 'size-3.5'])</span>
                <span x-show="sinConexion()" x-cloak>@include('pwa.partials.icono', ['nombre' => 'sin-senal', 'clase' => 'size-3.5'])</span>
                <span x-text="simularSinRed ? $t('Sin red (simulado)') : (enLinea ? $t('Con señal') : $t('Sin señal'))">Con señal</span>
            </span>
            </div>
        </div>
        <div class="flex items-center gap-2 text-xs text-muted">
            <span x-show="gps.estado === 'lista'" class="inline-flex items-center gap-1.5 text-foreground">
                @include('pwa.partials.icono', ['nombre' => 'ubicacion', 'clase' => 'size-3.5'])
                <span x-text="$t('Ubicación lista · ±:m m', { m: gps.precision })"></span>
            </span>
            <span x-show="gps.estado === 'buscando'" class="inline-flex items-center gap-1.5">
                @include('pwa.partials.icono', ['nombre' => 'ubicacion', 'clase' => 'size-3.5'])
                <span x-t>Buscando ubicación…</span>
            </span>
            <span x-show="gps.estado === 'sin'" x-cloak class="inline-flex items-center gap-1.5">
                @include('pwa.partials.icono', ['nombre' => 'sin-ubicacion', 'clase' => 'size-3.5'])
                <span x-t>Sin ubicación: el reporte se envía igual</span>
            </span>
            <button type="button" x-show="gps.estado === 'sin'" x-cloak @click="ubicar()" class="ml-auto min-h-8 rounded-sm px-2 font-medium text-foreground underline underline-offset-4" x-t>Reintentar</button>
        </div>
    </header>

    <main class="flex grow flex-col gap-8 px-4 pt-5 pb-6">
        <p x-show="aviso" x-cloak x-text="aviso" role="status" aria-live="polite"
            class="flex items-start gap-2 rounded-md bg-healthy/12 px-3 py-2.5 text-sm text-foreground"></p>

        {{-- Toque 1: tipo. Toque final: enviar. Banderas, personas y nota son opcionales. --}}
        <form @submit.prevent="enviar()" class="flex flex-col gap-6" aria-labelledby="titulo-tipo">
            <fieldset class="flex flex-col gap-3">
                <legend id="titulo-tipo" class="mb-3 text-xl font-semibold tracking-tight" x-t>¿Qué está pasando?</legend>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ($configuracion['tipos'] as $tipo)
                        <button type="button" @click="elegirTipo({{ $tipo['valor'] }})"
                            :aria-pressed="tipo === {{ $tipo['valor'] }}"
                            :class="tipo === {{ $tipo['valor'] }} ? 'bg-foreground text-background border-foreground' : 'bg-surface border-border active:bg-surface-2'"
                            class="flex min-h-20 flex-col items-start justify-between gap-2 rounded-lg border p-3 text-left">
                            @include('pwa.partials.icono', ['nombre' => $tipo['icono']])
                            <span class="text-[15px] leading-tight font-medium" x-t>{{ $tipo['nombre'] }}</span>
                        </button>
                    @endforeach
                </div>
            </fieldset>

            <div x-show="tipo !== null" x-cloak class="flex flex-col gap-6">
                <fieldset>
                    <legend class="mb-1 text-base font-semibold" x-t>Si puedes, marca lo que ves</legend>
                    <p class="mb-3 text-sm text-muted" x-t>Opcional. Lo urgente va primero.</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($configuracion['banderas'] as $bandera)
                            <button type="button" @click="alternarBandera({{ $bandera['bit'] }})"
                                :aria-pressed="tieneBandera({{ $bandera['bit'] }})"
                                :class="tieneBandera({{ $bandera['bit'] }}) ? 'bg-foreground text-background border-foreground' : 'border-border active:bg-surface-2'"
                                class="inline-flex min-h-11 items-center gap-1.5 rounded-full border px-3.5 text-sm font-medium">
                                @if ($bandera['critica'])
                                    <span class="size-2 rounded-full bg-p1" aria-hidden="true"></span>
                                @endif
                                <span x-t>{{ $bandera['nombre'] }}</span>
                                @if ($bandera['critica'])
                                    <span class="sr-only" x-t>(urgente)</span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                </fieldset>

                <div class="flex items-center justify-between gap-4 rounded-lg border border-border bg-surface p-3">
                    <div>
                        <p class="text-base font-semibold" id="titulo-personas" x-t>Personas afectadas</p>
                        <p class="text-sm text-muted" x-t>Opcional. Deja «No sé» si no estás seguro.</p>
                    </div>
                    <div class="flex items-center gap-1" role="group" aria-labelledby="titulo-personas">
                        <button type="button" @click="cambiarPersonas(-1)" :disabled="personas === 0" aria-label="Una persona menos" x-t:aria-label
                            class="inline-flex size-12 items-center justify-center rounded-md border border-border disabled:opacity-40">
                            @include('pwa.partials.icono', ['nombre' => 'menos', 'clase' => 'size-5'])
                        </button>
                        <output class="min-w-14 text-center font-mono text-lg font-semibold" aria-live="polite" x-text="personas === 0 ? $t('No sé') : personas">No sé</output>
                        <button type="button" @click="cambiarPersonas(1)" aria-label="Una persona más" x-t:aria-label
                            class="inline-flex size-12 items-center justify-center rounded-md border border-border">
                            @include('pwa.partials.icono', ['nombre' => 'mas', 'clase' => 'size-5'])
                        </button>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <button type="button" x-show="!verDetalles" @click="verDetalles = true; $nextTick(() => $refs.nota.focus())"
                        class="min-h-11 self-start rounded-sm text-sm font-medium underline underline-offset-4" x-t>Agregar una nota corta (opcional)</button>
                    <div x-show="verDetalles" x-cloak class="flex flex-col gap-1.5">
                        <label for="nota" class="text-base font-semibold" x-t>Nota corta</label>
                        <textarea id="nota" x-ref="nota" x-model="texto" @input="limitarTexto()" rows="3" maxlength="140"
                            placeholder="Ej.: hay dos personas atrapadas en la casa azul" x-t:placeholder
                            class="w-full rounded-md border border-border bg-surface p-3 text-base placeholder:text-muted focus:border-border-hover"></textarea>
                        <p class="text-right font-mono text-xs text-muted" x-text="`${bytesDelTexto} / 140 B`"></p>
                    </div>
                </div>
            </div>

            <div class="sticky bottom-0 -mx-4 flex flex-col gap-1.5 border-t border-border bg-background px-4 pt-3 pb-4">
                <button type="submit" :disabled="tipo === null"
                    class="inline-flex min-h-14 w-full items-center justify-center gap-2 rounded-lg bg-accent text-base font-semibold text-white hover:bg-accent-hover disabled:bg-surface-2 disabled:text-muted">
                    @include('pwa.partials.icono', ['nombre' => 'envio', 'clase' => 'size-5'])
                    <span x-text="tipo === null ? $t('Primero elige qué está pasando') : $t('Enviar: :tipo', { tipo: nombreTipo(tipo) })">Primero elige qué está pasando</span>
                </button>
                <p class="text-center text-xs text-muted" x-t>Se guarda en tu teléfono y se envía solo cuando haya señal.</p>
            </div>
        </form>

        {{-- Mis reportes: estado de cada uno, siempre visible --}}
        <section aria-labelledby="titulo-mis-reportes" class="flex flex-col gap-3">
            <h2 id="titulo-mis-reportes" class="flex items-baseline justify-between text-base font-semibold">
                <span x-t>Mis reportes</span>
                <span class="text-sm font-normal text-muted" x-text="pendientes ? $t(':n por confirmar', { n: pendientes }) : (registros.length ? $t('todos recibidos') : '')"></span>
            </h2>
            <p class="text-sm text-muted" x-show="registros.length === 0" x-t>Aún no has enviado reportes desde este teléfono.</p>
            <ul class="flex flex-col gap-2">
                <template x-for="r in registros" :key="r.uuid">
                    <li class="flex flex-col gap-1.5 rounded-lg border border-border bg-surface p-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[15px] font-medium" x-text="nombreTipo(r.tipo)"></span>
                            <span class="font-mono text-xs text-muted" x-text="hora(r.creado_en)"></span>
                        </div>
                        <p class="flex items-center gap-1.5 text-sm font-medium"
                            :class="r.estado === 'confirmado' ? 'text-healthy' : 'text-degraded'">
                            <span x-show="r.estado === 'confirmado'">@include('pwa.partials.icono', ['nombre' => 'check', 'clase' => 'size-4'])</span>
                            <span x-show="r.estado !== 'confirmado'">@include('pwa.partials.icono', ['nombre' => 'reloj', 'clase' => 'size-4'])</span>
                            <span x-text="textoEstado(r)"></span>
                        </p>
                        <p class="flex flex-wrap gap-x-3 font-mono text-[11px] text-muted">
                            <span x-text="`${r.bytes_n} B (JSON ${r.json_n} B)`"></span>
                            <span x-show="r.sin_ubicacion" x-t>sin ubicación</span>
                            <span x-show="r.repeticiones > 0" x-text="$t('actualizado ×:n', { n: r.repeticiones })"></span>
                            <span x-text="r.uuid.slice(0, 8)"></span>
                        </p>
                    </li>
                </template>
            </ul>
        </section>
    </main>

    <footer class="flex flex-col gap-3 border-t border-border px-4 pt-4 pb-6 text-xs text-muted">
        <p class="flex items-start gap-2">
            @include('pwa.partials.icono', ['nombre' => 'escudo', 'clase' => 'size-4'])
            <span><span x-t>Anónimo: no pedimos nombre, teléfono ni documento. Este teléfono se identifica solo con un código al azar</span>
                <span class="font-mono text-foreground" x-text="deviceId ? deviceId.toString(16).padStart(8, '0') : '········'"></span>.</span>
        </p>
        <button type="button" role="switch" :aria-checked="simularSinRed" @click="alternarSinRed()"
            class="flex min-h-11 items-center justify-between gap-3 rounded-md border border-border px-3 text-sm text-foreground">
            <span><span x-t>Simular sin red</span> <span class="text-muted">(demo)</span></span>
            <span class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full"
                :class="simularSinRed ? 'bg-accent' : 'bg-surface-2 ring-1 ring-border'">
                <span class="inline-block size-5 rounded-full bg-foreground" :class="simularSinRed ? 'translate-x-5.5' : 'translate-x-0.5'"></span>
            </span>
        </button>
    </footer>
</div>
</body>
</html>
