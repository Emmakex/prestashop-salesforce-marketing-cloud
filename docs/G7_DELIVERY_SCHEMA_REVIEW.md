# G7 — columnas de control de entrega en la outbox G3 existente

**18/11/2026 · Plan aditivo probado en CI; NO migración instalada.**

## Decisión de simplicidad

La outbox PrestaShop que ya capturó clientes #4/#7 y pedido #10 en staging PS9.1.5 es la única fuente de eventos. **No se crea otra tabla ni segunda cola.** El esquema original G3 obliga `status='blocked'` y el ledger solo acepta estado bloqueado. Esta protección se conserva hasta una aprobación expresa de paso a despacho.

La clase `GrowthG7DeliverySchemaPlan::preview($prefix)` produce solo un plan SQL **sin ejecutarlo**. Deriva el nombre de la misma tabla `[prefix]growth_outbox_v1` con el identificador de `GrowthOutboxSchemaPlan`, y añade exactamente:

| Campo adicional | Uso previsto | Valor inicial |
|---|---|---|
| `delivery_state` | Estado local de revisión/reclamación, sin alterar `status` | `blocked` |
| `lease_token_hash` | Huella SHA-256 de la reclamación (no token plano) | `NULL` |
| `lease_until_epoch` | Caducidad del lease | `NULL` |
| `delivery_attempts` | Contador de reclamaciones controladas | `0` |
| `delivery_last_http` | Última categoría HTTP numérica, sin payload | `NULL` |

Un índice sobre `delivery_state` + `lease_until_epoch` permite inspección sin duplicar colas. No se cambia ni elimina la restricción original `status='blocked'`, y ninguna fila pasa a `ready` automáticamente. Esto significa que la sola aplicación futura de la migración **NO habilitaría envíos**.

## Reversibilidad y seguridad

El plan proporciona SQL inverso, pero **jamás lo ejecuta**. Antes de evaluar rollback se debe consultar el número de filas con estado de entrega distinto de `blocked`, intentos registrados o valores de lease/HTTP no nulos. Si existe alguna, `GrowthG7DeliverySchemaPlan::reviewRollback` lo clasifica como **bloqueado para conservar evidencia**. Si no existe ninguna, el rollback sigue siendo candidato que requiere respaldo y aprobación humana. No borrar históricos para facilitar el retorno.

No se implementan credenciales, cliente HTTP, cron, procesamiento de pedidos, alteraciones de prod ni cambios de versión del módulo.

## Pruebas reales

Se sustituye el `ALTER TABLE` manuscrito de `tests/integration/g7_durable_outbox_lease.php` por el SQL del plan nuevo. El ensayo sigue ejecutándose **únicamente** en la base `g3_ci` desechable con guardia de DSN/usuario exacta: crea las cinco columnas, ejecuta concurrencia de cuatro workers, cancelación, relectura de fuente, 202/timeout y exclusión de bajas, elimina eventos sintéticos, comprueba la guarda de rollback y revierte las cinco columnas e índice para restaurar el esquema G3 original. Compatible por CI con MySQL 8 y MariaDB 10.11/11.8.

El contrato PHP `tests/g7-delivery-schema-plan-contract.php` rechaza prefijos peligrosos, confirma que la propuesta preserva todas las puertas a false, que el rollback exige aprobación incluso con cero intentos y que ni el módulo ni cron cargan el plan.

**Preflight antes de autorizar una migración en tienda:** inspeccionar con solo lectura `information_schema.columns` e índices de la tabla exacta derivada del prefijo, y rechazar instalaciones parciales antes de ejecutar un `ALTER`. No usar `ADD COLUMN IF NOT EXISTS` como sustituto de esta validación: ocultaría estados inconsistentes. Hacer respaldo externo confirmado, inventariar motor, volumen y ventana de mantenimiento. La conversión de un evento a `ready` exige una decisión separada por finalidad y tenant; añadir columnas por sí solo nunca lo autoriza.

## Próximo corte operativo

Una vez certificado el SQL en CI, desarrollar una actualización reversible **solo para staging**, con inventario de columnas, respaldo confirmado, gate explícito, instalación no destructiva y comprobación de cancelaciones. **No convertir la clase CI GrowthOutboxDeliveryLease en una API de producción sin revisar su guardia de base, el fence en el instante del POST y la conciliación asíncrona con Data Cloud.**

**G5 sintético completado; G6 aceptado administrativamente.** Ambos no son autorización de envíos comerciales. Staging se mantiene en 0.8.7 Test y Barcelona Confort producción en 0.7.1 Test hasta la certificación G8. Nada de este PR requiere un ZIP ni generar nuevos pedidos.
