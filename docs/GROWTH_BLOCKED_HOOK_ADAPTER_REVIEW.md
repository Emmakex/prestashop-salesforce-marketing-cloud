# Growth: puente de hooks existentes a outbox local bloqueado (candidato técnico)

**14/11/2026 — Desarrollo implementado, NO conectado a Barcelona Confort.**  
Destino ABH: Marketing Cloud **Growth/Next + Data 360**, no Engagement.

## Qué aporta al producto real

Clase del módulo `pssalesforcebridge/src/Growth/GrowthBlockedHookAdapter.php`:

| Hook de PrestaShop ya existente en 0.7.1 | Dato extraído | Evento interno candidato |
| --- | --- | --- |
| `actionCustomerAccountAdd` | Objeto `newCustomer`, `customer` u `object`; exige `id` + `id_shop` explícitos | `CUSTOMER_CREATED` |
| `actionCustomerAccountUpdate` | El mismo contrato de cliente; sin usar Context como fallback | `CUSTOMER_UPDATED` |
| `actionValidateOrder` | Objeto `order`; exige `id` + `id_shop` explícitos | `ORDER_CREATED` |

La clase **no** registra ni intercepta esos hooks. Solo traduce formas ya conocidas de objetos a `BlockedOutboxCoordinator::capture()`, que aplica `ExplicitProviderRouter` y `StagedAtomicUtcAcceptance` con una operación `insertOrLoad` suministrada por un almacén explícito. Nunca usa email, nombres, referencia, importe, indicador newsletter, fecha histórica ni contexto de tienda como clave de identificación. **Se conserva la huella SHA1 ya calculada por el servicio legado, pasando solamente su dedupe key**; no se vuelve a procesar PII ni se envía payload a Salesforce.

**Límites deliberados**:
- `actionCartSave`, `CART_ABANDONED` y `actionOrderStatusPostUpdate` se rechazan. El primero no dispone de PSCart Data Stream aprobado. El segundo (actualización de pedido) solo tiene `id_order` + estado, necesita resolver entidad/finalidad y demostrar semántica antes de capturarse. `ORDER_PAID` y `ORDER_CANCELLED` no se infieren ni se pierden silenciosamente.
- El **scope es obligatorio y estricto**: no se admite el fallback del adaptador histórico a la tienda 1 si falta `id_shop`, ni `1.0`, `001`, negativos o enteros fuera de rango.
- Solo proveedor Growth explícito, modo Test y namespace pasado al router; namespace válido sintácticamente **no certifica unicidad global** ni custodia independiente (pendiente G3-01).
- El resultado es `status=blocked_local_only`, con secuencia/timestamp de observación UTC y **todos los flags de autorización de envío, worker, Flow e ingesta falsos**. El timestamp de captura no es `Record Modified` de origen, ni asegura orden causal de cambios.
- **Cero cambios en** `pssalesforcebridge/pssalesforcebridge.php`, `install()`, upgrade, cron, configuración de ABH, `sfmc_queue` histórico, Salesforce Core/REST, Data Streams o campañas. El código es *dormant* hasta aprobación G3 y un release autorizado.

## Pruebas ejecutables, sin datos reales

- `tests/growth-blocked-hook-adapter-contract.php`: PHP 7.1/8.5; hooks de cliente/pedido, reintentos, dedupe y scope por instalación/tienda/entidad; rechazo de Engagement, cart, status, objetos ambiguos, IDs malformados, fingerprints cruzados; no llamadas al callback ante entradas inválidas; no se filtra ningún valor de un objeto con email/fecha/total sintéticos.
- `tests/integration/g3_growth_prestashop_db_store.php`: usa **MySQL8 y MariaDB10.11** en `g3_ci`, sin tablas de cliente. Captura y reintenta un hook de cliente y un pedido usando `PrestaShopDbBlockedOutboxStore` real (API `Db` simulada con PDO), verificando filas locales **bloqueadas e idempotentes**, ausencia de PII, rechazo de scope, rollback de tablas CI y protección de `sfmc_*`.
- `tests/runtime/prestashop-growth-db-api-contract.php`: ejecuta el hook bridge puro sobre **PrestaShop 1.7.8.11 / 8.2.8 / 9.1.5** reales con un almacén **en memoria**, sin crear las tablas Growth ni registrar hooks; verifica rechazo del fallback de tienda. El motor MySQL5.7 del runtime sigue bloqueado para DDL Growth.

**Próxima pieza funcional:** una vez autorizada la publicación G3/G4, implementar el contrato de snapshots y revisiones de origen que permita mapear PSCustomer/PSOrder a Data 360 en forma causal. El bloqueo de cart recovery continuará hasta disponer de fuente aprobada, consentimiento por finalidad, supresiones y prueba end-to-end Growth; **aprobar el esquema no equivale a enviar campañas**.

No necesitas reinstalar nada ni proporcionar SQL de producción.
