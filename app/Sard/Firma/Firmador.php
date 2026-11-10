<?php

namespace App\Sard\Firma;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Firma Ed25519 de lotes de eventos entre B y C (T-019 · ADR-003 · REQ-027).
 *
 * El hash de cada evento (T-003) prueba integridad; la firma del lote prueba qué
 * nodo lo envió. Llaves: privada en storage/keys/<nodo>.key — nunca en git — y
 * públicas en .env (SARD_PUB_B, SARD_PUB_C). Todo es activable con SARD_FIRMA:
 * con false no se firma ni se verifica y el comportamiento es idéntico al anterior.
 */
final class Firmador
{
    /** @return array{par: string, publica: string} par binario de sodium y pública en base64 */
    public static function generarPar(): array
    {
        $par = sodium_crypto_sign_keypair();

        return [
            'par' => $par,
            'publica' => base64_encode(sodium_crypto_sign_publickey($par)),
        ];
    }

    /**
     * Firma el lote con la llave privada de este nodo. Firma detached en base64
     * sobre la huella canónica del arreglo de eventos.
     *
     * @param  array<int, array<string, mixed>>  $eventos
     */
    public static function firmar(array $eventos): string
    {
        $par = self::parPrivado();

        return base64_encode(sodium_crypto_sign_detached(Canonico::huella($eventos), sodium_crypto_sign_secretkey($par)));
    }

    /**
     * Verifica la firma del lote con la pública del nodo emisor. false si falta la
     * llave, el nodo no está configurado, la firma no es válida o algún dato falta.
     *
     * @param  array<int, array<string, mixed>>  $eventos
     */
    public static function verificar(array $eventos, ?string $firmaBase64, ?string $nodo): bool
    {
        if ($firmaBase64 === null || $nodo === null) {
            return false;
        }

        $publicaBase64 = config("sard.firma.pub.{$nodo}");
        if (! is_string($publicaBase64) || $publicaBase64 === '') {
            Log::error("Firma: no hay una pública configurada para el nodo [{$nodo}].");

            return false;
        }

        $publica = base64_decode($publicaBase64, true);
        $firma = base64_decode($firmaBase64, true);
        if ($publica === false || $firma === false
            || strlen($publica) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($firma) !== SODIUM_CRYPTO_SIGN_BYTES) {
            Log::error("Firma: pública o firma con formato inválido para el nodo [{$nodo}].");

            return false;
        }

        return sodium_crypto_sign_verify_detached($firma, Canonico::huella($eventos), $publica);
    }

    /** Par de llaves del nodo local (96 bytes) desde la ruta configurada. */
    private static function parPrivado(): string
    {
        $ruta = self::rutaLlave();
        if ($ruta === '' || ! is_file($ruta)) {
            throw new RuntimeException("No existe la llave privada en [{$ruta}]. Genérela con sard:llaves.");
        }

        $par = (string) file_get_contents($ruta);
        if (strlen($par) !== SODIUM_CRYPTO_SIGN_KEYPAIRBYTES) {
            throw new RuntimeException("La llave en [{$ruta}] no es un par Ed25519 válido.");
        }

        return $par;
    }

    /** Ruta absoluta de la llave privada (config admite ruta relativa a la raíz). */
    private static function rutaLlave(): string
    {
        $ruta = (string) config('sard.firma.llave_privada');
        if ($ruta === '') {
            return '';
        }

        $absoluta = $ruta[0] === '/' || $ruta[0] === '\\' || preg_match('/^[A-Za-z]:/', $ruta) === 1;

        return $absoluta ? $ruta : base_path($ruta);
    }
}
