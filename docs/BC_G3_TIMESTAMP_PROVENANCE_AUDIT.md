# G3 · Auditoría de procedencia de marcas temporales BC (read-only)

**Estado: SQL OPCIONAL NO EJECUTADO EN BC.** 13/11/2026.
**Restricción operativa confirmada:** el responsable del proyecto **no tiene
acceso SQL directo a producción**. NO volver a solicitarle phpMyAdmin,
credenciales SQL, túneles o exportaciones. Esta auditoría SQL es una vía
alternativa solo si un DBA autorizado la necesita; no es requisito para
continuar G3.

### Vía principal SIN SQL directo: Back Office ya instalado

La versión 0.7.1 de `pssalesforcebridge` incluye
`ModuleHealthTrait::renderDataCloudReadiness()`,
`PrestaShopReadinessProbe` y `InstallationReadinessDashboard`.
El panel aparece al abrir **Módulos → Gestor de módulos →
PrestaShop Salesforce Marketing Cloud → Configurar**, sección
**“Data Cloud - installation preflight”**. Es una consulta local de
**metadatos** por tienda (`PS_TIMEZONE`, tipos de
`date_add/date_upd` vía `information_schema`) sin consulta de filas
de clientes/pedidos, sin envíos HTTP ni mutaciones.

Si el responsable dispone de acceso al BO y quiere aportar evidencia,
bastará la tabla **“Per-shop zone”** y **“SQL date column types
(metadata only)”**, sin hacer clic en botones de backfill, cron,
retención, prueba de conexión o guardar configuración. Si no dispone
del BO, continuar desde código y datos sintéticos; no solicitarle
nuevas credenciales. El panel NO muestra recuentos DST, ni puede probar
origen horario histórico.

**Estrategia de avance:** conservar G3-03 como pendiente, separar
registros históricos con procedencia no verificable de eventos futuros,
y priorizar un mecanismo futuro de timestamp UTC obtenido en el instante
de captura (con fuente y versión de escritura verificadas). Antes de
habilitarlo, decidir idempotencia, regla de frescura, Record Modified,
DST y aprobación de responsable. No inventar UTC del histórico.

### Consulta SQL opcional (solo DBA autorizado)
**Objetivo:** aportar evidencia a **G3-03** (origen temporal) y **G3-04**
(Record Modified + Refresh Mode) del conector `PST` de **Data 360 para
Marketing Cloud Growth**. No Data Streams, no ingesta, no campañas.

## Evidencia nueva del código oficial exacto de PrestaShop 1.7.8.11

El código upstream **1.7.8.11**, no una rama `develop`, muestra:

- [`classes/ObjectModel.php`](https://github.com/PrestaShop/PrestaShop/blob/1.7.8.11/classes/ObjectModel.php):
  `ObjectModel::add()` asigna `date_add/date_upd` usando
  `date('Y-m-d H:i:s')` (líneas alrededor de 561 y 564), y
  `ObjectModel::update()` asigna `date_upd` con `date()`
  (alrededor de la línea 733).
- [`config/config.inc.php`](https://github.com/PrestaShop/PrestaShop/blob/1.7.8.11/config/config.inc.php):
  en la inicialización habitual se llama a
  `@date_default_timezone_set(Configuration::get('PS_TIMEZONE'))`.
- La tienda BC confirma `PS_TIMEZONE=Europe/Madrid` **actualmente**.
  SQL anterior confirmó que `date_add/date_upd` de
  `bc_customer`/`bc_orders` son `DATETIME`, y que la sesión DB usa
  `SYSTEM` (CET en noviembre), con `NOW()` una hora por delante
  de `UTC_TIMESTAMP()`.

**Inferencia condicionada:** una escritura ordinaria por el
`ObjectModel` estándar, tras bootstrap PHP del sitio y con timezone
`Europe/Madrid`, probablemente dejó la **hora local PHP**, no UTC,
en las columnas. No se ha comprobado el flujo real de cada registro,
ni que todas las escrituras provengan del `ObjectModel`.
Importaciones, overrides, SQL directo, cron CLI, cambios históricos
de PS_TIMEZONE y scripts propios pueden haber grabado valores distintos.
`DATETIME` no codifica zona ni offset. No fijar un único offset para
`Europe/Madrid`, ya que varía con DST.

## Consulta preparada, solo lectura

Archivo: `scripts/sql/bc_g3_timestamp_provenance_aggregate_readonly.sql`.

- Se ejecuta en la **base de producción BC**, pese a su nombre parecido
  a pruebas, y utiliza exclusivamente `bc_` y `id_shop = 1`.
  Comprobar que es la tienda correcta antes de ejecutarla.
- Primer resultado: configuración `PS_TIMEZONE` por ámbito,
  categorizando zonas no esperadas como `OTHER_ZONE_REVIEW`.
- Segundo resultado: **exactamente dos filas de recuentos**,
  `bc_customer` y `bc_orders` en shop 1. Sin emails, nombres,
  identificadores de clientes/pedidos, IPs o fechas individuales.
- Indicadores agregados de nulidad/cero, `date_upd < date_add`,
  valores posteriores a reloj sesión/UTC +15min, recuentos últimos
  siete días y ventanas de la hora omitida/repetida de
  `Europe/Madrid` en 2025 y 2026.

### Cómo interpretar los resultados

| Observación | Interpretación lícita | Lo que NO demuestra |
| --- | --- | --- |
| `db_session_minus_utc_minutes` distinto de cero | Diferencia de reloj SQL actual | Zona histórica usada por PHP, importadores y otros procesos |
| Futuros respecto a `UTC_TIMESTAMP()` pero no respecto a `NOW()` | Posible componente de hora local en filas recientes | Que todas las fechas sean locales o puedan convertirse con seguridad |
| `date_upd < date_add` | Anomalía potencial / reimportación / orden inconsistente | Corrupción o causa específica sin revisar registros localmente |
| Filas en la ventana 02:00–03:00 del salto de marzo | Caso a revisar: hora no existente si se pretendió Europe/Madrid local | Que el dato sea imposible si realmente lo escribió una fuente UTC |
| Filas en 02:00–03:00 del retroceso de octubre | Hora local ambigua **si** proviene de esa zona | El offset correcto; se necesitan logs absolutos |
| Cero anomalías detectadas | La auditoría no halló esos casos concretos | **No** certifica origen UTC/local ni exactitud de timestamps |
| `source_updated_local` como campo TEXT | Se puede conservar como atributo diagnóstico tras aprobación de minimización | Que pueda ser Record Modified |
| `source_updated_utc` en esquema v0.3 | Campo técnico potencial para un stream futuro | Que se pueda construir desde histórico solo porque `PS_TIMEZONE` actual sea Madrid |

## Plan de evidencia para salir de G3-03

1. Ejecutar SQL read-only y guardar **solo los dos resultados agregados**.
2. Confirmar si los dos tipos de registros se escriben en BC por
   `ObjectModel` estándar, módulos, colas, integraciones y cargas SQL
   históricas, incluyendo cambios de `PS_TIMEZONE` si los hubo.
3. Contrastar al menos un evento reciente sintético/seguro con reloj
   absoluto conocido de logs técnicos, evitando exponer PII.
   El reloj MySQL y el PHP app no se pueden dar por iguales.
4. Para DST histórico: si no existe evidencia independiente del instante,
   marcar la fila ambigua como `quarantine` y no inventar UTC.
5. Definir *por origen y cohort* qué registros tienen procedencia de
   tiempo verificable y qué hacer con cambios fuera de orden.
6. Revisar esa evidencia con responsable técnico y de datos. No marcar
   G3-03 como aprobado basándose únicamente en un patrón SQL.

## Límite de producción

El proyecto debe conservar `PSCustomer`/`PSOrder` v0.2 y v0.3 como
borradores hasta cerrar G3. Este corte no cambia código PHP instalado
0.7.1, ni modifica schema YAML, configuración, PK, Data Space,
OAuth/permiso, mapeo, streams, retención, envíos o cron.

CI verifica que el SQL versionado sea aggregate-only en shop 1, use
solo SELECT y no extraiga campos personales. Esto es una protección
estática adicional, **no** sustituto de revisión y permisos SQL de solo lectura.
