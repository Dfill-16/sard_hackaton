<?php

namespace App\Console\Commands;

use App\Sard\Firma\Firmador;
use Illuminate\Console\Command;

/**
 * T-019: genera el par Ed25519 del nodo (sodium_crypto_sign_keypair), guarda el par
 * binario en storage/keys/<nodo>.key — nunca en git — e imprime la pública en base64
 * lista para el .env de los demás nodos (SARD_PUB_B, SARD_PUB_C).
 */
final class LlavesCommand extends Command
{
    protected $signature = 'sard:llaves
        {--nodo= : Nodo del par (por defecto NODE_ROLE)}
        {--forzar : Sobrescribe la llave privada si ya existe}';

    protected $description = 'Genera el par Ed25519 del nodo para firmar lotes (T-019)';

    public function handle(): int
    {
        $nodo = strtoupper((string) ($this->option('nodo') ?? config('sard.nodo_role', 'A')));
        $ruta = storage_path('keys/'.strtolower($nodo).'.key');

        if (is_file($ruta) && ! $this->option('forzar')) {
            $this->error("Ya existe una llave en [{$ruta}]. Use --forzar para regenerarla (las firmas previas quedan inválidas).");

            return self::FAILURE;
        }

        $par = Firmador::generarPar();

        if (! is_dir(dirname($ruta))) {
            mkdir(dirname($ruta), 0700, true);
        }
        file_put_contents($ruta, $par['par']);
        @chmod($ruta, 0600); // best-effort: en Windows el SO no aplica permisos POSIX

        $this->info("Par Ed25519 del nodo [{$nodo}] guardado en [{$ruta}] (fuera de git).");
        $this->line('Pública (base64): péguela en el .env de los OTROS nodos.');
        $this->line('  SARD_PUB_'.$nodo.'='.$par['publica']);

        return self::SUCCESS;
    }
}
