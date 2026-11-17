# G5 — Canary de ingesta sintética controlada en ABH (alternativa al ticket)

**17/11/2026 · Prueba real únicamente tras autorización manual.**

Salesforce distingue [validación síncrona `/actions/test`](https://developer.salesforce.com/docs/data/data-cloud-int/references/data-cloud-ingestionapi-ref/c360-a-api-synchronous-record-validation.html), cuya respuesta normal es **200**, de [upsert asíncrono](https://developer.salesforce.com/docs/data/data-cloud-int/references/data-cloud-ingestionapi-ref/c360-a-api-insert-records.html), que devuelve **202 Accepted**. La primera ejecución G5 obtuvo 202 en `/actions/test`; **este incidente histórico permanece sin explicar**. No reintentar ese endpoint ni suponer que no creó registros.

Esta alternativa verifica **el endpoint de upsert real**, sin reutilizar la identidad de la prueba fallida y sin ningún dato personal real. Es una escritura en el **tenant ABH conectado**, no en un tenant aislado ni en un sandbox independiente: por eso requiere autorización explícita y conciliación posterior.

## Canary fijo (no debe incluir clientes de PrestaShop)

| Parámetro | Valor |
|---|---|
| Connector | `ABH_PrestaShop_Data_Cloud` |
| Data Space | El espacio asociado a los streams ya creados, `default` |
| Objeto primero | `PSCustomer`, un registro |
| Namespace | `growth_g5_canary_20261117` |
| Tienda sintética | `2` |
| ID sintético | `987654321` |
| `source_key` del cliente | `growth_g5_canary_20261117:2:customer:987654321` |
| Email ficticio | `g5-canary-20261117@example.invalid` |
| Consentimientos y cuenta activa | Newsletter `false`, partner opt-in `false`, active `false` |
| Objeto segundo, solo después de verificar cliente | `PSOrder`, importe 0, paid `false`, sin carrito |
| `source_key` de pedido | `growth_g5_canary_20261117:2:order:987654321` |

No se usan datos del PrestaShop 9 de laboratorio ni BC producción. Las claves están segregadas lógicamente en el **mismo conector**. No asociar esta identidad sintética a segmentos de Growth. No se crea ni envía ningún correo.

## Ejecución exacta

Workflow: [G5 Single Synthetic Upsert (No Marketing)](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/workflows/g5-controlled-synthetic-upsert.yml).

1. Desde GitHub Actions elegir **Run workflow → main → `preview_only`**. La vista previa ejecuta únicamente el contrato tipado local, sin OAuth ni HTTP, y genera `g5-canary-sanitized.json`.
2. **Antes de cualquier POST**, comprobar en **Data Cloud → Explorador de datos → PST-PSCustomer** que la `source_key` indicada arriba NO existe. Si hay una fila preexistente, se detiene el ensayo para investigar; no ejecutarlo a ciegas. También revisar el estado previo del incidente 202 antiguo en su namespace distinto.
3. Para un único envío sintético, elegir `customer_upsert_once`; escribir exactamente en los campos de confirmación:
   - `approval_code`: `I_APPROVE_SYNTHETIC_UPSERT`
   - `absence_attestation`: `CANARY_NOT_PRESENT_IN_DLO`
   - `customer_dlo_attestation`: dejar vacío.
4. GitHub usará **el entorno protegido existente** `salesforce-growth-certification` y los secrets OAuth ya almacenados. El ejecutor admite **exactamente un POST PSCustomer** por ejecución; no sigue redirecciones, no reintenta y no almacena payloads, respuestas, emails ni tokens en logs/artifact.
5. Si el resultado es **202**, el artifact mostrará `accepted_unconfirmed_manual_dlo_check_required`. **NO es PASS de ingesta final**. Comprobar posteriormente en Data Explorer la fila `PST-PSCustomer` con la misma `source_key`. Validar asimismo si la relación del DMO Individual es coherente, sin publicar IDs personales. Si el resultado es timeout o error, **no reintentar** sin conciliación manual.
6. **Solo después** de comprobar el cliente y la ausencia de ese mismo pedido sintético en `PST-PSOrder`, ejecutar `order_upsert_after_customer_verified` con las mismas aprobaciones y además `customer_dlo_attestation` = `CUSTOMER_DLO_VERIFIED`. Inspeccionar luego la fila de pedido y su FK al cliente.
7. Registrar en [issue #12](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/issues/12) los dos IDs de workflows, sus estados saneados y evidencia **sin datos personales** de DLO/DMO. La política de borrado o retención del canary debe aprobarla el propietario de los datos antes de ejecutar operaciones de limpieza: no lanzar DELETE no certificado.

## Criterios de aceptación

- **Técnico mínimo:** ambos objetos aceptados por el endpoint normal, ambos visibles en Data Cloud y FK consistente. Un 202 aislado no basta. La anomalía de `/actions/test` continúa documentada como tal y es escalable.
- **No habilita automáticamente:** migración de la outbox G3 en tienda real, worker, ingesta de datos reales, prueba de consentimiento por contacto, envío promocional, recuperación de carritos, campañas, ni actualización de BC 0.7.1 a 0.8.0.
- **Fallo cerrado:** si no está permitido escribir ni siquiera registros sintéticos al Data Space `default`, usar solo `preview_only` y solicitar un espacio de prueba independiente o aprobación del administrador. Si hay alguna duda de enrutamiento, no ejecutar el POST.
- La versión PrestaShop 9.1.5 UTC de laboratorio permanece **modo Test**; Barcelona Confort producción **Europe/Madrid, 0.7.1 Test**. No modificar las zonas ni inventar procedencia UTC de fechas históricas.

**Resultado de CI no significa ejecución real del workflow.** El despliegue a Salesforce exige acción manual expresa del propietario sobre `main`.
