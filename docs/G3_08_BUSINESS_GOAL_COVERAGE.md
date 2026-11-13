# G3-08 · Comprobación del objetivo de negocio antes de elegir el esquema

**ABH / Barcelona Confort · Marketing Cloud Growth/Next + Data 360 · 13/11/2026**  
**Estado: revisión técnica; NO se ha aprobado un esquema, DMO, consentimiento, envío o ingesta.**

## Resultado técnico reproducible

`python scripts/ci/verify-g3-business-goal-coverage.py` compara **en modo completamente offline** el contrato del piloto con el manifiesto de ocho decisiones G3, ambas versiones YAML congeladas, los 24 registros de tratamiento por campo, las huellas SHA-256 y la propuesta de mapeo no aprobada.

| Objetivo que solicitó el negocio | Lo que permite verificar hoy el material existente | Conclusión |
|---|---|---|
| **Campañas a clientes** | El borrador `PSCustomer` contiene identidad y datos de contacto propuestos, pero no hay base/finalidad aprobada por campo, fuente de bajas/supresiones Growth certificada ni autorización de envíos | **No habilitado** |
| **Recuperación de carritos abandonados** | El módulo instalado captura cart snapshots locales, pero **ni v0.2 ni v0.3 definen PSCart**; tampoco existe un evento/cart Data Stream Growth aprobado, ni frescura de actividad y consentimiento para enviar | **No habilitado** |
| **Segmentos de clientes que han comprado** | El borrador `PSOrder` tiene una referencia `customer_source_key`, pero **no hay relación DLO→DMO con Individual/Account confirmada y aprobada**. El catálogo ABH fue leído por páginas completas, sin encontrar en la configuración actual las aristas candidatas | **No habilitado** |
| **Evitar enviar recuperación tras una compra** | El estado de pedido puede revalidarse **localmente en PrestaShop**; existe un candidato de control técnico en el módulo histórico. Eso **no equivale** a un pre-send check certificado para un futuro adaptador Growth | **Solo posibilidad técnica local, sin autorización de envío** |

### Por qué este corte importa

Aprobar `PSCustomer` y `PSOrder` **no debe aparecer como “carritos abandonados listos”**. Falta una decisión técnica independiente sobre el transporte de señales de carrito: una futura entidad/evento `PSCart`, un mecanismo Growth de eventos acotados o mantener el cálculo de abandono en PrestaShop con comprobación de conversión inmediatamente antes de cada envío. **Ninguno se activa ni se presupone.** Añadir un tercer objeto requeriría cambiar el esquema congelado, revisar minimización/retención/consentimiento y una nueva aprobación.

La estrategia de contención G3-06 (**PSOrder separado de Individual**) evita fusiones incorrectas, pero **no cierra la funcionalidad comercial** de segmentación por compra en Data 360. Por eso es una opción temporal para revisión, no una decisión final de arquitectura de campañas.

La [inspección completa de ABH (run #43495007989)](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/43495007989) inspeccionó `SalesOrder=64`, `Individual=137` y `Account=326` metadatos de relaciones (527 en total, completos según totalSize) sin detectar enlaces con los campos candidatos. No significa que sea imposible configurar una relación posteriormente. [Issue #45](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/issues/45) conserva las decisiones G3 pendientes.

## Criterios de aceptación que separan G3, G4 y G7

**Antes de G3-08:** el responsable debe responder G3-01…07, incluyendo fuente estable de identidad entre instalaciones, necesidad y base de cada campo, DST/procedencia de fechas, Record Modified/refresh, categoría Data Stream y DLO→DMO, relación comercial y tratamiento de huérfanos, y retención/supresión. Elegir v0.2 o v0.3 exige `SHA-256` exacto y conformidad documentada en la vista previa de PST, **sin guardar**.

**Antes de G4:** autorización separada para subir el esquema, configurar streams/mapping y pruebas de ingesta. Un `CI=green`, la inspección de metadatos o una firma de esquema G3 **no** dan permiso implícito de escritura en Data 360.

**Antes de la primera campaña Growth y la recuperación de carritos (G6/G7):** completar fuente/evento de carritos, validación de actividad y compra, identidad de destinatarios, consentimiento por finalidad, supresión/bajas, límites de frecuencia y prueba de recuperación end-to-end sintética y autorizada. El adaptador histórico **Engagement** no satisface estas condiciones en ABH.

## Qué hace el verificador y qué no

- Comprueba `Marketing Cloud Growth/Next`, `mode=test`, estado `Schema Required`, **ocho decisiones pendientes**, ambos objetos/22 y 24 campos, SHA congelados, 24 campos sin autorización de exportación, mapeo DLO→DMO sin firmar y ausencia de un objeto `PSCart` en el esquema v0.2/v0.3.
- Emite únicamente un resumen JSON **sin emails, IDs, clientes, secretos, instancias Salesforce ni respuestas de proveedor**; devuelve error y falla CI si se altera un esquema, se activa una bandera prematura o se introduce `PSCart` sin nuevo expediente.
- Los tests negativos en `tests/test_g3_business_goal_coverage.py` mutan banderas de publicación, firma, mapas DMO, proveedor, versión, objeto y huellas; todos deben rechazarse.
- No toca PrestaShop/SQL, no consulta API, no genera ZIP, no modifica esquemas o consentimientos, no crea mapeos, no autoriza campañas y **no sustituye las firmas humanas**.

**Estado final de este corte:** preparación de una decisión útil para el negocio, no despliegue. `pssalesforcebridge` permanece **0.7.1 en Test**.
