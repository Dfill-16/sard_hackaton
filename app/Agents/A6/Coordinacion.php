<?php

namespace App\Agents\A6;

use App\Agents\A3\Vecindad;
use App\Sard\Log\EventLog;
use App\Sard\Reglas\Banderas;
use App\Sard\Reglas\PrioridadIncidente;
use App\Sard\Reglas\Puntaje;
use App\Sard\Support\Uuid7;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A6 · Coordinación (líder, plantillas, sin LLM): propone una acción por incidente.
 *
 * Solo recomienda. Nada se despacha sin una DecisionHumana (REQ-016): la alerta a campo
 * la emite aprobar(), que solo se llama desde POST /api/decisiones.
 */
final class Coordinacion
{
    public const MODO = 'plantilla';

    public const ACCION_ESCALAR = 'escalar a la central';

    public const ACCION_CONTACTAR = 'contactar a la central';

    public const LARGO_MAXIMO_ALERTA = 120;

    /** Banderas que piden bomberos (T-010, paso 2). */
    private const BANDERAS_BOMBEROS = ['atrapada', 'derrumbe', 'incendio', 'explosion', 'gas', 'cables'];

    private const TIPOS_BOMBEROS = [1, 2, 4, 5, 6, 7];

    private const BANDERAS_AMBULANCIA = ['herido', 'respiratoria'];

    public const MODO_LLM = 'llm';

    public function __construct(
        private EventLog $log,
        private RedaccionLlm $redaccion,
    ) {}

    /**
     * Emite RecomendacionPropuesta si el incidente no tiene una, o si se volvió masivo
     * o cambió de prioridad sin una decisión humana sobre la última recomendación.
     *
     * Con LLM habilitado, la justificación la redacta el LLM (modo "llm"); las re-propuestas que
     * ocurren dentro de una decisión humana usan la plantilla para no demorar la respuesta.
     *
     * @return string|null uuid de la recomendación emitida
     */
    public function evaluar(string $incidenteUuid, bool $redactarConLlm = true): ?string
    {
        $incidente = DB::table('incidentes')->where('uuid', $incidenteUuid)->first();
        if ($incidente === null) {
            return null;
        }

        $prioridad = $this->prioridadSinEnvejecer($incidente);
        $ultima = DB::table('recomendaciones')->where('incidente_uuid', $incidenteUuid)->orderByDesc('version')->first();

        if ($ultima !== null) {
            $decidida = DB::table('decisiones')->where('recomendacion_uuid', $ultima->uuid)->exists();
            $cambio = $ultima->prioridad_al_proponer !== $prioridad
                || (bool) $ultima->masivo_al_proponer !== (bool) $incidente->masivo
                || ($ultima->unidad_id !== null && ! $this->unidadDisponible($ultima->unidad_id));
            if ($decidida || ! $cambio) {
                return null;
            }
        }

        $recomendacionUuid = Uuid7::generar();
        $version = DB::table('recomendaciones')->where('incidente_uuid', $incidenteUuid)->count() + 1;
        $propuesta = $this->proponer($incidente, $prioridad);
        $redaccion = $redactarConLlm
            ? $this->redaccion->redactar($incidenteUuid, $version, $propuesta)
            : ['justificacion' => $propuesta['justificacion'], 'modo' => self::MODO];

        $this->log->append('RecomendacionPropuesta', [
            'recomendacion_uuid' => $recomendacionUuid,
            'incidente_uuid' => $incidenteUuid,
            'version' => $version,
            ...$propuesta,
            'justificacion' => $redaccion['justificacion'],
            'modo' => $redaccion['modo'],
        ], $incidenteUuid);

        return $recomendacionUuid;
    }

    /**
     * Unidad, acción, justificación y pendientes para un incidente.
     *
     * @return array{unidad_id: string|null, accion: string, justificacion: string, pendientes: array<int, string>, distancia_m: float|null}
     */
    public function proponer(object $incidente, string $prioridad): array
    {
        $miembros = $this->miembros($incidente->uuid);

        $pendientes = [];
        if ($miembros->every(fn (object $m): bool => $m->personas === null)) {
            $pendientes[] = 'personas: desconocido';
        }
        $contradicciones = count(json_decode($incidente->contradicciones, true) ?? []);
        if ($contradicciones > 0) {
            $pendientes[] = "contradicciones: {$contradicciones}";
        }
        if (PrioridadIncidente::posibleRumor($incidente)) {
            $pendientes[] = 'posible rumor: verificar con otra fuente';
        }

        [$tipoUnidad, $pendientesUnidad] = $this->unidadRequerida($incidente, $miembros);
        $pendientes = [...$pendientesUnidad, ...$pendientes];

        $encabezado = sprintf(
            '%s · %s · %d %s de %d %s · confianza %d%%',
            $prioridad,
            mb_strtoupper(mb_substr(Banderas::nombreTipo((int) $incidente->tipo), 0, 1)).mb_substr(Banderas::nombreTipo((int) $incidente->tipo), 1),
            $incidente->reportes_n,
            (int) $incidente->reportes_n === 1 ? 'reporte' : 'reportes',
            $incidente->dispositivos_n,
            (int) $incidente->dispositivos_n === 1 ? 'dispositivo' : 'dispositivos',
            (int) round($incidente->confianza * 100),
        );

        if ((bool) $incidente->sin_ubicacion || $incidente->lat === null) {
            return [
                'unidad_id' => null,
                'accion' => self::ACCION_CONTACTAR,
                'justificacion' => $encabezado.' · sin ubicación',
                'pendientes' => ['confirmar ubicación', ...$pendientes],
                'distancia_m' => null,
            ];
        }

        $unidad = $tipoUnidad === null ? null : $this->unidadMasCercana($tipoUnidad, (float) $incidente->lat, (float) $incidente->lon);
        if ($unidad === null) {
            return [
                'unidad_id' => null,
                'accion' => self::ACCION_ESCALAR,
                'justificacion' => $encabezado.' · '.($tipoUnidad === null ? 'tipo de unidad sin definir' : "sin {$tipoUnidad} disponible"),
                'pendientes' => [$tipoUnidad === null ? 'definir tipo de unidad' : 'sin unidad disponible', ...$pendientes],
                'distancia_m' => null,
            ];
        }

        return [
            'unidad_id' => $unidad['id'],
            'accion' => "enviar {$unidad['nombre']}",
            'justificacion' => sprintf('%s · %s a %d m', $encabezado, $unidad['nombre'], $unidad['distancia_m']),
            'pendientes' => $pendientes,
            'distancia_m' => $unidad['distancia_m'],
        ];
    }

    /**
     * Tipo de unidad que necesita un incidente, según las banderas (marcadas o inferidas) de todos
     * sus reportes y su tipo. Lo usan A6 y el cálculo de brechas del tablero (T-017).
     *
     * @param  Collection<int, object>|null  $miembros  reportes del incidente (se consultan si no se pasan)
     * @return array{0: string|null, 1: array<int, string>}
     */
    public function unidadRequerida(object $incidente, ?Collection $miembros = null): array
    {
        $banderas = 0;
        foreach ($miembros ?? $this->miembros($incidente->uuid) as $miembro) {
            $personas = $miembro->personas === null ? null : (int) $miembro->personas;
            $banderas |= Puntaje::calcular((int) $miembro->banderas, $personas, (string) $miembro->texto)['banderas_efectivas'];
        }

        return $this->tipoDeUnidad((int) $incidente->tipo, Banderas::claves($banderas));
    }

    /** @return Collection<int, object> */
    private function miembros(string $incidenteUuid): Collection
    {
        return DB::table('incidente_reporte')
            ->join('reportes', 'reportes.reporte_uuid', '=', 'incidente_reporte.reporte_uuid')
            ->where('incidente_reporte.incidente_uuid', $incidenteUuid)
            ->get(['reportes.banderas', 'reportes.personas', 'reportes.texto']);
    }

    /**
     * Regla del paso 2: bomberos por banderas o tipo; ambulancia por herido, respiratoria o tipo médico.
     * Atrapada con herido → bomberos y «ambulancia» en pendientes.
     *
     * @param  array<int, string>  $claves  banderas marcadas o inferidas
     * @return array{0: string|null, 1: array<int, string>}
     */
    public function tipoDeUnidad(int $tipo, array $claves): array
    {
        $bomberos = array_intersect($claves, self::BANDERAS_BOMBEROS) !== [] || in_array($tipo, self::TIPOS_BOMBEROS, true);
        $ambulancia = array_intersect($claves, self::BANDERAS_AMBULANCIA) !== [] || $tipo === 3;

        if ($bomberos) {
            return ['bomberos', $ambulancia ? ['ambulancia'] : []];
        }

        return [$ambulancia ? 'ambulancia' : null, []];
    }

    /**
     * La unidad «disponible» más cercana; las ocupadas o asignadas nunca se recomiendan.
     *
     * @return array{id: string, nombre: string, distancia_m: int}|null
     */
    public function unidadMasCercana(string $tipo, float $lat, float $lon): ?array
    {
        $asignadas = DB::table('unidades_estado')->where('estado', 'asignada')->pluck('unidad_id')->all();

        return DB::table('unidades')
            ->where('tipo', $tipo)
            ->where('estado_inicial', 'disponible')
            ->whereNotIn('id', $asignadas)
            ->get()
            ->map(fn (object $u): array => [
                'id' => $u->id,
                'nombre' => $u->nombre,
                'distancia_m' => (int) round(Vecindad::haversine($lat, $lon, (float) $u->lat, (float) $u->lon)),
            ])
            ->sortBy([['distancia_m', 'asc'], ['id', 'asc']])
            ->first();
    }

    /**
     * La unidad acaba de asignarse: los demás incidentes cuya recomendación pendiente la proponía
     * reciben una versión nueva con otra unidad (evaluar() solo actúa si la última sigue sin decidir).
     */
    private function reproponerPorUnidadAsignada(string $unidadId, string $incidenteAtendido): void
    {
        DB::table('recomendaciones')
            ->where('unidad_id', $unidadId)
            ->where('incidente_uuid', '!=', $incidenteAtendido)
            ->whereNotIn('uuid', DB::table('decisiones')->select('recomendacion_uuid'))
            ->distinct()
            ->pluck('incidente_uuid')
            ->each(fn (string $incidente) => $this->evaluar($incidente, redactarConLlm: false));
    }

    /** Una unidad está disponible si la semilla la marca así y ninguna alerta aprobada la tiene asignada. */
    public function unidadDisponible(string $unidadId): bool
    {
        return DB::table('unidades')->where('id', $unidadId)->where('estado_inicial', 'disponible')->exists()
            && ! DB::table('unidades_estado')->where('unidad_id', $unidadId)->where('estado', 'asignada')->exists();
    }

    /**
     * Registra la decisión humana y, si es «aprobar» con unidad, emite la alerta a campo.
     * Una segunda decisión sobre la misma recomendación se conserva (el incidente queda en conflicto)
     * y nunca dispara una segunda alerta.
     *
     * @return array{decision_uuid: string, alerta_uuid: string|null, en_conflicto: bool}
     */
    public function decidir(object $recomendacion, string $accion, ?string $motivo, string $operador): array
    {
        $previas = DB::table('decisiones')->where('recomendacion_uuid', $recomendacion->uuid)->get();
        $decisionUuid = Uuid7::generar();

        $this->log->append('DecisionHumana', [
            'decision_uuid' => $decisionUuid,
            'recomendacion_uuid' => $recomendacion->uuid,
            'incidente_uuid' => $recomendacion->incidente_uuid,
            'accion' => $accion,
            'motivo' => $motivo,
            'operador' => $operador,
        ], $recomendacion->incidente_uuid);

        $alertaUuid = null;
        $yaAprobada = $previas->contains('accion', 'aprobar');
        if ($accion === 'aprobar' && $recomendacion->unidad_id !== null && ! $yaAprobada) {
            $alertaUuid = Uuid7::generar();
            $this->log->append('AlertaCampoEnviada', [
                'alerta_uuid' => $alertaUuid,
                'decision_uuid' => $decisionUuid,
                'incidente_uuid' => $recomendacion->incidente_uuid,
                'unidad_id' => $recomendacion->unidad_id,
                'texto' => $this->textoAlerta($recomendacion),
            ], $alertaUuid);

            $this->reproponerPorUnidadAsignada($recomendacion->unidad_id, $recomendacion->incidente_uuid);
        }

        return ['decision_uuid' => $decisionUuid, 'alerta_uuid' => $alertaUuid, 'en_conflicto' => $previas->isNotEmpty()];
    }

    /** Texto corto de la alerta (≤ 120 caracteres) con lo que la unidad necesita saber. */
    public function textoAlerta(object $recomendacion): string
    {
        $incidente = DB::table('incidentes')->where('uuid', $recomendacion->incidente_uuid)->first();
        $texto = sprintf(
            '%s %s · %d rep · %.5f,%.5f',
            $recomendacion->prioridad_al_proponer,
            mb_strtoupper(mb_substr(Banderas::nombreTipo((int) ($incidente->tipo ?? 0)), 0, 1)).mb_substr(Banderas::nombreTipo((int) ($incidente->tipo ?? 0)), 1),
            (int) ($incidente->reportes_n ?? 0),
            (float) ($incidente->lat ?? 0),
            (float) ($incidente->lon ?? 0),
        );

        return mb_substr($texto, 0, self::LARGO_MAXIMO_ALERTA);
    }

    /** Prioridad con el ascenso por masivo pero sin envejecimiento (no dispara recomendaciones por reloj). */
    private function prioridadSinEnvejecer(object $incidente): string
    {
        return PrioridadIncidente::efectiva($incidente, now(), tieneDecision: true)['prioridad'];
    }
}
