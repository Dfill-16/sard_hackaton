<?php

namespace App\Providers;

use App\Sard\Proyeccion\Manejadores\AlertaCampoEnviadaManejador;
use App\Sard\Proyeccion\Manejadores\DecisionHumanaManejador;
use App\Sard\Proyeccion\Manejadores\IncidenteAgrupadoManejador;
use App\Sard\Proyeccion\Manejadores\IncidenteMasivoDeclaradoManejador;
use App\Sard\Proyeccion\Manejadores\RecomendacionPropuestaManejador;
use App\Sard\Proyeccion\Manejadores\ReporteNormalizadoManejador;
use App\Sard\Proyeccion\Manejadores\TriajeAsignadoManejador;
use App\Sard\Proyeccion\Proyector;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Manejadores de proyección por tipo de evento (eventos.md).
     *
     * @var array<string, class-string>
     */
    public const MANEJADORES = [
        'ReporteNormalizado' => ReporteNormalizadoManejador::class,
        'TriajeAsignado' => TriajeAsignadoManejador::class,
        'IncidenteAgrupado' => IncidenteAgrupadoManejador::class,
        'IncidenteMasivoDeclarado' => IncidenteMasivoDeclaradoManejador::class,
        'RecomendacionPropuesta' => RecomendacionPropuestaManejador::class,
        'DecisionHumana' => DecisionHumanaManejador::class,
        'AlertaCampoEnviada' => AlertaCampoEnviadaManejador::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Proyector::class, function (Application $app): Proyector {
            $proyector = new Proyector;

            foreach (self::MANEJADORES as $tipo => $manejador) {
                $proyector->registrar($tipo, fn (array $evento) => $app->make($manejador)($evento));
            }

            return $proyector;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
