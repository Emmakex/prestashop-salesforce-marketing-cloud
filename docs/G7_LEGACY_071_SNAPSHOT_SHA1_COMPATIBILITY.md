# G7 — Compatibilidad exacta del snapshot histórico 0.7.1

**16/11/2026 · Código histórico verificado, sin acceso SQL ni envíos.** La versión instalada en Barcelona Confort permanece **0.7.1 Test**. No reinstalar, no leer registros personales ni modificar tablas.

## Evidencia primaria: repositorio antes del refactor Growth-only

Se ha recuperado el commit histórico **[527fe127](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/commit/527fe127a681cf3869611e3cbceac36421a28aff)**, que incluye un módulo cuya propiedad `$this->version` declara **0.7.1**. No se trata de una reconstrucción hipotética ni de la documentación de CART_CONTRACT.

Fuentes exactas:

- [CartNormalizer.php@527fe127](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/blob/527fe127a681cf3869611e3cbceac36421a28aff/pssalesforcebridge/src/Cart/CartNormalizer.php): crea un array ordenado que contiene identidad de carrito/tienda/cliente, contacto, nombre, email, preferencias, flags del cliente, moneda, total, productos, fecha de actividad y líneas normalizadas (SKU/nombre/precio/cantidad incluidos).
- [CartCaptureService.php@527fe127](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/blob/527fe127a681cf3869611e3cbceac36421a28aff/pssalesforcebridge/src/Cart/CartCaptureService.php): **después** de normalizar, añade `eligible`, `eligibility_reason`, `consent_mode`, `status` y `abandon_after`, y calcula exactamente `sha1(json_encode($payload))`.
- [CartRepository.php@527fe127](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/blob/527fe127a681cf3869611e3cbceac36421a28aff/pssalesforcebridge/src/Cart/CartRepository.php): guarda ese digest en `sfmc_cart.snapshot_hash`, además del JSON completo `sfmc_cart.payload`.
- [pssalesforcebridge.php@527fe127](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/blob/527fe127a681cf3869611e3cbceac36421a28aff/pssalesforcebridge/pssalesforcebridge.php): `sfmc_cart.snapshot_hash` es **CHAR(40)**; también lo son las columnas equivalentes de recuperación y eventos.
- [RecoveryService.php@527fe127](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/blob/527fe127a681cf3869611e3cbceac36421a28aff/pssalesforcebridge/src/Recovery/RecoveryService.php): el enlace histórico compara **hash guardado en token** contra **hash guardado en `sfmc_cart`**, sin recalcular el carrito actual desde PrestaShop en ese método. La protección contra cambios depende del proceso de actualización/captura; no es un chequeo nativo fresco por sí mismo.

**Corrección importante frente al PR #119:** el protocolo anterior asumía **SHA-256 y 64 caracteres**. Es **incompatible** con 0.7.1. El nuevo contrato G7 usa únicamente **SHA-1, 40 caracteres** en esta ruta de compatibilidad. El hash de 256 bits de los *tokens* de recuperación es un concepto distinto, y NO debe confundirse con `snapshot_hash`.

## Cambios hechos

- `GrowthCartSnapshotEquivalenceReview` valida el algoritmo `sha1` y el formato `[a-f0-9]{40}` tanto en la descripción de captura como de reconstrucción. El digest sigue siendo **inyectado**, y el resultado sigue `hold_for_review`, no permiso de recuperar.
- `GrowthLegacyCartSnapshotDigestReview` implementa exclusivamente un **replay en memoria** de `sha1(json_encode($payload))` con opciones PHP por defecto. Requiere el mismo **orden** de las 24 claves superiores y de las 9 claves de cada línea, tipos estructurales esperados y scope del carrito/tienda/cliente. Rechaza datos extra, texto no codificable, payload demasiado grande y digest incorrecto. No obtiene datos ni hashes desde SQL, hooks, Salesforce o la web. **No devuelve el hash, email, nombres, importes, ID, fechas ni payload**.
- `tests/g7-legacy-cart-snapshot-sha1-contract.php` fija un **vector de regresión sintético** del algoritmo de 0.7.1: `b6f9a8814d5ecbf74c365bb4e12e58ea12e7317f`. Valida alteraciones de artículos, precios, nombre de cliente, preferencias, último cambio, elegibilidad, estado y `abandon_after`; todas alteran el hash. Valida orden de claves, multitienda, errores y salida saneada. Se ejecuta en CI PHP 7.1 y 8.5.
- El contrato `tests/g7-snapshot-equivalence-contract.php` del corte #119 pasa a SHA-1 y rechaza expresamente hashes SHA-256 en el protocolo histórico.

## Límites importantes antes de cerrar G7

1. **La firma SHA-1 no es una firma autenticada** ni sirve como credencial. Aquí se conserva únicamente como mecanismo de compatibilidad y detección de cambios del estado legado. En un futuro diseño independiente deberá tener versionado/seguridad propios, sin reinterpretar los datos existentes.
2. El hash histórico incorpora campos sensibles y valores derivados del negocio; cualquier futura reconstrucción **solo puede hacerse localmente**, con un adaptador autorizado para leer el estado real y sin registrarlos ni exportarlos. El test solo usa datos inventados.
3. El estado `legacy_sha1_payload_digest_matches_injected_only` prueba que un **payload proporcionado** se corresponde con su digest. **No** que el hash represente el carrito actual, que haya un snapshot fresco completo, que existan 60 minutos de inactividad certificada, o que la supresión/consentimiento Growth permita enviar.
4. Falta un adaptador de **reconstrucción actual real**: el lector de siete SELECT solo confirma existencia de un producto, no las líneas, nombre, precio, currency, email, preferencias o cálculo de elegibilidad y fecha `abandon_after`. Conectar el serializador exige revisar las diferencias entre versiones PrestaShop y el hash histórico, y una estrategia clara para no reactivar el antiguo transporte.
5. Falta el *fence* final por compra/baja y el lector real de consentimiento CSC. No crear PSCart/Flow ni emitir campañas. G5 POST continúa bloqueado por el HTTP 202.

**Corte siguiente implementado solo para revisión:** [reconstrucción local de snapshot fresco desde PrestaShop](G7_FRESH_NATIVE_LEGACY_CART_REVIEW.md) y composición con 7 SELECT actuales. No certifica aún origen de hash histórico ni condiciones para enviar.

**Siguiente corte G7:** integrar un productor fresco **local, minimizado y desacoplado** que compare el hash histórico completo de forma segura, o determinar con pruebas si conviene una señal de cambio del carrito más estable versionada para la ruta Growth sin alterar los registros legados. Solo después abordar el despacho aprobado.
