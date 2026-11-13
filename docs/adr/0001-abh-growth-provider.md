# ADR-0001: ABH opera con Marketing Cloud Growth + Data 360

- **Fecha:** 2026-11-13
- **Estado:** aprobado como decisión de arquitectura del piloto, sin autorizar cambios de producción
- **Ámbito:** ABH / Barcelona Confort, dentro de un puente reutilizable multi-merchant
- **Origen de la decisión:** conversaciones con el propietario, capturas Lightning ABH, pruebas protegidas OAuth y token exchange GitHub.

## Contexto

ABH dispone de Marketing Cloud Growth (Marketing Cloud Next) integrado con Salesforce Core (Lightning) y Data Cloud/Data 360. Se configuró la aplicación externa `ABH PrestaShop Marketing Cloud Growth`, OAuth Client Credentials y el conector Data Cloud `PST`. Las certificaciones sin escritura fueron satisfactorias.

El módulo PrestaShop 0.7.1 tiene además una implementación histórica de Marketing Cloud Engagement, con Data Extensions y Journey Builder `/interaction/v1/events`. Su existencia técnica **no** demuestra licencia ni compatibilidad con Growth de ABH. Al confundir ambas plataformas se pidieron falsos accesos Installed Packages/Engagement y se hicieron pruebas SOAP que no aproximaban el piloto a su objetivo.

## Decisión

1. Selección explícita por **merchant/instalación**. Para ABH: `marketing_cloud_growth` + `data_cloud_data_360`.
2. Mantener los componentes PrestaShop de captura/ledger/colas/recuperación comunes. Adaptadores de salida aislados, con prefijo de identidad por merchant.
3. El destino legado Engagement permanece como opción **no habilitada** en ABH, sin transferir credenciales ni clientes entre proveedores.
4. Conservar evidencia ya certificada Core OAuth/token exchange. Siguiente gate: aprobar esquema PST, fuente de verdad/identidad/tz/retención/categorías. Sin esquema aprobado, no ingesta.
5. Consentimiento y supresión deben derivarse del modelo Growth/Core/Data 360 realmente disponible, no de All Subscribers/Publication Lists SOAP.
6. BC permanece en Test hasta circuito Growth completo, validación sintética, aprobación del propietario y rollback definido.

## Alternativas rechazadas

- **Engagement por inercia:** requiere otro producto/tenant no verificado; arquitectura equivocada para ABH.
- **Rehacer OAuth o crear otra aplicación externa:** trabajo ya certificado y sin evidencia de necesidad.
- **Publicar ahora el esquema de prueba:** datos/campos/identidades, timezone y retención todavía sin aprobar.
- **Activar 0.7.1 Production para probar:** puede enviar por adaptador Engagement; no es una prueba válida Growth y crea riesgo de datos/envíos.

## Consecuencias

Se documenta un contrato verificable en CI, las reglas de `AGENTS.md`, el roadmap de gates y una plantilla de PR. Los cambios futuros de proveedor, activación, esquema o consentimiento requieren **nuevo ADR + autorización explícita**. Esta ADR es una decisión de ruta, **no** autorización de puesta en producción ni prueba de un conector Growth operativo.
