# AGENTS.md — reglas obligatorias del puente PrestaShop ↔ Salesforce

**Leer antes de cualquier análisis, diseño, respuesta técnica, commit o PR en este repositorio.** Aplica a asistentes, colaboradores y automatizaciones. La prioridad es entregar una integración **reutilizable para PrestaShop 1.7.8.x, 8.x y 9.x**; ABH / Barcelona Confort es la tienda piloto, no un proveedor universal.

## 1. Fuente de verdad y producto elegido

1. Para **ABH / Barcelona Confort**, el producto certificado es **Salesforce Marketing Cloud Growth (Next) en Salesforce Lightning/Core + Data Cloud / Data 360**, NO Marketing Cloud Engagement.
2. **No** enviar al usuario a `mc.exacttarget.com`, Engagement Installed Packages, SOAP Subscriber, All Subscribers, Publication Lists, Engagement Data Extensions o Journey Builder `/interaction/v1/events` para completar ABH.
3. Existe un adaptador **histórico de Engagement** en el módulo 0.7.1; conservarlo aislado para futuros comerciantes que realmente contraten Engagement. **Nunca** deducir que porque el código lo contiene ABH tiene ese producto.
4. Consulta el contrato versionado `config/project-contract.json`, `docs/ABH_GROWTH_CANONICAL_TARGET.md` y el apartado inicial de `ROADMAP.md` antes de elegir APIs. Los documentos legacy de Engagement no sustituyen esas fuentes.
5. Un cambio de proveedor en ABH exige decisión explícita y evidencia del propietario, ADR, actualización del contrato y tests. No reinterpretar una captura de Lightning ni su etiqueta “Marketing” como Engagement.

## 2. Lo conseguido: NO volver a solicitarlo

- Repositorio: `Emmakex/prestashop-salesforce-marketing-cloud`.
- Aplicación externa ya creada: `ABH PrestaShop Marketing Cloud Growth`. OAuth Client Credentials de Salesforce Core **certificado**; metadata REST v68.0 inspeccionada.
- Entorno de GitHub protegido ya existente: `salesforce-growth-certification`; **no** crear otra app, otro entorno ni repetir secretos salvo evidencia nueva y necesidad aprobada.
- Token exchange de Data Cloud / Data 360 **certificado sin escrituras** (run #42655156432). El conector es `ABH_PrestaShop_Data_Cloud` (alias `PST`), en estado `Schema Required` observado.
- BC producción tiene el módulo `pssalesforcebridge` **0.7.1 activo**; configuración comprobada por SQL: `PS_SFMC_MODE=test`, `PS_SFMC_CART_CONSENT_MODE=marketing_optin`, abandono `60` minutos.
- Historia auditada: 6.023 cuentas BC (shop 1); 99 claves email distintas con algún flag newsletter positivo, **8 conflictos**; son datos analíticos, NO destinatarios habilitados. La muestra de 500 carritos contenía 7 customer IDs con newsletter en 10 carritos; NO convertir esos números en audiencia.
- Esquema `PSCustomer` / `PSOrder` de PST es **propuesta no aprobada**. No se ha publicado un flujo completo ni probado un envío Growth end-to-end.

## 3. Reglas de arquitectura y aislamiento

- Mantener un **núcleo PrestaShop independiente de Salesforce**: hooks, normalización, colas, reintentos, ledger, idempotencia, conversiones, retención y salud. Los destinos son adaptadores **seleccionados explícitamente por comercio/instalación/proveedor**.
- ABH: Salesforce **Core REST / Data 360 Ingestion**; activación por funcionalidades comprobadas de **Marketing Cloud Growth / Flows**. NO rutear ABH a un cliente Engagement, aunque compile o devuelva OAuth válido.
- Proteger identidad `merchant_namespace:shop_id:entity_id`; `ps_{shop_id}_{customer_id}` aislado no es globalmente único entre instalaciones.
- No fusionar contactos, pedidos o carritos por coincidir email. Separar `newsletter` (tienda), `optin` (colaboradores), finalidad, consentimiento, supresión, relación contractual y fecha.
- No deducir UTC a partir de timestamps locales de BC; `Europe/Madrid` confirmado, reglas DST/fecha fuente no certificadas.
- No confundir las capacidades del adaptador histórico (Engagement) con funcionalidades ya certificadas en Growth.

## 4. Puertas de seguridad y producción (fail-closed)

Está **prohibido** sin aprobación explícita documentada y pruebas específicas:
- Cambiar BC de `test` a `production` / activar worker externo.
- Publicar un esquema PST, crear streams, mapear DMO, ingerir clientes/pedidos reales o ejecutar backfill.
- Disparar Flow/campañas/emails, importar listas a campañas o recuperar carritos antiguos de forma masiva.
- Crear credenciales, ampliar permisos, copiar tokens/secretos o mostrar PII de clientes en chat, PR, logs o artefactos.
- Tratar flags newsletter o registros de consentimiento como permiso universal, ignorar exclusiones/bajas, ni elegir por última fecha en duplicados sin validar su procedencia.
- Cambiar la API/proveedor de ABH por analogía con otra variante de Salesforce.

Una validación de OAuth, metadata, token exchange, tests unitarios o ZIP **no** certifica esquema, ingesta, permisos de escritura, campañas ni base legal.

## 5. Método de trabajo obligatorio

1. **Estado real primero:** leer `main`, PRs abiertos, CI, `AGENTS.md`, contrato y último ADR. No empezar por suposiciones ni pedir al usuario lo ya comprobado.
2. **Una meta, un alcance:** formular objetivo de negocio, proveedor elegido, estado actual, siguiente bloqueo verificable y entregable. Mantener lista de lo ya realizado; no reabrir decisiones superadas.
3. **Evidencia antes de acción:** distinguir explícitamente `verificado`, `propuesto`, `pendiente`, `bloqueado`. Cualquier URL/API externa nueva requiere prueba de que pertenece al producto contratado.
4. **Tests antes de merge:** rama, diff acotado, compatibilidad PHP 7.1+ y PrestaShop 1.7/8/9 cuando haya runtime, CI exact-head, merge y CI posterior; sin cambiar código instalado por cambios documentales.
5. **Semántica de PR:** indicar el merchant, producto, ruta de API, impacto en datos, configuración, modo Test, permisos, reversibilidad, pruebas y si necesita aprobación humana.
6. **No crear versiones/ZIPs innecesarios:** empaquetar solo si cambia realmente el módulo; no pedir reinstalar por documentación, investigaciones o pruebas aisladas.
7. **Informar resultado concreto:** commit/PR/CI real, limitaciones y próximo paso accionable. No llamar “conectado y operativo” a un servicio que solo ha autenticado.
8. **Desvío detectado:** detener esa línea, documentar causa y reencauzar en `docs/PROJECT_DEVELOPMENT_RULES.md`; nunca proseguir con una API ajena por inercia.

## 6. Ruta de entrega de ABH: orden estricto

`OAuth Growth certificado` → `Data 360 token exchange certificado` →
**aprobar esquema PST PSCustomer/PSOrder, keys, TZ, retención y privacidad** →
`publicar esquema/streams solo con autorización` →
`prueba sintética sin ingesta` →
`ingesta sintética autorizada y conciliación` →
`identidad + preferencia/supresión Growth certificadas` →
`segmento/Flow Growth certificado` →
`recuperación end-to-end con test` →
`go-live autorizado`.

El paso **actual** es la aprobación de esquema y modelo de datos (sin publicar aún). Data 360 y Growth son complementarios, no intercambiables.

## 7. Registro de decisiones y guardas

- Regla humana completa: `docs/PROJECT_DEVELOPMENT_RULES.md`
- Diagnóstico del desvío: `docs/PROJECT_DRIFT_POSTMORTEM.md`
- Contrato verificable por CI: `config/project-contract.json`
- Canon de ABH: `docs/ABH_GROWTH_CANONICAL_TARGET.md`
- Los PR deben usar `.github/PULL_REQUEST_TEMPLATE.md`; un gate CI valida invariantes mínimos pero no reemplaza revisión del propietario ni impide mutaciones externas manuales.
