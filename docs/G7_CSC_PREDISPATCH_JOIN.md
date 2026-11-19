# G7 — unión de fuente nativa y consentimiento CSC (solo lectura)

**19 de noviembre de 2026.** PrestaShop 9.1.5 staging usa el módulo 0.8.8, outbox G3 ampliada y bloqueada, sin envíos. PR #147 integró dos lecturas nativas y comprobación de la revisión vigente; G6 certificó la topología de **Communication Subscription Consent** (Marketing/Email) administrativamente, pero no un lector de consentimiento certificado para despacho.

## Qué incorpora el incremento

`GrowthG7CscPreDispatchReview::inspectLatest` conecta exactamente los componentes ya existentes:

- `GrowthG7NativePreflight`: outbox G3 ampliada, ledger vigente, lectura PSCustomer/PSOrder con proyección PST v0.4 y comprobación local de cuentas eliminadas/inactivas/invitadas, newsletter u opt-in negativos.
- `GrowthCommunicationConsentHoldGate` (G6): busca un CSC referido al **cliente real obtenido del propio evento o pedido**, al shop, namespace y suscripción concreta. Un cliente independiente proporcionado por el llamador nunca puede desviar el consentimiento de un pedido a otra identidad. La lectura se realiza solo si la fuente local es estable, el cliente no presenta exclusiones y la fila continúa `blocked`.
- Vuelve a releer fuente y ledger tras consultar el CSC y compara, únicamente en memoria, una huella de los objetos nativos y la última secuencia. Cualquier alteración impide inferir elegibilidad.
- Los resultados devuelven motivos sanitizados, sin IDs, email, nombres, hashes, URL, token ni cuerpo PST. Ni el consentimiento sintético más reciente sirve como autorización de envío: devuelve `csc_candidate_requires_sendtime_fence`, nunca un permiso.

**No hay envío:** la clase no modifica `delivery_state`, no llama `claim`, no crea otra cola, ni usa HTTP o cron. El lector CSC externo se inyecta solo como dependencia de prueba, no como un conector Salesforce certificado. El hecho de que G6 esté aceptado administrativamente no certifica consentimientos individuales ni permite transmitir información.

## Pruebas

En `g3_ci`, sobre los mismos cinco eventos G3 existentes de MySQL 8 y MariaDB 10.11/11.8: se prueba el origen `customer`, la relación real `order→customer`, consentimiento CSC positivo pero no autorizante, CSC `opt_out`, ausencia/obsolescencia/error del lector, baja nativa local que evita siquiera consultar CSC, modificación nativa durante la consulta y ausencia de fugas de PII. La outbox queda inalterada y `blocked` tras el ensayo.

El contrato de PHP 7.1–8.5 rechaza hooks, cron, transporte, `ready`, escrituras SQL y cualquier resultado que implique envío.

## Pendientes reales de salida

1. Un lector real CSC certificado para Growth en el instante de envío, con tratamiento de exclusiones y frecuencia por finalidad; **no basta con los fixtures de prueba**.
2. Resolver el *fence* final entre la relectura local y el POST y aprobar la política de transición `blocked→ready`.
3. Transporte autorizado Data Cloud y conciliación asíncrona HTTP 202→DLO/DMO, sin reintentos ciegos.
4. Descartar recuperación de carritos tras compra o baja, con ventana de 60 minutos.
5. G8, ensayo UAT reversible y autorización expresa antes de tocar BC producción.

Este incremento no requiere ZIP ni instalación: staging **0.8.8 Test** y Barcelona Confort producción **0.7.1 Test** permanecen intactos.