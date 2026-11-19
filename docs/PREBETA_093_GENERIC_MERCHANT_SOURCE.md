# Pre-beta 0.9.3 — Multicomercio BCABH / DOABH / siguientes tiendas

**Objetivo:** que el puente PrestaShop ↔ Salesforce Marketing Cloud Growth + Data Cloud no exija modificar PHP para incorporar una nueva tienda. Esta versión **no crea automáticamente nuevos objetos ni fuentes en Salesforce**.

## Modelo de configuración (Back Office)

- **Código de comercio:** cualquier identificador válido en mayúsculas con números/guiones bajos, por ejemplo `BCABH` o `DOABH`. No hay una lista fija de comercios autorizados en el código.
- **Identificador de instalación:** lowercase, números/guiones bajos, estable. Para instalaciones nuevas el Back Office propone automáticamente `pssfmc_<hex aleatorio>`; **solo se guarda al pulsar Guardar**. Si ya existe un identificador, no se modifica ni sustituye.
- **Fuente de ingesta Data Cloud (API Name):** cada instalación necesita un origen real de Salesforce compatible con `PSCustomer` y `PSOrder` (PST v0.4). Para `ABH` y `BCABH`, se conserva **sin ningún cambio** la fuente histórica `ABH_PrestaShop_Data_Cloud`. Las nuevas tiendas, incluida DOABH, **deben introducir su propio API Name existente en Salesforce**. `DO_PrestaShop_Data_Cloud` es solo un ejemplo de nomenclatura, NO se afirma que exista.
- **Declaración explícita del responsable:** en una nueva fuente, se requiere `CONFIRMO_ORIGEN_Y_ESQUEMA_DATA_CLOUD`. La declaración **no sustituye la verificación técnica de que existe el origen y el mapeo**.
- **Credenciales OAuth:** cifradas por tienda como en 0.9.1. Guardar identidad o fuente no realiza OAuth nuevo ni POST; el botón separado Probar conexión revisa Core+Data Cloud sin enviar clientes.

## Reglas de aislamiento

La vinculación almacena `merchant + namespace + source + attested` en la configuración de la tienda (id_shop). El sender 1+1 relee esa vinculación y exige la misma combinación al seleccionar el origen de **ambos** POST. Si faltan o no coinciden, no hay POST. Una fuente de DO nunca puede ser `ABH_PrestaShop_Data_Cloud`. La fuente está bloqueada contra cambios una vez vinculada; actualizar una fuente requiere migración controlada por separado.

La protección de doble clic, reserva pre-POST, resultado HTTP 202 sin confirmar, timeout retenido, pedido condicionado al cliente DLO, OAuth y ausencia de campañas siguen intactos. La versión 0.9.3 no crea nuevos Data Streams/DMO, no hace backfill, no arranca cron, no borra datos G3 ni cambia Marketing Cloud Growth por otra plataforma.

## Ruta de validación

**BC producción:** actualizar desde 0.9.2 → 0.9.3 solo con backup y aprobación. `BCABH` + namespace existente se conservan; origen histórico automático; credenciales válidas se conservan. El piloto 1+1 sigue pendiente de DLO/DMO reales.

**DO:** instalar el mismo módulo en su propio PrestaShop, rellenar `DOABH`, guardar el namespace aleatorio propuesto, configurar las credenciales cifradas de la tienda y **obtener/confirmar en Salesforce un origen propio** con PSCustomer/PSOrder, luego introducir su API Name y declaración. Validar localmente un cliente y pedido autorizados, probar OAuth y solo después autorizar el primer POST. No utilizar el origen BC aunque DO comparta organización Salesforce.

**Límite importante:** el nombre de la fuente se configura por id_shop. Las credenciales también. En instalaciones multitienda históricas, `PS_GROWTH_MERCHANT` y `PS_GROWTH_NAMESPACE` pueden existir como configuración global heredada; no reutilizar una identidad global entre tiendas sin una migración de alcance por tienda. La certificación de esta versión cubre principalmente instalaciones independientes de BC y DO y el aislamiento del selector de origen, no una migración completa de identidades entre tiendas dentro de un mismo PrestaShop multitienda.

**Certificación técnica:** tests sintéticos no-red para BCABH, ABH, DOABH, otros comercios, source obligatorio, cross-shop/cross-merchant/cross-namespace, fuente BC no reutilizable, binding immutable, upgrade no destructivo, matriz PS 1.7/8/9. La prueba real de origen DO permanece pendiente hasta que exista en Salesforce.
