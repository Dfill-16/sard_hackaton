# SARD

**Reportes de emergencia que llegan aunque no haya señal.**

SARD es un sistema de reporte y coordinación de emergencias pensado para Manizales (Caldas, Colombia), donde los deslizamientos, las inundaciones y las caídas de red pueden ocurrir al mismo tiempo. Un ciudadano reporta desde el celular en 2 a 4 toques, sin registrarse y aunque no tenga señal. El reporte viaja por una red degradada hasta una central que lo clasifica, lo agrupa con otros reportes del mismo evento y propone una acción. **Una persona siempre decide** antes de que una alerta llegue al equipo de campo.

Proyecto desarrollado para un hackathon.

---

## Qué hace

| Vista | Para quién | Qué permite |
|---|---|---|
| **PWA ciudadana** | Cualquier persona | Reportar qué pasa, marcar señales de urgencia, cuántas personas hay y una nota escrita o dictada. Funciona sin conexión: guarda el reporte en el teléfono y lo envía cuando hay señal. Es anónima: no pide nombre, teléfono ni documento. |
| **Tablero de coordinación** | La central | Lista priorizada (P1 a P4), mapa con la confianza de cada incidente, brechas de cobertura, contradicciones, el rastro en el log y la recomendación para aprobar o descartar. |
| **Vista de campo** | Unidades (ambulancias, bomberos) | Recibir las alertas aprobadas y confirmar con «Recibido». |

### Principios

- **Nada se pierde ni se oculta.** Los reportes de baja prioridad o sin ubicación siempre aparecen, con su conteo.
- **La IA sugiere, la persona decide.** El LLM es opcional: solo puede *subir* una prioridad o redactar la justificación. Si no está disponible, el sistema sigue funcionando por reglas.
- **Resistente a fallas.** Si el nodo líder cae, el seguidor asume en unos 10 s. Al volver, ambos unen sus registros sin perder nada.
- **Trazable.** Todo queda en un log de eventos solo de escritura, encadenado con hashes.
- **Incluyente.** Interfaz en español e inglés, dictado de la nota, lectura en voz alta, vibración, compatibilidad con lectores de pantalla (TalkBack, VoiceOver) y botones grandes.

---

## Arquitectura

SARD simula en un solo computador los nodos de un despliegue real. Cada nodo es la misma aplicación Laravel con un rol (`NODE_ROLE`) y su propia base SQLite.

```
 Ciudadano (PWA)                                                        Equipo de campo
      │ POST /api/reportes (binario ≤ 200 B)                                     ▲
      ▼                                                                          │
 ┌─────────┐     ┌──────────────────────┐     ┌──────────────┐  réplica  ┌─────────────┐
 │ Nodo A  │────▶│ CANAL simulado       │────▶│ Nodo B líder │◀─────────▶│ Nodo C      │
 │ :8001   │     │ :8010 pérdida, lat., │     │ :8002        │  latidos  │ seguidor    │
 │ recibe  │     │ desorden, ventanas   │     │ A1 A2 A3 A6  │  failover │ :8003       │
 └─────────┘     └──────────────────────┘     └──────┬───────┘           └─────────────┘
                                                     │ alerta aprobada por una persona
                                                     ▼
                                              ┌──────────────┐
                                              │ Nodo D campo │
                                              │ :8004        │
                                              └──────────────┘
```

### Agentes

| Agente | Dónde | Qué hace | ¿Usa LLM? |
|---|---|---|---|
| **A1** Recepción | Líder | Normaliza el reporte sin inventar datos | No |
| **A2** Triaje | Líder | Asigna P1 a P4 por reglas y calcula la incertidumbre | Opcional: solo sugiere *subir* la prioridad |
| **A3** Correlación | Líder | Agrupa reportes cercanos en tiempo y espacio (DBSCAN); detecta incidentes masivos y posibles rumores | No |
| **A4** Comunicación | Nodo A | Cola de salida con prioridad y reintentos (1-2-4-8-16-30 s) hasta recibir el ACK | No |
| **A5** Resiliencia | B y C | Latidos, failover por época, réplica y unión del log al reconectar | No |
| **A6** Coordinación | Líder | Recomienda la unidad más cercana disponible; la alerta solo sale tras una decisión humana | Opcional: redacta la justificación |

### Tecnologías

Laravel 13 · PHP 8.3+ · SQLite (WAL) · Blade + Alpine.js · Tailwind CSS v4 · Leaflet + PMTiles (Protomaps, mapa sin conexión) · Vite · PHPUnit · `node --test`.

---

## Instalación

### Requisitos

- **PHP 8.3 o superior** con la extensión `pdo_sqlite`. El equipo usó PHP 8.5 con [Laravel Herd](https://herd.laravel.com).
- **Composer 2**
- **Node.js 22 o superior** y npm
- **PowerShell**, para los scripts que levantan los nodos. Se probaron en Windows; en macOS o Linux puedes intentar con PowerShell 7 (`pwsh`), pero no se probó.

### Pasos

```bash
git clone https://github.com/Dfill-16/sard_hackaton.git
```

```bash
cd sard_hackaton
```

```bash
composer setup
```

`composer setup` instala las dependencias de PHP y de Node, crea el `.env` a partir de `.env.example`, genera la clave de la aplicación, corre las migraciones y compila los estilos y scripts.

El mapa de Manizales y Villamaría (`public/tiles/manizales.pmtiles`, unos 4,7 MB) ya viene en el repositorio, así que no hay que descargar nada más.

### Levantar los nodos

```bash
./scripts/start.ps1 -Fresco
```

`-Fresco` empieza con bases vacías. Sin él, conserva los datos anteriores. El script arranca los cinco nodos y sus procesos de fondo (canal, cola, latidos, réplica y alertas), y deja los registros en `storage/logs/sard-<nodo>-<proceso>.log`.

| Vista | URL |
|---|---|
| PWA ciudadana | http://127.0.0.1:8001/pwa |
| Tablero (líder B) | http://127.0.0.1:8002/tablero |
| Tablero (seguidor C) | http://127.0.0.1:8003/tablero |
| Vista de campo | http://127.0.0.1:8004/campo |

Para detener todo:

```bash
./scripts/stop.ps1
```

### Cargar datos de demostración

Con los nodos encendidos:

```bash
php artisan db:seed --class=ReportesDemoSeeder
```

Envía 64 reportes sintéticos al Nodo A, igual que lo haría la PWA, así que pasan por el canal, el triaje, la correlación y la recomendación reales. Incluye casos P1, un incidente masivo, un posible rumor, una brecha de cobertura, una contradicción y reportes sin ubicación. Tardan entre 1 y 4 minutos en llegar todos (más si el LLM está activo).

También puedes generar reportes a tu medida:

```bash
php artisan sard:sembrar 40 --masivo --rumor
```

---

## LLM opcional

El sistema funciona completo sin LLM («modo reglas»). Para activarlo, completa en tu `.env` (nunca en el repositorio) hasta tres proveedores gratuitos compatibles con la API de OpenAI. Se prueban en orden; si todos fallan, se sigue por reglas.

```dotenv
LLM_HABILITADO=true
LLM_TIMEOUT_S=5
LLM_1_URL=https://generativelanguage.googleapis.com/v1beta/openai
LLM_1_KEY=tu-clave-de-google-ai-studio
LLM_1_MODELO=gemini-3.5-flash-lite
LLM_2_URL=https://openrouter.ai/api/v1
LLM_2_KEY=tu-clave-de-openrouter
LLM_2_MODELO=nvidia/nemotron-3.5-super-vl:free
LLM_3_URL=https://integrate.api.nvidia.com/v1
LLM_3_KEY=tu-clave-de-nvidia-nim
LLM_3_MODELO=nvidia/nemotron-3.5-lightning-30b-a3b
```

Para comprobar cada proveedor sin mostrar las claves:

```bash
php artisan sard:llm-probar
```

Al LLM solo se le envían datos del reporte (tipo, banderas, personas y nota), nunca el identificador del dispositivo.

---

## Guion de demostración

1. **Reportar sin señal.** En la PWA activa «Simular sin red», envía un reporte y mira cómo queda «Pendiente». Desactívalo: el reporte se envía solo y la confirmación cambia a «Recibido por la central».
2. **Priorizar.** En el tablero, los P1 aparecen primero. Abre uno para ver sus reportes originales, la confianza, las fuentes y el rastro en el log.
3. **Decidir.** Escribe tu nombre en «Operador» y aprueba la recomendación. La alerta aparece en la vista de campo; al tocar «Recibido», el tablero muestra el acuse.
4. **Caída del líder.**

   ```bash
   ./scripts/matar-nodo.ps1 B
   ```

   El tablero pasa solo a C, que asume como líder con una época mayor en unos 10 s. Vuelve a levantar B con `./scripts/start.ps1 -Nodos B`: se une sin perder eventos.
5. **Integridad.** Verifica la cadena de hashes del log de un nodo. En PowerShell, apuntando a la base de ese nodo:

   ```powershell
   $env:NODE_ROLE = 'B'; $env:DB_DATABASE = "$PWD/database/nodo_b.sqlite"; php artisan sard:verificar-log B
   ```

---

## Pruebas

```bash
php artisan test --compact
```

```bash
node --test tests/js/ resources/js/protocol/
```

Hay pruebas para el protocolo binario (con un fixture compartido entre PHP y JS), los agentes, el canal con pérdida y multisalto, el failover y la unión de nodos, el LLM con proveedores simulados, el tablero, la PWA, la accesibilidad y las traducciones.

---

## Estructura del proyecto

```
app/Agents/        Agentes A1–A6
app/Node/          Controladores por nodo (A: PWA y reportes, B/C: líder, D: campo)
app/Protocol/      Codec binario del reporte (≤ 200 B)
app/Sard/          Log de eventos, proyecciones, reglas, canal y cadena LLM
resources/views/   Tablero, PWA y vista de campo (Blade + Alpine)
resources/js/      Lógica del tablero, la PWA (cola sin conexión, accesibilidad) e idiomas
scripts/           Arranque, parada y caída simulada de nodos
tests/             Pruebas PHP (Feature y Unit) y JS
```

---

## Privacidad

- La PWA no pide datos personales. El teléfono se identifica solo con un código al azar.
- Todos los datos de demostración (reportes, unidades, albergues) son ficticios.
- Las claves de los LLM viven solo en el `.env` local.

---

## Autoría

| | Autor |
|---|---|
| <img src="https://github.com/mduquegi.png" width="48" alt=""> | **[@mduquegi](https://github.com/mduquegi)** |
| <img src="https://github.com/mgama-sketch.png" width="48" alt=""> | **[@mgama-sketch](https://github.com/mgama-sketch)** |
| <img src="https://github.com/Dfill-16.png" width="48" alt=""> | **[@Dfill-16](https://github.com/Dfill-16)** |

Mapa: © [OpenStreetMap](https://www.openstreetmap.org/copyright) · [Protomaps](https://protomaps.com).
