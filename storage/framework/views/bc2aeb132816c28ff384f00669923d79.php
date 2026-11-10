
<?php ($clase = $clase ?? 'size-6'); ?>
<svg class="<?php echo e($clase); ?> shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <?php switch($nombre):
        case ('colapso'): ?>
            <path d="M4 21V9l6-4v16M10 21h10V11l-4-2" /><path d="M14 13l-1.5 2.5L15 17l-1.5 2.5" /><path d="M3 21h18" />
            <?php break; ?>
        <?php case ('fuego'): ?>
            <path d="M12 21c-3.9 0-6.5-2.6-6.5-6 0-3.5 2.7-5.6 3.5-9 2 1.3 3 3.2 3 5 .9-.6 1.6-1.6 1.9-2.8 2.3 1.9 4.6 4.4 4.6 6.8 0 3.4-2.6 6-6.5 6z" /><path d="M12 21c-1.5 0-2.6-1.1-2.6-2.6 0-1.4 1.2-2.4 2.6-3.9 1.4 1.5 2.6 2.5 2.6 3.9 0 1.5-1.1 2.6-2.6 2.6z" />
            <?php break; ?>
        <?php case ('medico'): ?>
            <path d="M9 3.5h6v5.5h5.5v6H15v5.5H9V15H3.5V9H9z" />
            <?php break; ?>
        <?php case ('ladera'): ?>
            <path d="M2.5 20h19L14 7l-3.5 5.5L8 9z" /><path d="M15 15.5h.01M18 17.5h.01M12.5 18h.01" />
            <?php break; ?>
        <?php case ('agua'): ?>
            <path d="M3 9c1.5 0 1.5-1.2 3-1.2S7.5 9 9 9s1.5-1.2 3-1.2S13.5 9 15 9s1.5-1.2 3-1.2S19.5 9 21 9" /><path d="M3 14c1.5 0 1.5-1.2 3-1.2S7.5 14 9 14s1.5-1.2 3-1.2 1.5 1.2 3 1.2 1.5-1.2 3-1.2 1.5 1.2 3 1.2" /><path d="M3 19c1.5 0 1.5-1.2 3-1.2S7.5 19 9 19s1.5-1.2 3-1.2 1.5 1.2 3 1.2 1.5-1.2 3-1.2 1.5 1.2 3 1.2" />
            <?php break; ?>
        <?php case ('via'): ?>
            <path d="M3 10h18v5H3z" /><path d="M7 10l-3 5M12 10l-3 5M17 10l-3 5M21 10l-3 5" /><path d="M5 15v5M19 15v5M5 10V7M19 10V7" />
            <?php break; ?>
        <?php case ('servicios'): ?>
            <path d="M13 2.5 5 13.5h6l-1 8 8-11h-6z" />
            <?php break; ?>
        <?php case ('otro'): ?>
            <circle cx="12" cy="12" r="8.5" /><path d="M12 7.5v5.5M12 16.5h.01" />
            <?php break; ?>
        <?php case ('ubicacion'): ?>
            <path d="M12 21c-1.5-1.9-6.5-6.6-6.5-11a6.5 6.5 0 0 1 13 0c0 4.4-5 9.1-6.5 11z" /><circle cx="12" cy="10" r="2.3" />
            <?php break; ?>
        <?php case ('sin-ubicacion'): ?>
            <path d="M8.2 5.3A6 6 0 0 1 18 10c0 1.6-.6 3.2-1.4 4.6M14 18.3c-1 1-1.7 1.7-2 2.2-1.5-1.9-6-6.4-6-10.5" /><path d="M3 3l18 18" />
            <?php break; ?>
        <?php case ('senal'): ?>
            <path d="M4 18h.01M8 18v-3M12 18v-6M16 18V9M20 18V6" />
            <?php break; ?>
        <?php case ('sin-senal'): ?>
            <path d="M4 18h.01M8 18v-3M12 18v-6" /><path d="M15 9l5 5M20 9l-5 5" />
            <?php break; ?>
        <?php case ('check'): ?>
            <path d="M4.5 12.5l5 5 10-11" />
            <?php break; ?>
        <?php case ('reloj'): ?>
            <circle cx="12" cy="12" r="8.5" /><path d="M12 7.5V12l3 2" />
            <?php break; ?>
        <?php case ('envio'): ?>
            <path d="M4 12h12M11 6l6 6-6 6" /><path d="M20 5v14" />
            <?php break; ?>
        <?php case ('menos'): ?>
            <path d="M5 12h14" />
            <?php break; ?>
        <?php case ('mas'): ?>
            <path d="M12 5v14M5 12h14" />
            <?php break; ?>
        <?php case ('escudo'): ?>
            <path d="M12 3l7.5 3v5.5c0 4.6-3.2 8.2-7.5 9.5-4.3-1.3-7.5-4.9-7.5-9.5V6z" />
            <?php break; ?>
        <?php case ('microfono'): ?>
            <rect x="9" y="3" width="6" height="11" rx="3" /><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21M8.5 21h7" />
            <?php break; ?>
        <?php case ('altavoz'): ?>
            <path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z" /><path d="M15.5 9a4 4 0 0 1 0 6M18 6.5a7.5 7.5 0 0 1 0 11" />
            <?php break; ?>
    <?php endswitch; ?>
</svg>
<?php /**PATH D:\sard_hackaton\resources\views/pwa/partials/icono.blade.php ENDPATH**/ ?>