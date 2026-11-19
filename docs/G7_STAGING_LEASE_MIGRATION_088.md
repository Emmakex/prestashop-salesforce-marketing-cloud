# G7 — PrestaShop 0.8.8: migración controlada de la misma outbox en staging

**19/11/2026 — solo Growth + Data Cloud, modo Test.**

Se incorporan al Back Office tres acciones independientes: inspeccionar el esquema G3/G7 original; añadir cinco columnas de control de entrega a la MISMA outbox; y revertirlas solo cuando no existan intentos ni reclamaciones de entrega.

## Seguridad

- Instalación/upgrade 0.8.8: NO ejecuta DDL. Mantiene HMAC, identidad, outbox y ledger; fuerza Test y desactiva temporalmente la captura local.
- Inspección: comprueba InnoDB, CHECK activo, binding Growth/Test, columnas exactas, valores por defecto e índice. Detecta esquema parcial sin corregirlo a ciegas.
- Aplicar: el operador debe detener captura, introducir la frase PREPARE_G7_STAGING_OUTBOX_ONLY y confirmar respaldo externo con I_HAVE_A_VERIFIED_STAGING_BACKUP. El módulo NO crea ni valida backups. Se usa un lock SQL y se revisa el esquema antes y después.
- Rollback: requiere otra frase (ROLLBACK_G7_STAGING_OUTBOX_ONLY), confirmación de respaldo y captura OFF. Si existe algún intento, lease, HTTP o estado distinto de blocked, se niega. DDL no es una transacción convencional y los fallos requieren revisión manual.
- Nunca se cambia el CHECK de G3 ni se habilita ready, HTTP, campañas, carritos o envíos a Salesforce. La operación no se ejecuta en producción sin autorización independiente.

La verificación de captura G3 se ha adaptado para reconocer tanto las 13 columnas originales de outbox como las 18 ampliadas, pero únicamente cuando el esquema G7 completo está certificado. Esto permite rearmar captura tras aplicar el cambio.

## Comprobación en staging tras CI verde

1. Confirmar respaldo externo real de staging. Actualizar sobre el módulo existente; no desinstalar.
2. Pulsar Inspect G3/G7 outbox (read only). Resultado esperado: original_g3_verified.
3. Con captura local OFF, utilizar Extend G3 outbox on staging (no sends), frase de aprobación y confirmación del respaldo.
4. Resultado esperado: extended_blocked_no_sends (o already_extended_verified); conserva los eventos existentes.
5. Si hace falta, rearmar la captura local Test con su aprobación habitual. No crear más pedidos/clientes para esta verificación.
6. El botón rollback queda disponible solo para mantenimiento y si no existe historial de intentos.

## Pruebas y límites

El test existente de staging en MySQL 8 / MariaDB 10.11 / 11.8 usa base desechable g3_ci y ahora ensaya inspección, falta de permisos, aplicación, idempotencia, rearme, bloqueo de rollback con intentos, restauración y preservación de los cinco eventos sintéticos originales.

Esto NO es un dispatcher. Quedan adaptador de lease operativo, relectura final, decisión de finalidad, conciliación 202→DLO/DMO y pruebas G8. No repetir el canary G5 ni la aceptación administrativa G6.

Barcelona Confort producción permanece en 0.7.1 Test. Staging permanece en 0.8.7 hasta instalación aprobada de 0.8.8.
