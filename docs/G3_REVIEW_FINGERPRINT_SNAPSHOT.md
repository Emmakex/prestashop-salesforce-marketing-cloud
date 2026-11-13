# G3 — Snapshot de revisión verificable (SIN firma ni autorización)

**13/11/2026 · ABH / Barcelona Confort · Marketing Cloud Growth + Data 360 / PST.**

**Estado: borrador sin aprobar.** Los archivos PSCustomer/PSOrder siguen sin
publicarse en Salesforce. Este registro facilita que el responsable de datos
revise una versión identificable y detecte cambios posteriores sin volver
a examinar un fichero diferente inadvertidamente.

## Tres artefactos fijados por SHA-256

| Artefacto de revisión | Campos | SHA-256 |
| --- | ---: | --- |
| `schemas/ABH_PST_SCHEMA_REVIEW.yaml` (v0.2) | 22 | `8595c2f9acb5be388ba3b2ec156ec65379ab04bf5ebf3718e63231cb06b43e9d` |
| `schemas/ABH_PST_SCHEMA_UTC_CANDIDATE.yaml` (v0.3) | 24 | `3405a884d9cbe7691ad363825f3a730cf88a6742117925113a4f36633cda05f0` |
| `config/g3-pst-schema-review.json` (dossier de ocho decisiones) | 24 inventariados | `e1b1894cce21afce84a5806edca4ea5645a672a041c46805340895a189fcc4db` |

La fuente legible por máquina es
[`config/g3-pst-review-fingerprint-freeze.json`](../config/g3-pst-review-fingerprint-freeze.json).
La comprobación `python3 scripts/ci/verify-g3-review-fingerprint.py`
recalcula SHA-256 con los bytes reales y verifica el inventario, los
24 campos, las ocho decisiones pendientes y las prohibiciones de
publicar, ingerir o enviar. Los tests inyectan alteraciones, rehashes
fraudulentos y falsos «aprobado» para comprobar el rechazo.

**Una huella SHA-256 no firma un documento:** cualquiera con permiso de
editar el repositorio podría generar un nuevo manifiesto. La aprobación
humana debe citar el commit exacto, la huella del YAML elegido, el objeto,
la finalidad y las ocho decisiones. No existe firma ni autorización
de publicación en este corte. No usar este fichero como indicador de
consentimiento ni como alternativa a G3-02/G3-07.

## El siguiente paso de decisión — sin SQL de producción

[Hoja operativa para el responsable (G3-01…G3-08)](G3_OWNER_DECISION_READY.md): contiene propuestas, evidencia faltante y opciones de aceptar/modificar/rechazar, con **todas las decisiones pendientes**.

El [dossier completo](G3_PST_DATA_OWNER_APPROVAL_PACK.md)
identifica ocho decisiones concretas:

| Grupo | Punto que deberá resolver el responsable |
| --- | --- |
| Identidad | **G3-01:** namespace exclusivo por instalación, sin mezclar por email |
| Privacidad | **G3-02:** finalidad, minimización y base jurídica por campo |
| Fechas | **G3-03 y G3-04:** origen histórico de DATETIME, política de nuevas observaciones, Record Modified y modo Full/Partial |
| Modelo Data 360 | **G3-05 y G3-06:** categorías, Data Space, PK y relación DLO/DMO |
| Conservación | **G3-07:** plazos, bajas, supresiones y derecho de borrado |
| Aceptación | **G3-08:** seleccionar **una** versión, aprobar huella y revisar vista previa en PST sin guardar |

**Recomendación técnica conservadora, pendiente de aprobación:** no
convertir automáticamente las fechas históricas `date_upd` en UTC ni
considerar `captured_at_utc` como fecha de modificación fuente. Cualquier
histórico sin procedencia verificable queda fuera de cargas incrementales
y campañas. Los indicadores de newsletter/partner no habilitan envíos
Growth. La elección del esquema v0.2 o v0.3 continúa abierta.

Aprobar el esquema **no** autoriza crear Data Streams, ingerir clientes,
activar Flow ni enviar campañas; cada fase posterior tiene su gate.

**El módulo instalado BC sigue en 0.7.1 y Test. No se requiere instalación,
acceso SQL del usuario ni cambios de Salesforce.**
