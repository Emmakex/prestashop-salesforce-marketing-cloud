# G3-01 — Reserva de namespaces y detección de clonaciones (solo CI)

**13/11/2026. Proyecto:** conector PrestaShop multi-merchant con proveedor explícito. ABH/Barcelona Confort = Marketing Cloud **Growth + Data 360**, no Engagement.  
**Estado:** contrato técnico de laboratorio **no publicado, no desplegado y pendiente de aprobación de responsable**.

## Problema comprobable

Dos instalaciones de PrestaShop pueden tener el mismo prefijo de tablas,
ID de tienda, clientes y pedidos. Clonar una instalación puede copiar
además la configuración y el `source_namespace`. Un registro por
**base de datos local** no detecta que otra tienda usa ese mismo
namespace: no podemos certificar identidad global con un `UNIQUE`
dentro de cada tienda.

Para una integración reutilizable se necesita un registro
**independiente y autoritativo**, separado de los datos clonables de la
tienda, que vincule un namespace inmutable a una identidad de
instalación emitida o verificada fuera de PrestaShop.

## Lo implementado en el ensayo

`tests/integration/G3NamespaceRegistrySandbox.php` modela ese registro
en la **única base de datos desechable g3_ci**, con un `PRIMARY KEY`
por `source_namespace`, un segundo `UNIQUE` por identificador de
instalación externo (UUID v4 sintético), `merchant`, `provider` y
`status='blocked'`. Se verifican columnas, InnoDB e índices.

La operación `reserve()` intenta el INSERT atómico: el mismo par de
identidades puede repetirse sin cambiar el estado; un namespace ya
reservado por otra instalación, un ID externo que intenta cambiar de
namespace, otro merchant o un proveedor incompatible **se rechazan**.
`inspect()` comprueba exactamente la vinculación y devuelve siempre
`owner_approved=false`, `data_360_ingestion_authorized=false` y
`marketing_sends_authorized=false`.

Un tercero solo puede pasar `inspect()` si presenta exactamente la
identidad que figura en el registro. Por tanto, el servicio definitivo
debe autenticar la procedencia de esa identidad fuera de la tienda y
evitar su copia en clones: **este ensayo no demuestra ese servicio**.

## Tests de aceptación

`tests/integration/g3_namespace_registry.php` comprueba en MySQL 8
y MariaDB 10.11:

- Reserva inicial e idempotencia sin mutación.
- Intento de clonar el namespace con otra identidad de despliegue.
- Intento de reaprovechar la identidad de instalación bajo un namespace nuevo.
- Conflictos de merchant, selector de proveedor y nombres no válidos.
- Dos instalaciones independientes con diferentes namespaces sin colisión,
  una Growth/ABH y otra de laboratorio Engagement, sin ruteo real.
- Bloqueo de aprobación/ingesta/envío, tabla InnoDB y claves únicas.
- Imposibilidad de destruir un registro con reservas; eliminación solo
  de los fixtures sintéticos al finalizar las pruebas del laboratorio.

Las comprobaciones se ejecutan **solo** con `G3_CI_ENABLE=yes_sandbox_only`,
DSN/usuario fijos de `g3_ci`. No hay acceso a BC, SQL real, Salesforce,
identidades reales, credenciales o PII.

## Qué queda para aprobar G3-01

1. El responsable técnico y de datos debe elegir **quién opera el
   registro autoritativo** y quién puede asignar, transferir, revocar y
   recuperar un namespace (sin generar registros oficiales desde CI).
2. Definir identidad del despliegue mediante evidencia **independiente
   de la base de datos clonada**, y validarla cada vez antes de
   activar un adaptador. Un UUID copiado junto a las credenciales
   **no prueba unicidad**. El cambio de dominio, restauración,
   multishop, failover y migración requieren flujo explícito.
3. Registrar colisiones y procesos de resolución, con auditoría y sin
   fusionar perfiles por emails. No eliminar reservas con observaciones
   conservadas ni reutilizar sus namespaces.
4. Integrarlo con el producto **solo después de revisión técnica y
   autorizaciones G3**, sin activar cargas de Data 360 ni campañas.

**Este corte refuerza G3-01 técnicamente pero NO lo cierra.** G3-02…08
también siguen pendientes. No modifica `pssalesforcebridge/` y no
genera ZIP. Barcelona Confort continúa en 0.7.1 Test.
