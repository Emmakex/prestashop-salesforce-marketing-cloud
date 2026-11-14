# Roadmap — Marketing Cloud Growth + Data Cloud

**Dirección vigente (14/11/2026):** únicamente Growth + Data Cloud para PrestaShop 1.7.8.x, 8.x y 9.x; no existen proveedores alternativos. Barcelona Confort es el piloto. Consulta [AGENTS.md](AGENTS.md), [contrato](config/project-contract.json), [canon](docs/ABH_GROWTH_CANONICAL_TARGET.md) y [gates](docs/PROJECT_DEVELOPMENT_RULES.md).

## Conexión ya certificada; no repetir

- [x] **G0**: destino correcto, Marketing Cloud Growth + Data Cloud.
- [x] **G1**: aplicación externa y OAuth de Salesforce Core, sin escrituras.
- [x] **G2**: intercambio de token Data Cloud y conector PST (`ABH_PrestaShop_Data_Cloud`) identificados; schema en `Schema Required`.
- [x] ABH/BC mantiene instalado `pssalesforcebridge 0.7.1`, Test, `marketing_optin`, 60 minutos. **No es un circuito Growth de envío**.
- [x] Auditoría de esquema/campos, muestras de clientes/carritos y timestamps sin convertir flags históricos en audiencia aprobada.
- [x] Preparación técnica G3: candidato PSCustomer/PSOrder v0.2 (22 campos, SHA verificado), análisis de PK/FK, opciones de actualización, minimización y decisiones pendientes.

## Entrega del producto Growth-only

- [x] **Corte de arquitectura 0.8.0 en rama**: retirados transportes, procesadores y credenciales del paquete anterior; no se reintroducen alternativas ni se tocan datos históricos. Test obligatorio, cron seguro y BO solo Growth + Data Cloud.
- [ ] Certificar **CI exact-head y runtime real PS 1.7.8.11, 8.2.8 y 9.1.5**, incluida instalación y actualización no destructiva.
- [ ] **G3 BLOQUEANTE**: aprobación responsable de namespace único, PSCustomer/PSOrder, categorización, relación cliente-pedido, fecha fuente UTC/DST, finalidades, consentimiento, conservación, borrado y selección final de PST.
- [ ] **G4**: publicar esquema y activar Data Streams/mapeos SOLO después de aprobación separada.
- [ ] **G5**: /actions/test sintético, luego ingesta sintética autorizada y conciliada.
- [ ] **G6**: identidad estable, consentimiento actual y supresión específica Growth/Core/Data Cloud, revalidación antes de cada campaña.
- [ ] **G7**: Flow/campaña Growth: cliente, pedido y carrito abandonado, frecuencia, conversiones, cancelación y recuperación.
- [ ] **G8**: adaptador completo, almacenamiento/reintentos/ledger, despliegue reversible y go-live autorizado para BC, verificado en 1.7/8/9.

## Límites que no se pueden saltar

La 0.8.0 de desarrollo **no** concede permiso de subir esquemas, leer PII remota, ingerir clientes, ejecutar backfill, ni enviar campañas. Los datos históricos 0.7.1 se preservan hasta decisión expresa del propietario; jamás borrarlos para aparentar una limpieza. Sin repetición de OAuth/Data Cloud y sin solicitar SQL de producción. PRs con CI verde, revisión y resultados reales antes de integración.
