# G7/G8 — cliente y pedido nativos → PST v0.4, sin transporte

**16/11/2026 · Código de negocio nuevo.** El módulo Growth-only ya tenía el esquema PSCustomer/PSOrder en Salesforce y el builder tipado v0.4, pero faltaba la conversión estricta desde **objetos nativos reales de PrestaShop** al contrato exacto de 22 campos. Ahora `GrowthNativeCustomerOrderDraft::buildPair` es esa pieza reusable.

## Lo que hace

1. Recibe **Customer, Order y Currency** de PrestaShop como objetos provistos por quien lo invoque, namespace de instalación y shop explícito.
2. Rechaza cliente/pedido de otra tienda, order huérfana, cliente invitado/borrado, ID ambiguo, moneda no coincidente, flags no binarios, importe negativo, fecha inválida, modo Production o proveedor distinto de Growth.
3. Proyecta **PSCustomer/PSOrder v0.4**, con PK `namespace:shop:customer:id` y `namespace:shop:order:id`, FK de pedido a cliente de la misma instalación/tienda, FK de carrito tipada, `paid_flag=false` sin inferencia de pago y fecha local sin convertir a UTC.
4. Usa `GrowthTypedPstPayloadBuilder::encode` **sin copiar la validación de esquema ni crear otro contrato**.
5. Devuelve exclusivamente dos JSON **en memoria**, con estado `native_local_draft_only`; el resultado contiene datos personales, por lo que no debe registrarse en logs, artifacts ni comentarios. No persiste ni contacta Salesforce.

La prueba nueva `tests/g7-native-customer-order-draft-contract.php` compara **ambas estructuras completas** byte a byte semánticamente con el mismo golden fixture sintético G4 (PS 1.7.8.11 / 8.2.8 / 9.1.5) y ejercita negativos multitienda, huérfanos, invitado/borrado, moneda, importe, fecha, esquema y modo.

## Qué NO hace (gates externos)

- No está cargado por el entrypoint del módulo, ni está conectado a los hooks, Cron, outbox, Data Cloud o un Flow. La instalación de Barcelona Confort **0.7.1 Test** no cambia.
- Newsletter/optin son atributos fuente, jamás la certificación de consentimiento Marketing Cloud Growth. No habilita campañas.
- PSCart no es un objeto de salida ni un stream aprobado; su referencia tipada en el pedido no crea una campaña de recuperación.
- No transforma `date_upd` histórica en UTC fuente, no decide pagos por estado de pedido ni hace ingesta real.
- No usa datos reales en CI y no altera tablas antiguas ni crea una segunda cola.

## Qué desbloquea

Cierra la falta de **serializador real nativo de cliente/pedido** de G7/G8; cuando G5 resuelva el 202 y G6 tenga consentimiento efectivo certificado y se apruebe el contrato de datos, podrá conectarse a la misma salida Growth con una revisión de seguridad separada. **No repetir el builder ni los contratos de identidad.** La secuencia posterior es un único dispatcher/cancelación con el outbox existente, sin transportes paralelos.

Faltantes totales del proyecto: G5 validación del endpoint, G6 consentimiento, G7 flujo/campaña/atomicidad y G8 despliegue. [Seguimiento actualizado](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/issues/12).
