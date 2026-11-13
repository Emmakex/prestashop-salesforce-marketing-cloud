# Reglas de desarrollo — Puente PrestaShop con Salesforce

**Estado:** contrato de equipo v1 (2026-11-13). Prioridad: evitar desvíos, proteger la producción BC y entregar la primera integración **Marketing Cloud Growth + Data 360**.

## Norte y producto

**Producto:** módulo multiproveedor reutilizable en PrestaShop 1.7.8.x/8.x/9.x. **Primer cliente:** ABH / Barcelona Confort. **Destino ABH comprobado:** Salesforce Marketing Cloud Growth/Next sobre Salesforce Core Lightning, complementado por Data Cloud / Data 360. **No:** Marketing Cloud Engagement.

### Separación de responsabilidades

| Capa | Responsabilidad | Evidencia / estado |
| --- | --- | --- |
| PrestaShop Bridge | Captura clientes, pedidos, carritos; colas; idempotencia; cumplimiento | BC 0.7.1 activo, `test`; adaptador legado Engagement instalado pero NO válido como destino ABH |
| Salesforce Core | OAuth de app externa y APIs REST | OAuth certificado; metadata v68.0 inspeccionada |
| Data 360 | Ingestion API, catálogo, espacios, streams, mapeo | Token exchange certificado; conector PST en `Schema Required`, esquema sin aprobación |
| Marketing Cloud Growth | Segmentación/canales/Flow/campañas | Arquitectura y facultades reales de activación todavía por certificar |
| Marketing Cloud Engagement (legado) | SOAP All Subscribers, Data Extensions, Journey `/interaction/v1/events` | Solo adaptador genérico separado; ABH no tiene un tenant Engagement verificado |

No equiparar autenticación con integración. No asumir que un elemento Lightning llamado “Marketing” significa “Marketing Cloud Engagement”, ni que Salesforce Growth usa `Installed Packages`.

## Clasificación de hechos (usar en cada entrega)

- **VERIFICADO:** comprobación concreta (captura de usuario, SQL aggregate, CI/run con SHA o API autorizada). Indicar fecha y alcance, no extrapolar.
- **DISEÑADO / PROPUESTO:** código o documento que no se ha desplegado o no ha pasado aceptación; no presentarlo como activo.
- **BLOQUEADO:** falta aprobación humana, estado proveedor, privacidad, identidad o esquema.
- **NO CERTIFICADO:** conexión, política, campaña o datos que aún no se han probado.

Ejemplos: `oauth_success` no implica `marketing_send_ready`; `newsletter=1` no implica `send_authorized`; `PS_SFMC_MODE=test` no demuestra ausencia de acciones manuales externas.

## Procedimiento por cada petición “continuemos”

1. Revisar `AGENTS.md`, `config/project-contract.json`, último `main`, PRs, CI y `docs/ABH_GROWTH_CANONICAL_TARGET.md`. No empezar desde un recuerdo de otra variante de Salesforce.
2. Localizar el **último gate no satisfecho**, no buscar un nuevo producto o documentación legacy.
3. Elegir **un corte pequeño** y documentar qué cambio exacto acerca al objetivo comercial: campañas de clientes y carritos abandonados en **Growth**, con métricas y privacidad.
4. Codificar / documentar y probar contra el proveedor correcto. Tests de integración solo con datos sintéticos y scopes mínimos, sin envíos ni cambios de producción por defecto.
5. Completar PR → CI exact-head → merge → CI `main`. No merge rojo, no crear releases por documentos; publicar evidencias verificables.
6. Solicitar intervención del usuario **solo cuando sea necesaria** para aprobación del esquema o alguna configuración de tenant inaccesible; una sola acción concreta, sin repetir preguntas resueltas.
7. Mantener `test` hasta aceptación de cada gate. No contaminar otro merchant/instalación de PrestaShop.
8. Actualizar el estado de gates en roadmap y ADR; no declarar “operativo al 100 %” a medias.

## Bloqueos y autorizaciones

| Gate | Salida obligatoria para marcarlo completado | Estado |
| --- | --- | --- |
| G0 identidad de producto | ABH=Growth+Data 360; provider boundary fijado en repo | VERIFICADO |
| G1 OAuth Core | Run auth-only sin escrituras, scopes, Run As controlado | VERIFICADO |
| G2 Data 360 auth | Token exchange sin escrituras | VERIFICADO |
| G3 esquema PST | Datos PSCustomer/PSOrder, PK tenant, TZ/DST, categorías, retención, minimización, data owner sign-off | **BLOQUEADO** |
| G4 objetos + streams | Esquema publicado **con aprobación separada**, data streams y DLO/DMO revisados | **PENDIENTE** |
| G5 pruebas Data 360 | `/actions/test` sintético; después escritura sintética expresamente autorizada y conciliada | **PENDIENTE** |
| G6 permisos y supresión Growth | Modelo de consentimiento por finalidad y estado de exclusión en Core/Data 360 + identidad global | **PENDIENTE** |
| G7 Flow / campaña Growth | Capacidad real en tenant, audiencia sintética, dedupe, ventana, anti-frecuencia, conversiones | **PENDIENTE** |
| G8 despliegue BC | Release Growth adapter certificado en PS 1.7/8/9, rollback, go-live humano y monitoreo | **PENDIENTE** |

Queda prohibido saltarse gates por haber certificado OAuth. Aprobación de esquema ≠ aprobación de ingesta ≠ aprobación de campañas.

## Reglas técnicas innegociables

- Core común PrestaShop **agnóstico de proveedor**. Adaptadores separados: `growth`, `engagement_legacy`, futuros providers, con selección explícita y fail-closed.
- NUNCA ABH → SOAP Subscriber / All Subscribers / Engagement Data Extensions / Journey Builder API. No crear login/panel/paquete alternativo para ABH. Si se contrata Engagement en el futuro: nuevo ADR y pruebas por tenant.
- Modelo de clave: `merchant_namespace:ps_{shop_id}_{entity_id}` o contrato aprobado equivalente. Email normalizado solo para diagnóstico; no se fusionan personas/pedidos por email.
- Separar usos analíticos de permisos de envío. Gestionar consentimientos y supresiones específicos de Growth; un flag `newsletter` no sustituye estados actuales y finalidad.
- Zona `Europe/Madrid` en tienda; fechas MySQL sin TZ: no inventar UTC ni ordenar eventos usando fechas ambiguas como clock global.
- Sin datos de clientes reales ni secretos en repositorio, conversaciones, artefactos, logs o pruebas. Sin URLs de recuperación en Data 360.
- No tocar infraestructura/tenants de otras tiendas por trabajar en BC. El piloto **no** convierte el puente en un plugin solo para BC.
- Prohibido habilitar `Production`, backfill real, Data 360 ingest, envíos, nuevos permisos o publicar esquema **sin aprobación separada y específica**.
- Si la consulta o herramienta no permite demostrar un hecho, reconocerlo y solicitar la mínima comprobación necesaria. No inventar resultados, links, APIs ni campos.

## Control de cambios

Cada PR incluirá: comercio/proveedor, objetivo, gate, hechos ya verificados, archivos cambiados, pruebas, impacto de producción, datos personales, secretos y permisos, compatibilidad (1.7/8/9, PHP 7.1+), necesidad de ZIP, reversibilidad. Las reglas anteriores son contractuales; el gate automatizado solo verifica **invariantes documentales** y no demuestra el cumplimiento de todas.

Cambios al producto elegido, esquema PST, ruta de autenticación, finalidad del tratamiento, supresiones, estrategia multiinstalación o paso a Production requieren **ADR** previo en `docs/adr/` con motivo, alternativas, impacto, evidencia y aprobación del responsable, además de CI.

### Antídoto a la repetición

Antes de pedir credenciales, capturas, SQL o permisos, buscar en historial/versionado si ya fue entregado. Reutilizar las certificaciones OAuth/Token y solo pedir evidencia de gates aún abiertos. Un PR documental no obliga a subir nuevo ZIP.

## Siguiente paso fijado

**G3:** revisar `schemas/ABH_PST_SCHEMA_REVIEW.yaml` / `docs/DATA_CLOUD_PST_SCHEMA_APPROVAL.md` y preparar la matriz final PSCustomer/PSOrder de claves, datos, retención, zona horaria y mapping para decisión de data owner. **No subir todavía el esquema**, ni crear flujos de datos ni activar campañas.

Referencias: `AGENTS.md`, `config/project-contract.json`, `docs/ABH_GROWTH_CANONICAL_TARGET.md`, `DATA_CLOUD_ABH_DECISIONS.md`, `GROWTH_AUTH_CERTIFICATION.md`, `DATA_CLOUD_AUTH_REUSE.md`.
