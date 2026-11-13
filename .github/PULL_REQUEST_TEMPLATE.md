## Propósito, merchant y proveedor

- **Objetivo comercial / ticket:**
- **Merchant / tienda:** (ABH/BC, otro merchant, shared core)
- **Destino Salesforce:** (Growth + Data 360 / Engagement legacy / shared only)
- **Gate actual → gate objetivo:** (G0–G8 de `docs/PROJECT_DEVELOPMENT_RULES.md`)
- **Evidencia preexistente reutilizada:**

## Impacto técnico y seguridad

- [ ] Consulté `AGENTS.md`, `config/project-contract.json` y `docs/ABH_GROWTH_CANONICAL_TARGET.md`.
- [ ] Para ABH **no** usé APIs/documentación exclusiva Engagement.
- [ ] Se separan core común, adaptador proveedor y namespace del merchant.
- [ ] Sin secretos, tokens, PII, fuentes sensibles ni RecoveryUrl en diff/logs.
- [ ] Sin activar Production, backfill, Data 360 ingest, API writes ni campañas sin autorización.
- [ ] El consentimiento, finalidad, oposición, supresión, identidad y tiempos se mantienen fail-closed.
- [ ] Certificación sobre PHP 7.1+ / PrestaShop 1.7.8.11, 8.2.8, 9.1.5 cuando aplica.
- [ ] Si hay cambios de arquitectura/proveedor/esquema, existe ADR y autorización.
- [ ] CI exact-head y post-merge verificada; no se afirma “live” a partir de tests offline.

## Evidencia y entrega

- **Archivos y efectos reales:**
- **Tests exactos / CI run y SHA:**
- **Estado:** (verificado / propuesto / pendiente / bloqueado)
- **Cambios en DB/BO/ZIP/secretos:** (ninguno o especificar)
- **Plan de rollback / criterio de parada:**
- **Próxima acción concreta y aprobación necesaria:**
