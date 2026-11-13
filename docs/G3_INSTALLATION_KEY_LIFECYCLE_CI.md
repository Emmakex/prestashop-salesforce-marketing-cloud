# G3-01 · Claves de instalación: rotación, revocación y desafíos obsoletos

**13/11/2026 · SOLO LABORATORIO DE CI · SIN APROBACIÓN DEL RESPONSABLE.**  
**ABH / Barcelona Confort:** Marketing Cloud **Growth/Next + Data 360**, no Engagement. Módulo distribuido 0.7.1, modo `test`.

## Qué mejora este corte técnico

El [prototipo de desafío externo](G3_EXTERNAL_INSTALLATION_CHALLENGE_CI.md) ya verificaba la posesión de una clave privada y la caducidad/replay de un desafío. Faltaba poder **retirar o sustituir una clave comprometida sin aceptar desafíos emitidos antes del cambio**.

`tests/integration/G3InstallationProofSandbox.php` mantiene ahora:

- `key_version`: contador de generación de clave, inicialmente 1.
- `key_state`: solo `active` para emitir/verificar desafíos; `revoked` bloquea indefinidamente este registro de laboratorio.
- `revoked_at_epoch`: marca de revocación para auditoría en CI.
- Cada desafío contiene **versión y huella SHA-256 de la clave** con la que fue emitido, además del hash del nonce, identidad de instalación y caducidad.

## Transiciones certificadas

| Operación | Condición | Comportamiento |
|---|---|---|
| Alta | Registro global previo y simulación de operador externo | Clave pública registrada, versión 1, estado `active`, **proveedor bloqueado** |
| Rotación | Operador CI simulado, clave activa, huella previa exacta, nueva clave pública distinta | Actualización atómica `key_version + 1`; desafíos previos dejan de ser válidos |
| Revocación | Operador CI simulado, clave activa y huella exacta | Estado `revoked` irreversible **en este laboratorio**; los desafíos pendientes ya no son utilizables |
| Recuperación | Revocación, migración, clon o pérdida de clave | **No implementada**: revisión externa y flujo nuevo necesarios; nunca reactivar por `install()` |
| Verificación | Identidad válida, firma correcta, versión/huella correctas, plazo vigente | Consume nonce **una sola vez**, devuelve exclusivamente validación criptográfica; **no** autoriza esquema, ingesta ni campañas |

La emisión, verificación, rotación y revocación de un mismo registro utilizan transacciones y bloqueo `SELECT ... FOR UPDATE` sobre la fila de clave. Así, la verificación no puede pasar entre una actualización de clave y su invalidación. La firma es RSA 2048–4096 SHA-256 de laboratorio; un diseño real debe revisar algoritmos, soporte de almacén seguro y dependencias.

## Certificación de CI

`tests/integration/g3_proof_key_lifecycle.php` prueba claves y datos **exclusivamente sintéticos** en la base `g3_ci`, sobre MySQL 8 y MariaDB 10.11:

- Rotación/revocación denegadas sin el indicador simulado de operador.
- Rechazo de huella antigua incorrecta, misma clave, proveedor equivocado y doble rotación con huella obsoleta.
- Firma de la clave vieja rechazada después de rotar, firma nueva válida únicamente con un desafío nuevo.
- Desafío anterior rechazado **aunque se firme después con la clave nueva** gracias a `key_version`.
- Firma válida en un nuevo desafío aceptada una vez; reuso rechazado.
- Revocación de clave con desafío pendiente; intento de usarlo, nueva emisión, re-rotación, re-registro o doble revocación rechazados.
- Independencia de otras instalaciones y conservación de las filas de estado revocado hasta limpiar únicamente el fixture.
- Ningún resultado marca `identity_owner_approved`, `data_360_ingestion_authorized` o `marketing_sends_authorized` como verdadero.

La compatibilidad PHP 7.1+ se controla con lint y los tests SQL con el CI existente. No se ha tocado el núcleo ni el módulo distribuido ni se han añadido rutas de Salesforce.

## Bloqueos para llevar esto a producción

**Ningún indicador de entorno de CI constituye autenticación real de un operador.** Antes de crear un registro central funcional es necesario un ADR **aprobado** de arquitectura/seguridad y del responsable de datos que establezca custodia externa de la clave, operador y segundo factor, rotación dual-control, revocación ante pérdida, recuperación, auditoría, retención de desafíos, TLS, disponibilidad y autorización real multitenant. Una clave privada exportable que pueda copiarse con la web continúa permitiendo suplantar la instalación original.

**G3-01 sigue pendiente** de registrar un operador/namespace autorizado y autenticar un despliegue real. G3-02…08 (esquema PSCustomer/PSOrder, fechas, privacidad, retención, DLO/DMO) no se han cerrado. G4–G8 (streams, ingesta, campañas, despliegue) permanecen bloqueados.

No se necesitan SQL de producción, ZIP ni reinstalación. Este ensayo no modifica Barcelona Confort y no autoriza envíos de Marketing Cloud.
