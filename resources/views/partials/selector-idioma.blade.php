{{-- Selector de idioma (ES / EN). Muestra el idioma al que se cambia; el elegido se recuerda en el navegador. --}}
<button type="button" @click="$store.idioma.alternar()"
    :aria-label="$store.idioma.actual === 'es' ? 'Switch to English' : 'Cambiar a español'"
    :title="$store.idioma.actual === 'es' ? 'Switch to English' : 'Cambiar a español'"
    aria-label="Switch to English"
    class="inline-flex shrink-0 items-center justify-center rounded-sm border border-border font-mono font-semibold text-muted hover:border-border-hover hover:text-foreground {{ $clase ?? 'h-8 px-2 text-xs' }}">
    <span x-text="$store.idioma.actual === 'es' ? 'EN' : 'ES'">EN</span>
</button>
