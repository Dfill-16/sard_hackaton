<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identidad y topología del nodo (valores por .env.<nodo>)
    |--------------------------------------------------------------------------
    */

    'nodo_role' => env('NODE_ROLE', 'A'),

    'urls' => [
        'A' => env('SARD_URL_A', 'http://127.0.0.1:8001'),
        'CANAL' => env('SARD_URL_CANAL', 'http://127.0.0.1:8010'),
        'B' => env('SARD_URL_B', 'http://127.0.0.1:8002'),
        'C' => env('SARD_URL_C', 'http://127.0.0.1:8003'),
        'D' => env('SARD_URL_D', 'http://127.0.0.1:8004'),
    ],

    'lider_inicial' => env('SARD_LIDER_INICIAL', 'B'),
    'demo' => env('SARD_DEMO', true),

    /*
    |--------------------------------------------------------------------------
    | Parámetros configurables (ver contratos/parametros.md)
    |--------------------------------------------------------------------------
    */

    'canal' => [
        'perdida' => env('CANAL_PERDIDA', 0.30),
        'lat_min_ms' => env('CANAL_LAT_MIN_MS', 200),
        'lat_max_ms' => env('CANAL_LAT_MAX_MS', 3000),
        'desorden' => env('CANAL_DESORDEN', 0.20),
        'ventana_abierta_s' => env('CANAL_VENTANA_ABIERTA_S', 5),
        'ventana_cerrada_s' => env('CANAL_VENTANA_CERRADA_S', 15),
        'mtu_b' => env('CANAL_MTU_B', 200),
        'semilla' => env('CANAL_SEMILLA', 42),
        'saltos' => env('CANAL_SALTOS', 1),
    ],

    'backoff_serie' => array_map('intval', explode(',', (string) env('BACKOFF_SERIE', '1,2,4,8,16,30'))),
    'backoff_jitter' => env('BACKOFF_JITTER', 0.2),

    'a4_envej_min' => env('A4_ENVEJ_MIN', 5),
    'a4_envej_tope' => env('A4_ENVEJ_TOPE', 5),
    'b_envej_min' => env('B_ENVEJ_MIN', 10),
    'b_envej_tope' => env('B_ENVEJ_TOPE', 'P2'),

    'pwa_ventana_repetido_min' => env('PWA_VENTANA_REPETIDO_MIN', 10),

    'dbscan_eps_m' => env('DBSCAN_EPS_M', 300),
    'dbscan_min' => env('DBSCAN_MIN', 2),
    'ventana_min' => env('VENTANA_MIN', 30),
    'masivo_dispositivos' => env('MASIVO_DISPOSITIVOS', 5),

    'latido_s' => env('LATIDO_S', 1),
    'latidos_perdidos' => env('LATIDOS_PERDIDOS', 10),
    'replica_s' => env('REPLICA_S', 1),
    'polling_tablero_s' => env('POLLING_TABLERO_S', 2),

    'llm_habilitado' => env('LLM_HABILITADO', false),

    /*
    |--------------------------------------------------------------------------
    | LLM opcional (capa 7, T-016 · contratos/config.md · ADR-001)
    |--------------------------------------------------------------------------
    | Cadena 1 → 2 → 3 → reglas. Las claves viven solo en .env: nunca en el
    | repositorio ni en los logs. Solo se envían datos sintéticos.
    */

    'llm' => [
        'timeout_s' => env('LLM_TIMEOUT_S', 5),
        'proveedores' => [
            ['url' => env('LLM_1_URL', 'https://generativelanguage.googleapis.com/v1beta/openai'), 'key' => env('LLM_1_KEY'), 'modelo' => env('LLM_1_MODELO')],
            ['url' => env('LLM_2_URL', 'https://openrouter.ai/api/v1'), 'key' => env('LLM_2_KEY'), 'modelo' => env('LLM_2_MODELO')],
            ['url' => env('LLM_3_URL', 'https://integrate.api.nvidia.com/v1'), 'key' => env('LLM_3_KEY'), 'modelo' => env('LLM_3_MODELO')],
        ],
    ],

];
