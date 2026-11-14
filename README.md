# Puente PrestaShop ↔ Salesforce Marketing Cloud Growth + Data Cloud

**Producto único:** integración reusable para **PrestaShop 1.7.8.x, 8.x y 9.x** con **Salesforce Marketing Cloud Growth (Next)** y **Data Cloud**. No hay selección de proveedor, transporte alternativo ni motor de campañas propio. Las reglas vinculantes están en [AGENTS.md](AGENTS.md), [normas de desarrollo](docs/PROJECT_DEVELOPMENT_RULES.md), [contrato](config/project-contract.json) y [roadmap](ROADMAP.md).

## Estado verificado y objetivo de negocio

El piloto es **ABH / Barcelona Confort**. OAuth de Salesforce Core y el intercambio de token Data Cloud **están certificados sin escrituras**; el conector **PST** (`ABH_PrestaShop_Data_Cloud`) existe y se observó con `Schema Required`. Barcelona Confort mantiene el módulo **0.7.1 Test**, consentimiento local `marketing_optin` y abandono 60 minutos. No se ha certificado una campaña Growth ni ingesta real.

El objetivo es llevar clientes, pedidos y señales de carrito **con permisos correctos** a Data Cloud, para activar **segmentos, campañas y Flows de Growth** y detener la recuperación cuando aparece una compra. Los identificadores deben tener namespace de instalación/tienda, consentimiento y supresión por finalidad y control de actualización/reintentos.

## Módulo fuente 0.8.0 (Growth-only)

Se ha retirado del código empaquetado el transporte anterior, sus writers, colas, endpoints de recuperación y workers. Esta versión **no está instalada en BC** y no hereda permiso de envío alguno. Incluye:

- Registro de tres hooks estándar de cliente y pedido; actualmente ejecutan **preflight sin persistencia**, sin tocar datos personales ni hacer peticiones a Salesforce.
- Back Office exclusivamente Growth + Data Cloud con revisión de metadata/timezone local; fuente PST y modo Test visibles, sin campos de otro producto.
- Health y cron protegidos: reportan Test/bloqueo de transmisión; ninguna llamada remota, ningún backfill ni envío.
- Upgrade `0.7.1 → 0.8.0` **no destructivo**: fuerza Test y no borra tablas, tokens, configuraciones ni datos históricos. El uninstall tampoco borra tablas preexistentes; su limpieza requeriría un proceso independiente aprobado.

**Importante:** `0.8.0` es una refactorización de arquitectura, **no el producto operativo end-to-end**. El siguiente incremento funcional implementará la cola Growth duradera y el mapeo de PST después de superar los gates. No se ha aprobado escribir ni enviar datos reales.

## Puertas de entrega

| Paso | Estado |
| --- | --- |
| G0 producto Growth + Data Cloud | Confirmado |
| G1 Salesforce Core OAuth | Probado, sin escritura |
| G2 token exchange Data Cloud | Probado, sin escritura |
| G3 esquema PSCustomer/PSOrder, privacidad, namespace, retención, mapeo | **Pendiente de conformidad** |
| G4 publicar PST / Data Streams | No autorizado |
| G5 pruebas sintéticas Data Cloud | Pendiente |
| G6 consentimiento y supresión | Pendiente |
| G7 campañas y Flow Growth | Pendiente |
| G8 rollout de BC y recuperación end-to-end | Pendiente |

El diseño G3 v0.2 de 22 campos es **un candidato técnico**, no licencia para publicar; ver [issue #45](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/issues/45). No inferir UTC de fechas locales, ni usar `newsletter` como consentimiento universal.

## Desarrollo y certificación

CI evalúa PHP >=7.1, contrato Growth-only, preflight/seguridad, PST y pruebas MySQL/MariaDB sintéticas. Runtime valida 1.7.8.11/8.2.8/9.1.5. No crear ZIP para BC ni tocar la instalación sin comprobación y autorización específicas. El historial de código anterior queda visible en Git pero **no se distribuye ni ejecuta**.
