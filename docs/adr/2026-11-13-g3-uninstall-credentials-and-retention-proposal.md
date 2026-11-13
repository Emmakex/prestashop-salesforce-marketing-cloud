# ADR (borrador) — Separación de retención comercial y revocación local de credenciales

**Fecha:** 2026-11-13  
**Estado:** PROPUESTO / SIN APROBACIÓN PARA PRODUCCIÓN  
**Proyecto:** Puente PrestaShop con Salesforce. Piloto ABH/Barcelona Confort -> Marketing Cloud Growth (Next) + Data 360; no Engagement.

## Problema

El módulo instalado 0.7.1 borra tablas y configuración en la desinstalación.
El candidato temporal 0.8.0 del PR #55 ensayó una retirada no destructiva,
conservando también los secretos: ese último comportamiento **no es una política
de privacidad aceptable por defecto**. Necesitamos demostrar que una retirada
no impide reanudar la integración, pero tampoco mantiene credenciales utilizables.

## Decisión candidata, exclusivamente para pruebas

- **Persisten** las tablas de eventos/colas y ledger de PrestaShop heredadas,
  las observaciones Growth (incluyendo proveedor y namespace inmutables),
  las preferencias operativas no secretas (cart, ventana, umbrales de
  retención, configuración de consentimiento fuente) y las marcas de
  inventario.
- **Se retiran localmente** el client secret, client ID, account ID,
  endpoints legacy, referencias de Data Extensions y Journey y token de cron.
  No pueden reaparecer automáticamente durante una reinstalación.
- **Una reinstalación** del mismo módulo en el mismo sitio mantiene las
  preferencias válidas y registros locales, obliga a modo `test`,
  genera un **nuevo** token de cron y conserva la asociación Growth/namespace.
- Todos los emisores legacy siguen bloqueados por el proveedor y la
  ausencia de autorización externa. El token cron renovado solo autentica
  acceso local: no concede permiso de ingesta ni envíos Salesforce.
- La limpieza local **no equivale** a revocar tokens en Salesforce ni
  a borrar copias de seguridad o registros de operadores externos.

## Alternativas descartadas para el ensayo

1. Borrar todas las tablas: pérdida potencial irreversible del historial
   de sincronización, dedupe y auditoría.
2. Conservar todas las credenciales: deja rutas obsoletas recuperables
   después de retirar el módulo.
3. Reinicializar todas las preferencias operativas al reinstalar: altera
   ventanas y retención silenciosamente.
4. Mantener el token cron de la instalación anterior: reutiliza un bearer
   que debía caducar al retirar el módulo.

## Límites y aprobación pendiente

Las colas históricas pueden contener **datos personales** y registros de
recuperación. No se aprueba retención indefinida ni la conservación automática
de tokens de recuperación; hace falta política específica de plazos,
supresión, derecho al borrado, copias de seguridad y eliminación irreversible
para cada clase de datos y responsable.

Antes de trasladar esta política a un módulo distribuible 0.8.0 se requiere
aprobación del responsable de tratamiento, estrategia de revocación remota,
auditoría del ciclo completo de reintentos/cron, fail-closed sobre bases sin
CHECK, y CI exact-head + compatibilidad nativa 1.7/8/9 de la versión real.

**No modifica 0.7.1 en Barcelona Confort; no libera G3.**
