# Desarrollo del puente Growth + Data Cloud — reglas v2

**Decisión actual:** producto exclusivo **Marketing Cloud Growth + Data Cloud** para PrestaShop 1.7/8/9; piloto Barcelona Confort. Un único destino y sin selector alternativo. Los componentes ejecutables antiguos se retiran del paquete 0.8.0. No eliminar automáticamente tablas ni datos de una instalación anterior.

| Gate | Evidencia y siguiente resultado necesario | Estado |
| --- | --- | --- |
| G0 | Contrato del producto Growth-only | VERIFICADO |
| G1 | App externa y OAuth Salesforce Core, scope probado | VERIFICADO |
| G2 | Data Cloud token exchange, PST existente | VERIFICADO sin escrituras |
| G3 | Tres decisiones de dirección aceptadas por propietario; no repetirlas. Bases legales por finalidad no inferidas | ACEPTADO propietario, sin generalizar a consentimiento de campañas |
| G4 | Esquema PST guardado, 2 Data Streams activos, 2 mapeos LISTO, relación pedido→Individual N:1 (capturas 16/11/2026) | **VERIFICADO estructural** |
| G5 | Synchronous `/actions/test` sin persistencia, workflow manual. Ingesta real por endpoint 202 en otro corte | TEST EN PREPARACIÓN; ingesta persistente BLOQUEADO |
| G6 | Consentimiento y supresión Growth, sin confiar solo en flags newsletter | PENDIENTE |
| G7 | Flow/campañas Growth, recuperación y dedupe verificados | PENDIENTE |
| G8 | Release, rollback, CI/runtime 1.7/8/9 y autorización BC | PENDIENTE |

## Reglas concretas

1. **Un cambio, un resultado.** No repetir G1/G2 ni crear nueva app, credenciales o paquetes de otro producto.
2. **Primero estado real:** `main`, PRs, CI, `AGENTS.md`, contrato, canon y Roadmap.
3. **Solo Growth + Data Cloud.** Mantener un núcleo multisite con namespace explícito. No protocolo discontinuado, almacenamiento discontinuado ni orquestadores discontinuados API.
4. **Fail-closed:** en la nueva 0.8.0 el módulo puede inspeccionar formas de hooks y metadata sin enviar ni persistir datos personales; la verdadera cola Growth necesita su siguiente corte y el contrato G3. No llamar sincronización a un preflight.
5. **Reversible por defecto:** el upgrade desde 0.7.1 debe conservar las tablas/configuración existentes, bloquear antiguas rutas de ejecución y forzar Test. No purgar datos locales sin autorización y respaldo.
6. **Datos personales:** ninguna PII en tests, artifacts, logs o chat. No deducir origen UTC de fechas MySQL `Europe/Madrid`; separar base legal y finalidad de cada consentimiento.
7. **CI/release:** PHP >=7.1, PS 1.7.8.11/8.2.8/9.1.5, pruebas negativas y de actualización, CI exact-head, merge solo con todas verdes. Prohibido afirmar uso productivo si solo hay pruebas.
8. **Avance real:** tras cada corte informar PR/commit/pruebas y siguiente bloqueo mínimo. No ampliar otro tema mientras el proyecto principal esté abierto.

**BC actual:** 0.7.1 en modo Test, sin reinstalar. Versión 0.8.0 Growth-only integrada en `main` y certificada por CI/runtime, pero no instalada en BC. **G4 estructural completado por UI en Salesforce**; ahora se ejecuta G5 `/actions/test` exclusivamente sintético y no persistente. No habilitar envíos ni ingesta real.
