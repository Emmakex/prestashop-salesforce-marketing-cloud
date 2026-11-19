# 0.9.2 — Código de comercio BCABH para Barcelona Confort

**Decisión de cliente (19/11/2026):** código de comercio **`BCABH`**, identificador de instalación único **`abh_bc_prod_20261119`** propuesto si no existe ningún identificador previo. No reutilizar el namespace `abh_ps9_stage_20261117` del staging ni modificar un namespace ya guardado.

## Compatibilidad

- El formulario de la tienda admite `BCABH` sin cambios de formato (solo letras mayúsculas y dígitos). `ABH` permanece compatible para instalaciones anteriores.
- La tarjeta de identidad mostrará **Configurada** con ambos valores; el test individual de `PSCustomer` y `PSOrder` acepta exactamente ambos códigos, comprobando merchant, namespace y tienda actual.
- **No se crea ni renombra un conector Salesforce**: para el piloto BC, ambos códigos comparten la fuente ya existente y probada `ABH_PrestaShop_Data_Cloud`; no se cambian nombres de Data Streams, DLO ni DMO.
- Otras marcas/comercios quedan bloqueados para ese test de ingesta hasta tener su propio source y contrato. La licencia y el módulo siguen siendo genéricos, pero esta prueba real es exclusivamente para BC.
- `0.9.1 → 0.9.2` es actualización no destructiva: Test, captura local OFF, sin SQL/DDL, sin envío, sin borrar OAuth, sin sobrescribir código de comercio o namespace ya guardados.

## Pasos en BC

1. Si la tarjeta dice **Identidad pendiente** y los campos están vacíos, guardar `BCABH` y un namespace de BC producción exclusivo y estable (por ejemplo `abh_bc_prod_20261119` si está libre).
2. Si aparece `ABH` ya guardado, **no cambiarlo** en caliente. El módulo no permite modificar identidades existentes: se necesitaría un proceso de migración explícito con revisión de claves y fuentes.
3. La confirmación OAuth Core + token Data Cloud ya fue observada en la versión 0.9.1 de BC. La actualización no exige repetir ni tocar esas credenciales.
4. Validar un cliente y su pedido de BC **sin enviar**. Los envíos reales son pasos independientes, solo con autorización individual y conciliación DLO/DMO tras HTTP 202.
5. No activar campañas Growth, captura masiva ni cron por este cambio.

**No confundir:** el código comercial `BCABH` es la identidad administrativa del piloto, mientras `ABH_PrestaShop_Data_Cloud` es el nombre técnico de la fuente Salesforce existente. Son conceptos distintos y no requieren igualdad textual.
