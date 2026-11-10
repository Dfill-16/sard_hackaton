<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Campo · SARD</title>
    <script>
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.classList.add('dark');
        }
        try { if (localStorage.getItem('sard-idioma') === 'en') { document.documentElement.lang = 'en'; } } catch (e) {}
    </script>
    @fonts
    @vite(['resources/css/campo.css', 'resources/js/campo/app.js'])
</head>
<body class="bg-background font-sans text-foreground antialiased">
<div x-data="campo(@js(['pollingMs' => $pollingMs]))" x-init="iniciar()" class="mx-auto flex min-h-dvh max-w-xl flex-col">

    <header class="sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-border bg-background px-4 py-3">
        <div class="flex items-baseline gap-2">
            <span class="text-base font-bold tracking-tight">SARD</span>
            <h1 class="text-sm text-muted" x-t>Equipo de campo</h1>
        </div>
        <div class="flex items-center gap-2">
        @include('partials.selector-idioma', ['clase' => 'min-h-8 px-2 text-xs'])
        <span class="inline-flex items-center gap-1.5 rounded-full border border-border px-2.5 py-1 text-xs font-medium" role="status" aria-live="polite"
            :class="conectado ? 'text-foreground' : 'text-degraded'">
            <span class="size-2 rounded-full" :class="conectado ? 'bg-healthy' : 'bg-degraded'"></span>
            <span x-text="conectado ? (lider ? $t('Central: :lider · época :epoca', { lider, epoca }) : $t('Conectando…')) : $t('Sin conexión con este equipo')">Conectando…</span>
        </span>
        </div>
    </header>

    <main class="flex grow flex-col gap-4 px-4 pt-5 pb-8">
        <div class="flex items-baseline justify-between">
            <h2 class="text-xl font-semibold tracking-tight" x-t>Alertas</h2>
            <span class="text-sm text-muted" x-text="alertas.length ? (sinConfirmar ? $t(':n sin confirmar', { n: sinConfirmar }) : $t('todas confirmadas')) : ''"></span>
        </div>

        <p class="rounded-md bg-down/12 px-3 py-2 text-sm" role="alert" x-show="error" x-cloak x-text="error"></p>

        <p class="rounded-lg border border-dashed border-border p-4 text-sm text-muted" x-show="alertas.length === 0" x-t>
            Sin alertas. Cuando la central apruebe un despacho para tu unidad, aparece aquí con un botón para confirmar que la recibiste.
        </p>

        <ul class="flex flex-col gap-3">
            <template x-for="a in alertas" :key="a.uuid">
                <li class="flex flex-col gap-3 rounded-lg border bg-surface p-4"
                    :class="estado(a) === 'nueva' ? 'border-accent' : 'border-border'">
                    <div class="flex items-center justify-between gap-2">
                        <span class="rounded-sm bg-surface-2 px-2 py-1 font-mono text-sm font-semibold" x-text="a.unidad_id"></span>
                        <span class="font-mono text-xs text-muted" :title="a.enviada_en" x-text="`${hora(a.enviada_en)} · ${haceCuanto(a.enviada_en)}`"></span>
                    </div>
                    <p class="text-lg leading-snug font-medium" :lang="a.texto ? 'es' : null" x-text="a.texto || $t('Alerta sin texto')"></p>

                    <button type="button" x-show="estado(a) === 'nueva'" @click="recibido(a.uuid)" :disabled="enviando === a.uuid"
                        class="inline-flex min-h-14 w-full items-center justify-center gap-2 rounded-lg bg-accent text-lg font-semibold text-white hover:bg-accent-hover disabled:opacity-60">
                        @include('pwa.partials.icono', ['nombre' => 'check', 'clase' => 'size-6'])
                        <span x-text="enviando === a.uuid ? $t('Registrando…') : $t('Recibido')">Recibido</span>
                    </button>

                    <p x-show="estado(a) === 'enviando'" class="flex items-center gap-2 text-sm font-medium text-degraded">
                        @include('pwa.partials.icono', ['nombre' => 'reloj', 'clase' => 'size-4'])
                        <span x-text="$t('Acuse enviado :hora · esperando confirmación de la central', { hora: hora(a.ack_en) })"></span>
                    </p>
                    <p x-show="estado(a) === 'confirmado'" class="flex items-center gap-2 text-sm font-medium text-healthy">
                        @include('pwa.partials.icono', ['nombre' => 'check', 'clase' => 'size-4'])
                        <span x-text="$t('Confirmado por la central :hora', { hora: hora(a.confirmado_en) })"></span>
                    </p>
                </li>
            </template>
        </ul>
    </main>

    <footer class="border-t border-border px-4 py-4 text-xs text-muted" x-t>
        Las alertas solo llegan después de que una persona en la central aprueba el despacho. Datos de demostración.
    </footer>
</div>
</body>
</html>
