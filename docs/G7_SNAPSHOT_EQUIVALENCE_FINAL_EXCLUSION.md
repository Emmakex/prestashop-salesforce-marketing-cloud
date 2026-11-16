# G7 — Equivalencia de snapshot y barrera final de exclusión

**16/11/2026.** Proyecto exclusivamente PrestaShop → Marketing Cloud Growth + Data Cloud. Barcelona Confort permanece **0.7.1 Test**, sin envíos ni cambio de instalación. G5 POST `/actions/test` está en cuarentena; G6 carece todavía de lector actual de consentimiento CSC efectivo por persona.

## Reutilizar el snapshot existente, no reconstruir la recuperación

El contrato de carrito heredado, [CART_CONTRACT.md](../CART_CONTRACT.md), ya establece `sfmc_cart` con **snapshot_hash determinista**, y el flujo de recuperación, [RECOVERY.md](../RECOVERY.md), exige verificar que el snapshot sigue coincidiendo antes de recuperar un carrito. **Ese concepto se conserva**. Este corte no inventa un nuevo hash persistente, no añade columnas y no implementa un segundo motor de captura.

El código Growth 0.8.0, tras retirar el transporte discontinuado, todavía **no tiene un productor certificado** que recalcule exactamente ese digest contra los productos y atributos actuales de PrestaShop. La lectura G7 actual comprueba el carrito y **existencia** de producto, pero no enumera todas las líneas o combinaciones y por tanto **no puede computar el hash histórico**. No hay que presentar una lectura de 7 SELECT como verificación completa de equivalencia de snapshot.

## Corte implementado — protocolo mínimo de comparación, sin conectar fuentes

`GrowthCartSnapshotEquivalenceReview::inspect()` recibe dos pequeñas descripciones **inyectadas** con exactamente estas claves: `id_shop`, `id_cart`, `id_customer`, `origin`, `algorithm` y `digest`.

- **Capturado:** `origin=captured_snapshot`, scope positivo exacto del carrito y **`algorithm=sha1`**, digest hexadecimal de **40 caracteres**, según el código 0.7.1 verificado.
- **Actual:** `origin=fresh_recalculation`, scope y algoritmo iguales, digest de la **reconstrucción actual** que un futuro adaptador tendrá que justificar.
- Comparación con `hash_equals()`; devuelve únicamente un estado: `snapshot_digest_match_injected_only`, `snapshot_digest_changed_hold`, `captured_snapshot_unverified` o `fresh_snapshot_unverified`.
- Se bloquean shops/clientes/carritos cruzados, algoritmo incorrecto, valores vacíos, placeholders, información personal o columnas inesperadas.
- Nunca se devuelven IDs, hashes, filas, datos de productos ni nombre de cliente.

**Incluso dos hashes iguales son una prueba sintética de un protocolo, no de que se hayan recalculado datos reales.** El algoritmo del módulo 0.7.1 ya está verificado: `sha1(json_encode($payload))` sobre el array normalizado completo, ampliado con estado/elegibilidad/fecha de abandono; ver [auditoría fuente histórica](G7_LEGACY_071_SNAPSHOT_SHA1_COMPATIBILITY.md). El productor real fresco sigue SIN conectar. No se sustituye su `snapshot_hash` por un digest nuevo incompatible.

`GrowthCartConversionHoldGate::inspectWithNativeCartSnapshotReview()` integra opcionalmente ese estado con los **siete SELECT frescos ya existentes**: 2 carrito/productos, 1 cliente, 4 pedidos en dos rondas. No añade escrituras ni consultas SQL. Aunque las dos huellas coincidan, sigue devolviendo `hold_for_review`, `cart_snapshot_unverified`, y autorización de envíos siempre `false`. Compra sobrevenida en segunda ronda y baja local mantienen prioridad de exclusión.

Pruebas: `php tests/g7-snapshot-equivalence-contract.php` en PHP 7.1/8.5, con ejemplos de digest cambiado, origen falso, scope cruzado, columnas extra de email, compra en segunda ronda, opt-out y resultado no autorizante. CI y runtime PrestaShop 1.7/8/9.

## Diseño de la futura barrera final; todavía NO implementada

Se conserva **una única fuente de verdad local** por `merchant_namespace + shop + cart + snapshot_hash`, reutilizando el outbox/ledger existente cuando se certifique el esquema y el despliegue G8. No se crea una cola de envío paralela.

1. **Captura y versionado:** guardar el digest del snapshot normalizado original en el almacenamiento autorizado y registrar cuándo se observó la actividad mediante un reloj UTC fiable. Diferenciar observación UTC de la procedencia de `date_upd` local.
2. **Antes de reclamar un intento:** obtener un estado de trabajo no emitido, con control de versión y clave de idempotencia; validar de nuevo hash actual, compra nativa y ledger, cliente/supresión local, frecuencia, consentimiento Growth CSC por suscripción/canal y vigencia.
3. **Exclusión transaccional local:** reclamar/cancelar el trabajo contra una versión de estado, de forma que un comprador o una baja observada antes de la reclamación invalide la recuperación; hooks de pedido y baja actualizan la condición de exclusión. Registrar por qué se canceló, sin filtrar identidad personal al log.
4. **Inmediatamente antes de salida:** volver a consultar señales de compra/supresión que puedan cambiar, y usar la comprobación de consentimiento/aplicación de bajas que ofrece oficialmente el canal Growth. Si hay error, latencia, dato indeterminado o revocación, **no se envía**.
5. **Entrega e idempotencia:** los mensajes externos **no son parte de una transacción MySQL**. Ningún bloqueo local puede garantizar por sí solo que un pedido/baja aparezca después de la última consulta pero antes de que Salesforce entregue el mensaje. La compensación requiere supresión gestionada por Growth y un protocolo de despacho explícito, con auditoría de intentos y cancelación; hasta verificarlo, `final_send_exclusion_fence_certified=false`.

No usar `newsletter=1` ni `optin=1` como consentimiento afirmativo Growth. No enviar al DMO CSC un estado directamente; no activar Flow, Data Streams, PSCart o envío sin permisos y contrato de datos. **No se cierra G7 ni G6 por escribir este diseño.**

## Siguiente tarea realmente necesaria

**Cerrada la investigación del algoritmo histórico**: SHA-1 sobre `json_encode` de 24 campos en orden y todas sus líneas; el `RecoveryService` compara hashes persistidos, no un recálculo nativo. Falta crear y certificar un **productor fresco local** que reproduzca exactamente los mismos campos y su orden (incluidos los datos que cambian por elegibilidad), sin exponer datos privados. El replay sintético ya tiene contrato y vector golden en [G7 compatibilidad SHA-1](G7_LEGACY_071_SNAPSHOT_SHA1_COMPATIBILITY.md). Después diseñar el adaptador de lectura y exclusión final, sin escribir ni contactar Salesforce. Esta tarea es independiente del 404/timeout observado en el diagnóstico G6.

La fase G8 certificará la barrera de despacho y reversibilidad del adaptador completo. Barcelona Confort seguirá en 0.7.1 Test hasta que haya aprobación específica.
