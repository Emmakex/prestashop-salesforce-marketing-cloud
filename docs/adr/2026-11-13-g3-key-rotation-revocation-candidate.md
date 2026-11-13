# ADR propuesta · Ciclo de vida de claves de instalación G3-01

**Fecha:** 2026-11-13  
**Estado:** PROPUESTA TÉCNICA SIN APROBACIÓN DE PRODUCCIÓN  
**Ámbito:** Puente PrestaShop multiinstalación / multicomercio; ABH → Marketing Cloud Growth + Data 360 (no Engagement).

## Decisión candidata en CI

Antes de permitir a una instalación presentarse ante el registro global
de namespaces, comprobar su posesión de una clave **registrada por operador
externo** mediante desafío criptográfico de uso único. Vincular desafíos
a generación y huella de clave. Cada rotación debe incrementar la versión
atómicamente y dejar sin validez desafíos anteriores. Cada revocación
debe negar nuevas pruebas, también las que fueron emitidas antes de la
revocación. Mantener el binding de instalación y la auditoría mínima.
**Ninguna prueba de identidad autoriza Data 360 ni campañas.**

Los métodos de cambio solo existen en el fixture `g3_ci` y usan un
indicador de operador simulado. No constituyen una implementación de
autenticación humana real ni un permiso para BC.

## Alternativas rechazadas en el prototipo

- Solo UUID de instalación o `source_namespace`: clonables con la BD.
- Solo firma válida sin generaciones: permite usar desafíos antiguos
  tras una rotación si su nonce todavía no se consumió.
- Rotación no transaccional: introduce carreras entre verificar firma
  y revocar/cambiar clave.
- Reinscripción automática tras revocar: habilitaría credenciales
  comprometidas sin revisión de seguridad.
- Borrar el registro al desinstalar: podría perder trazabilidad y
  permitir reutilización de namespaces.

## Riesgos y condiciones de producción

Un almacén de claves **exportable** no garantiza unicidad física.
La solución real deberá definir identidad de agente/instalación no
clonable, procedimiento de enrolamiento y renovación con operador
autenticado, custodia (TPM/HSM/servicio externo o equivalente), TTL y
rate limits, auditoría, emergencia, gestión multitenant, backups y
disaster recovery. El estado bloqueado no podrá pasar a autorizado por
rotar una clave.

Los responsables técnico, de seguridad y de datos deben aprobar
G3-01 y documentar G3-02…G3-08 por separado. Publicar esquema,
conectar transportes, crear flujos de datos, campañas y activar
Production tendrán sus autorizaciones independientes.

## Evidencia y reversión

Pruebas CI-only: `tests/integration/g3_installation_proof.php` y
`tests/integration/g3_proof_key_lifecycle.php` contra bases desechables
MySQL8 / MariaDB10.11. Revisiones `docs/G3_EXTERNAL_INSTALLATION_CHALLENGE_CI.md`
y `docs/G3_INSTALLATION_KEY_LIFECYCLE_CI.md`.

Un revert revierte solo documentación y pruebas. No hay upgrade de
`pssalesforcebridge`, migración, ZIP, secretos reales ni cambio en
Barcelona Confort 0.7.1 Test.
