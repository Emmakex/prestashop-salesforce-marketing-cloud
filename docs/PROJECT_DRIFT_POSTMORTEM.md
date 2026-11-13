# Incidente de rumbo técnico — ABH Growth ↔ Engagement

**Fecha del análisis:** 2026-11-13. **Clasificación:** desvío de producto y documentación, sin evidencia de activación de envíos reales desde BC. No se afirma que esta revisión haya auditado todos los sistemas externos.

## Objetivo que el proyecto siempre tuvo

Integración reusable PrestaShop (clientes, compras y carritos) con el marketing que **ABH realmente tiene**: Marketing Cloud Growth / Salesforce Lightning + Data 360. Primero un piloto seguro en Barcelona Confort (`test`), después adaptadores para otros merchants/versiones. Las campañas de recuperación deben respetar consentimiento, oposición, identidad, ventana y supresión.

## Puntos del desvío

1. El módulo 0.1–0.7 se desarrolló históricamente con rutas de **Engagement** (Data Extensions, Journey Builder). Eran código reusable, **no prueba** del producto contratado en ABH.
2. Ya el 5–9/11 el equipo confirmó **Growth**, certificó el OAuth externo y el token exchange Data 360; esto debió bloquear cualquier itinerario ABH tipo Engagement.
3. Tras las auditorías de newsletters BC se eligió incorrectamente el control de **All Subscribers + Publication Lists**. PR #36 introdujo una política de *readiness* inspirada en Engagement y PR #40 un parser SOAP sin acceso a ABH ni uso en runtime. Este trabajo no reemplazó el sender ni habilitó campañas, pero desvió tiempo y sugerencias.
4. Se solicitó al usuario navegar a Marketing Cloud Engagement **Installed Packages** y `mc.exacttarget.com`, aunque ya estaba dentro del Lightning de ABH. La solicitud fue incorrecta para ABH.
5. PR #41 corrigió la documentación de producto, pero faltaba imponer una regla central, seguimiento de gates y un gate CI para evitar recaídas.

## Causas raíz

- No había `AGENTS.md` ni contrato piloto/proveedor comprobado automáticamente.
- Los documentos legados Engagement coexistían con Growth sin jerarquía visible al iniciar tareas.
- Se confundió un módulo con capacidad Engagement con el servicio licenciado del cliente.
- Los entregables se orientaron a auditorías periféricas en vez de seguir el gate bloqueante: aprobación PSCustomer/PSOrder PST.
- Las instrucciones al usuario no consultaron primero decisiones ya certificadas.

## Corrección y acciones preventivas

- `AGENTS.md` obligatorio y documentación de desarrollo unificada; contrato versionado `config/project-contract.json`.
- `docs/ABH_GROWTH_CANONICAL_TARGET.md` es fuente ABH; legacy Engagement aislado y nombrado explícitamente.
- CI `ABH provider focus` falla ante cambios accidentales de producto, modo autorizado o estado de schema en el contrato. **Limitación:** no puede validar el tenant vivo ni sustituye revisión.
- Plantilla PR fuerza proveedor, gates y efectos; ADR ante cambio de proveedor/scope.
- Retomar G3 esquema PST, luego G4/G5 ingesta sintética y G6/G7 marketing Growth.
- No repetir credenciales, ni instalaciones, ni crear un nuevo paquete cuando el existente ya está certificado.
- BC 0.7.1 sigue en `test` y no se interpreta como envío Growth operativo.

**No se borran PR #36/#40**: contienen pruebas offline válidas solo para un eventual proveedor Engagement, sin relación con ABH. Mantener esa frontera es parte del producto reutilizable.
