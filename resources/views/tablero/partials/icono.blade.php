{{-- Íconos de trazo 1.75 px, 24×24, decorativos (aria-hidden): siempre van junto a un texto. --}}
@php($clase = $clase ?? 'size-3.5')
<svg class="{{ $clase }} shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($nombre)
        @case('dispositivos')
            <rect x="3" y="6" width="7" height="12" rx="1.5" /><rect x="14" y="3" width="7" height="15" rx="1.5" /><path d="M6.5 15h.01M17.5 15h.01" />
            @break
        @case('rumor')
            <path d="M4 5h16v11H9l-5 4z" /><path d="M10 9.5a2 2 0 1 1 2.5 1.9c-.3.1-.5.4-.5.7v.4M12 14.5h.01" />
            @break
        @case('reloj')
            <circle cx="12" cy="12" r="8.5" /><path d="M12 7.5V12l3 2" />
            @break
        @case('alerta')
            <path d="M12 4 2.8 19.5h18.4z" /><path d="M12 10v4M12 17h.01" />
            @break
        @case('sin-ubicacion')
            <path d="M8.2 5.3A6 6 0 0 1 18 10c0 1.6-.6 3.2-1.4 4.6M14 18.3c-1 1-1.7 1.7-2 2.2-1.5-1.9-6-6.4-6-10.5" /><path d="M3 3l18 18" />
            @break
        @case('telefono')
            <path d="M5 4h3.5l1.5 4-2 1.5a10 10 0 0 0 6.5 6.5l1.5-2 4 1.5V19a1.5 1.5 0 0 1-1.5 1.5A15.5 15.5 0 0 1 3.5 5.5 1.5 1.5 0 0 1 5 4z" />
            @break
        @case('cerrar')
            <path d="M6 6l12 12M18 6 6 18" />
            @break
        @case('sol')
            <circle cx="12" cy="12" r="4" /><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4" />
            @break
        @case('luna')
            <path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z" />
            @break
        @case('flecha-arriba')
            <path d="M12 19V5M6 11l6-6 6 6" />
            @break
        @case('cadena')
            <path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1" /><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1" />
            @break
        @case('capas')
            <path d="m12 3 9 5-9 5-9-5z" /><path d="m3 13 9 5 9-5" />
            @break
    @endswitch
</svg>
