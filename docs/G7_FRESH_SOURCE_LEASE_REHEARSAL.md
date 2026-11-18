# G7 — Fuente nativa fresca + lease en la misma outbox (ensayo bloqueado)

**18/11/2026 — avance real de desarrollo, sin envíos ni instalación.**

Tras G5 validado mediante PSCustomer/PSOrder sintéticos materializados en DLO y DMO, G6 aceptado administrativamente por el responsable a través de la integración previa del CRM y captura/relectura real G7 completada en PrestaShop 9.1.5 con módulo 0.8.7, el objetivo pasa a ser el despacho **seguro y reversible** desde la misma outbox G3.

## Implementación

`GrowthFreshSourceBlockedRehearsal::simulate` une componentes existentes, **sin introducir otra cola ni escribir un sender**:

1. Primera relectura del evento G3 `blocked` y última versión en `growth_entity_ledger_v1`, junto con objetos `Customer` / `Order` / `Currency` nativos **inyectados por la prueba**. `GrowthCapturedEventPstPreview` valida identidad, tienda, esquema PST v0.4 y contrato de endpoint en memoria.
2. **Filtro negativo local obligatorio:** cliente inactivo, eliminado, invitado o con `newsletter=false` bloquea esta simulación de despacho promocional. **Newsletter verdadero NO acredita consentimiento Growth**; el Consent Check remoto seguirá siendo obligatorio cuando se autoricen campañas.
3. `GrowthOutboxDeliveryLease::claim` hace CAS atómico contra la **misma outbox/ledger**. Si no es la revisión vigente, ya está reclamada o la fila sigue bloqueada, **no se reclama**. La única preparación a `delivery_state=ready` sigue siendo el fixture desechable `g3_ci`; el módulo nunca la hace.
4. Después del claim se vuelve a leer la fuente y el ledger. Cambios de baja, estado, email, fecha, orden, importe u otros campos públicos → `cancelled` en el ensayo, sin HTTP. Dos lecturas iguales **NO garantizan una transacción atómica hasta una futura petición remota**.
5. Solo tras las dos comprobaciones, se inyecta una **respuesta HTTP simulada**: 202 → `reconcile` (aceptación no confirmada); respuesta perdida/timeout → `reconcile` inmediato; cualquier otra respuesta → `held`, sin reintentos automáticos. Nunca se certifica entrega ni se habilitan permisos.
6. La respuesta de simulación solo contiene estado, flags booleanos y una categoría permitida; no devuelve IDs, emails, URI, tokens, huellas HMAC, JSON PST ni detalles SQL.

## Tests

`tests/integration/g7_durable_outbox_lease.php` amplía el ensayo ya existente en MySQL 8 / MariaDB 10.11 / MariaDB 11.8 reales **solo bajo credenciales exactas `g3_ci`**, restaurando al final el esquema original G3. Comprueba: fila `blocked` no reclamada, 202 nunca entregado, cierre de newsletter previo, cuenta que se desactiva después del claim, fuente que cambia de versión en la segunda lectura, respuesta perdida/timeout, ausencia de PII y ausencia de reintentos. El control adicional `tests/g7-fresh-source-rehearsal-contract.php` se ejecuta con PHP 7.1/8.5 y verifica que ni módulo ni cron cargan esta clase.

## Lo que aún **no** está hecho

- **La outbox real del módulo** solo tiene `status=blocked` con CHECK, y no tiene aún las columnas `delivery_state`, `lease_token_hash`, `lease_until_epoch`, `delivery_attempts`, `delivery_last_http`. El sandbox CI las crea temporalmente y las elimina tras las pruebas. **No existe migración de tienda ni tabla/cola nueva.**
- **No hay cliente HTTP ni credenciales de envío en este ensayo**, y nunca se llama Salesforce. No es un dispatcher real ni certificado de entrega.
- Para operar, quedan: migración controlada y reversible de esas columnas en la misma outbox, un selector de eventos preparados con autorización de ingesta según finalidad, lectura atómica/cancelación ante compra y baja, fence en el instante de POST, transporte autorizado, conciliación posterior Data Cloud 202→DLO/DMO, limites de ritmo/reintentos y UAT G8.
- Carritos: aún no existe PSCart en G4 ni Flow de recuperación autorizado; **no mezclar** esta prueba de cliente/pedido con envíos de recuperación.

**Próximo incremento G7:** convertir la migración y lectura en una ruta operativa de PrestaShop con controles Test y reversible **sin activar el transporte**. La última decisión expresa de go-live se reserva a G8. BC producción sigue 0.7.1 Test y el staging sigue 0.8.7 Test; no entregar ZIP en este PR.
