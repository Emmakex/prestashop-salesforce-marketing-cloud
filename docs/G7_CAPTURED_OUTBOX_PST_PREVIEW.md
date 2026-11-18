# G7 — Una única ruta: evento de outbox → PST v0.4 (sin ingesta)

**18/11/2026.** Se verificó en PrestaShop 9.1.5 staging la captura local real en la outbox G3 de `CUSTOMER_CREATED` (#7), `CUSTOMER_UPDATED` (#4) y `ORDER_CREATED` (#10), con `status=blocked`, módulo 0.8.4, namespace exclusivo `abh_ps9_stage_20261117` y modo Test. Evidencia privada: `request_sql_19.csv`, resumida sin datos personales en la incidencia #12.

## Implementación

`GrowthCapturedEventPstPreview::inspect` une piezas ya existentes sin reescribirlas:

1. **Outbox G3 + ledger original:** exige namespace/tienda/entidad coincidentes, misma revisión `latest_accepted_sequence`, estados `blocked` en ambos, y eventos exclusivamente `CUSTOMER_CREATED`, `CUSTOMER_UPDATED` u `ORDER_CREATED`. Una revisión anterior, cancelada, de otra tienda o carrito se rechaza antes de generar el JSON PST.
2. **Fuente nativa actual:** exige `Customer` con ID/tienda explícitos; para pedidos exige también `Order` y `Currency` del mismo cliente/tienda. Los objetos son **inyectados por el llamador**: esta versión no lee base de datos ni puede certificar la frescura del snapshot.
3. **Normalización sin inventar pedido:** nuevo `GrowthNativeCustomerOrderDraft::buildCustomer` produce `PSCustomer` v0.4 para altas/actualizaciones incluso antes de cualquier compra; comparte la proyección con `buildPair` (que sigue preparando PSCustomer y PSOrder por separado).
4. **Contrato de ingesta existente:** `GrowthStreamingIngestionPlan::prepare` construye temporalmente la forma del upsert para PSCustomer o PSCustomer + PSOrder, con endpoint, namespace, FK/PK y 11 campos por objeto validados por el builder tipado G4. No envía HTTP ni llama a `/actions/test`.
5. **Salida estrictamente saneada:** se descartan el JSON PST y el cuerpo HTTP preparado. Solo se devuelven contadores y flags constantes de forma; sin email, nombre, claves tipadas, IDs, importe, URI, token, namespace ni cuerpo. `data_cloud_ingestion_authorized`, `outbound_worker_authorized` y `marketing_sends_authorized` siguen `false`.

La ejecución es puramente en memoria: **sin consultas ni mutaciones SQL, sin worker, sin segunda cola, sin cambios en el módulo instalado**, sin actualizar ni reenviar el pedido #8 histórico. BC producción continúa en 0.7.1 Test. PrestaShop staging continúa en 0.8.4 y **no necesita instalar otra versión por este cambio**.

## Prueba y límites

`tests/g7-captured-outbox-pst-preview-contract.php` prueba alta/actualización de cliente sin pedido, pedido con dos objetos PST, bloqueo de revisiones obsoletas, distinta tienda, estado ready no autorizado, carrito, PII, invitado, baja, consentimiento inválido, origen HTTPS arbitrario, modo Production y ninguna conexión a los hooks/cron. Ejecutar en CI PHP 7.1/8.5; las pruebas runtime de instalación/hook siguen separadas.

**No es entrega real ni autorización de tratamiento:** G5 debe verificar el contrato de ingesta sintética y el resultado DLO/DMO; G6 debe confirmar la suscripción Marketing/Email, el Consent Check nativo por destinatario/Business Unit y las supresiones; G7 necesita lector de fuentes frescas, política de reintento/conciliación HTTP 202 y lease duradero aprobado en la misma outbox; G8 conserva gates de despliegue. Una previsualización correcta no certifica orden de cambios, fecha UTC fuente, aceptación remota ni permiso de campañas.

La siguiente integración autorizada podrá reutilizar esta ruta tras **un único paso de lectura reciente de fuente y validaciones**, en lugar de crear un transporte paralelo.

[Seguimiento central #12](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/issues/12)
