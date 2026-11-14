# Destino único ABH — Marketing Cloud Growth + Data Cloud

**Producto contratado y confirmado:** Salesforce **Marketing Cloud Growth (Next)** sobre Salesforce Core/Lightning, con **Data Cloud**. Ningún otro producto Salesforce será proveedor de este puente. El núcleo seguirá sirviendo a múltiples comercios y tiendas PrestaShop, con destino Growth únicamente.

## Lo que NO volvemos a hacer

- Salesforce Core org `https://alfabh.my.salesforce.com`, aplicación externa `ABH PrestaShop Marketing Cloud Growth`, cliente OAuth y Run As ya probados.
- Token exchange de **Data Cloud certificado sin escrituras**; entorno GitHub protegido `salesforce-growth-certification` reutilizable. Sin nueva aplicación ni scopes.
- Conector `ABH_PrestaShop_Data_Cloud`, alias `PST`, observado en `Schema Required`. No se debe confundir con schema publicado o datos ingestados.
- Barcelona Confort mantiene el módulo instalado **0.7.1 Test**, `marketing_optin`, 60 minutos; ninguna campaña Growth o ingesta end-to-end ha sido validada.

## Arquitectura exclusiva

```text
PrestaShop 1.7/8/9 hooks + identidad por instalación/tienda
  -> cola Growth idempotente y control de consentimiento
  -> Data Cloud Ingestion (schema + stream + mapeo autorizados)
  -> Marketing Cloud Growth / Flow (segmento + envío autorizado)
  -> comprobación pedido/conversión + supresión por finalidad
```

La **0.8.0 Growth-only** elimina el código ejecutable de transporte anterior y protege instalación, upgrade y cron contra reenvíos. Este corte no equivale a captura duradera ni transmisión Growth: la nueva cola no puede conectarse a PST hasta aprobación G3/G4.

## Puertas aún sin certificar

1. **G3:** PSCustomer y PSOrder, SHA candidato v0.2, PK/FK de Data Cloud, namespace único, timezone/retención/finalidad/supresión. Falta consentimiento y conformidad explícita por responsables; no inventar firmas.
2. **G4/G5:** tras aprobación, publicar esquema PST y streams; validar primero payload sintético sin datos de clientes y después escritura sintética por autorización independiente.
3. **G6/G7:** origen real de consentimiento y exclusión Growth; Flow y prueba end-to-end con destinatario controlado.
4. **G8:** release instalable 1.7/8/9, backup, reversibilidad y aprobación para Barcelona Confort. Seguir Test hasta entonces.

**Fuentes de verdad:** `AGENTS.md`, `config/project-contract.json`, `ROADMAP.md` y expediente G3 [#45](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/issues/45). El historial de otros desarrollos no determina el producto futuro.
