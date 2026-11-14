#!/usr/bin/env python3
"""G4 PREPARATION ONLY: consolidated, sanitized, fail-closed ABH readiness.

No network, SQL, credentials, PrestaShop access or Salesforce writes.
The optional input is the EXISTING G3 DMO metadata-only artifact produced by
the protected workflow on main. No record payloads, custom fields, tenant
URLs, contact IDs or API bodies are read or reproduced.
"""
import hashlib
import json
import os
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[2]
SOURCE_SCHEMA = "schemas/ABH_PST_SCHEMA_REVIEW.yaml"
SOURCE_HASH = "8595c2f9acb5be388ba3b2ec156ec65379ab04bf5ebf3718e63231cb06b43e9d"
SOURCE_METADATA = "g3-abh-dmo-catalog-sanitized.json"
DEST_REPORT = "g4-abh-readiness-sanitized.json"
MAX_METADATA_BYTES = 150000
OBJECTS = ("Individual", "SalesOrder", "Account", "AccountContact", "ContactPointEmail")
CATEGORIES = {"PROFILE", "OTHER", "UNASSIGNED", "RELATED", "ENGAGEMENT"}
SCANS = {
    "Individual": "individual",
    "SalesOrder": "sales_order",
    "Account": "account",
    "AccountContact": "account_contact",
    "ContactPointEmail": "contact_email",
}
ROUTES = {
    "account": (
        "sales_order_to_account_bill_to_relation_seen",
        "active_sales_order_to_account_bill_to_relation_seen",
        "account_to_individual_relation_seen",
        "active_account_to_individual_relation_seen",
        "account",
    ),
    "account_contact": (
        "sales_order_to_account_contact_bill_to_relation_seen",
        "active_sales_order_to_account_contact_bill_to_relation_seen",
        "account_contact_to_individual_relation_seen",
        "active_account_contact_to_individual_relation_seen",
        "account_contact",
    ),
    "contact_point_email": (
        "sales_order_to_contact_email_bill_to_relation_seen",
        "active_sales_order_to_contact_email_bill_to_relation_seen",
        "contact_email_to_individual_party_relation_seen",
        "active_contact_email_to_individual_party_relation_seen",
        "contact_email",
    ),
}
FALSE_ONLY = (
    "credential_values_recorded", "record_values_recorded",
    "salesforce_record_reads", "salesforce_data_writes", "schema_uploaded",
    "data_stream_created", "dlo_dmo_mapping_saved",
    "marketing_messages_sent", "owner_g3_approval",
)
CATALOG_FALSE_ONLY = (
    "tenant_mapping_approved", "identity_resolution_authorized",
    "schema_upload_authorized", "data_360_ingestion_authorized",
    "marketing_sends_authorized", "salesforce_record_reads",
    "salesforce_data_writes",
)
OWNER_ACTIONS = (
    "confirm_namespace_final_purposes_retention_and_privacy",
    "approve_customer_order_tenant_relationship_and_refresh",
    "approve_exact_schema_sha_and_PST_preview_without_publishing",
)


def local_contract_verified(closure, freeze, packet, privacy, mapping, project, source_bytes):
    """Recheck existing approval gates without mutating or signing them."""
    if not all(isinstance(v, dict) for v in
               (closure, freeze, packet, privacy, mapping, project)):
        return False
    proposed = closure.get("selected_for_technical_review")
    if not isinstance(proposed, dict) or any(proposed.get(k) != v for k, v in {
        "version": "v0.2", "file": SOURCE_SCHEMA, "sha256": SOURCE_HASH,
        "field_count": 22, "final_schema_approved": False,
        "schema_to_publish": None,
    }.items()):
        return False
    if closure.get("merchant") != "ABH" \
            or closure.get("product") != "marketing_cloud_growth_data_360" \
            or closure.get("review_stage") != "technical_closure_candidate_not_owner_approval" \
            or closure.get("module_installed_version") != "0.7.1" \
            or closure.get("module_mode") != "test" \
            or closure.get("production_impact") != "none" \
            or closure.get("shop_sql_access_required") is not False \
            or closure.get("new_module_installation_required") is not False \
            or closure.get("only_remaining_owner_actions") != list(OWNER_ACTIONS):
        return False
    x1 = closure.get("point_1_data_privacy")
    x2 = closure.get("point_2_customer_order_relationship")
    x3 = closure.get("point_3_schema_finalization")
    if not all(isinstance(v, dict) for v in (x1, x2, x3)):
        return False
    if any(x1.get(k) != v for k, v in {
        "privacy_owner_signoff": "pending",
        "erasure_and_suppression_signoff": "pending",
        "global_namespace_reserved_for_abh": False,
        "namespace_registry_operator_approved": False,
        "raw_optins_are_marketing_consent": False,
        "historical_customer_order_backfill": "excluded",
        "raw_historical_datetime_as_record_modified": False,
        "new_observation_timestamp_utc_is_source_modified": False,
        "record_modified_field": None,
        "refresh_mode": "not_enabled",
        "retention_days": None,
        "approved_processing_purpose": None,
        "export_to_data_360_authorized": False,
        "growth_sends_authorized": False,
    }.items()):
        return False
    if any(x2.get(k) != v for k, v in {
        "local_parent": "PSCustomer.source_key",
        "local_child": "PSOrder.customer_source_key",
        "match_dimensions": ["source_namespace", "shop_id"],
        "invalid_relationship_action": "quarantine_without_export",
        "join_by_email_authorized": False,
        "dmo_linked_target": None,
        "dmo_relation_approved": False,
        "data_360_architect_signoff": "pending",
        "dlo_dmo_mapping_save_authorized": False,
    }.items()):
        return False
    if any(x3.get(k) != v for k, v in {
        "technical_candidate_sha_verified_against_frozen_review": True,
        "salesforce_pst_preview_confirmed_by_owner": False,
        "data_owner_schema_signoff": "pending",
        "data_360_architect_schema_signoff": "pending",
        "schema_approved": False,
        "g4_publication_authorized": False,
        "data_360_ingestion_authorized": False,
        "marketing_campaign_send_authorized": False,
    }.items()):
        return False
    if not isinstance(source_bytes, bytes) \
            or hashlib.sha256(source_bytes).hexdigest() != SOURCE_HASH \
            or freeze.get("artifacts", {}).get(SOURCE_SCHEMA, {}).get("sha256") != SOURCE_HASH \
            or freeze.get("schema_to_publish") != "undecided" \
            or freeze.get("owner_signoff") != "pending" \
            or freeze.get("approved_by") is not None \
            or freeze.get("approved_at") is not None \
            or any(freeze.get(k) is not False for k in (
                "data_360_upload_authorized", "data_360_ingestion_authorized",
                "growth_marketing_sends_authorized",
            )):
        return False
    if packet.get("approval_state") != "pending_data_owner" \
            or packet.get("schema_to_publish") != "undecided" \
            or packet.get("review_only") is not True \
            or any(packet.get(k) is not False for k in (
                "uploaded_to_tenant", "streams_approved", "real_data_ingestion_approved",
                "marketing_sends_approved",
            )):
        return False
    decisions = packet.get("open_decisions")
    if not isinstance(decisions, list) or [
        (v.get("id"), v.get("state")) if isinstance(v, dict) else None
        for v in decisions
    ] != [("G3-%02d" % i, "pending") for i in range(1, 9)]:
        return False
    if privacy.get("state") != "pending_data_privacy_owner" \
            or privacy.get("purpose_signoff") != "pending" \
            or privacy.get("retention_signoff") != "pending" \
            or privacy.get("schema_selection") != "undecided" \
            or any(privacy.get(k) is not False for k in (
                "data_cloud_schema_upload_authorized",
                "data_cloud_ingestion_authorized", "marketing_sends_authorized",
            )):
        return False
    field_reviews = privacy.get("field_decisions")
    if not isinstance(field_reviews, dict) or set(field_reviews) != {"PSCustomer", "PSOrder"}:
        return False
    for name in ("PSCustomer", "PSOrder"):
        entries = field_reviews[name]
        if not isinstance(entries, list) or len(entries) != 12 \
                or any(not isinstance(field, dict)
                       or field.get("decision") != "pending"
                       or field.get("lawful_basis_verified") is not False
                       or field.get("retention_approved") is not False
                       or field.get("export_to_data_360_authorized") is not False
                       or field.get("growth_send_authorized") is not False
                       for field in entries):
            return False
    relation = mapping.get("relation_proposal")
    if mapping.get("schema_version_selected") != "undecided" \
            or mapping.get("owner_signoff") != "pending" \
            or mapping.get("data_space_approved") is not False \
            or any(mapping.get(k) is not False for k in (
                "dlo_dmo_mapping_save_authorized", "identity_resolution_authorized",
                "data_360_ingestion_authorized", "growth_marketing_sends_authorized",
            )) \
            or not isinstance(relation, dict) \
            or relation.get("selected_dmo_target_field") is not None \
            or relation.get("dmo_relationship_verified_in_tenant") is not False \
            or relation.get("email_dedupe_authorized") is not False:
        return False
    pilot = project.get("pilot")
    if not isinstance(pilot, dict) or any(pilot.get(k) != v for k, v in {
        "merchant": "ABH", "provider": "marketing_cloud_growth",
        "data_platform": "data_cloud_data_360",
        "data_cloud_connector_alias": "PST",
        "data_cloud_source_api_name": "ABH_PrestaShop_Data_Cloud",
        "installed_module_version_observed": "0.7.1",
        "prestashop_runtime_mode_observed": "test",
        "data_cloud_schema_status": "schema_required_unapproved",
        "customer_data_live_ingestion_authorized": False,
        "historical_backfill_authorized": False,
        "live_campaign_sends_authorized": False,
    }.items()) or project.get("current_delivery_gate") != "pst_schema_data_owner_approval" \
            or project.get("architecture") != "growth_only_multi_shop" \
            or set(project.get("provider_registry", {})) != {"marketing_cloud_growth"} \
            or project.get("delivery_policy") != "growth_only_no_other_salesforce_product":
        return False
    return True


def empty_metadata(status):
    return {
        "state": status,
        "this_run_data_360_catalog_verified": False,
        "dmos": {name: {"readable": "unverified", "category": "UNVERIFIED",
                         "primary_key": "unverified",
                         "relationships_count": None,
                         "relationships_complete": "unverified"}
                 for name in OBJECTS},
        "buyer_routes": {name: "unverified" for name in ROUTES},
        "dlo_dmo_mapping_certified": False,
    }


def metadata_summary(evidence):
    """Never copy untrusted strings, custom fields, URLs or IDs into report."""
    if evidence is None:
        return empty_metadata("no_current_run_artifact")
    if not isinstance(evidence, dict) or any(evidence.get(k) != v for k, v in {
        "schema_version": 1, "merchant": "ABH",
        "product": "marketing_cloud_growth_data_360",
        "connector_alias": "PST",
        "stage": "g3_data_360_dmo_catalog_readonly",
        "status": "metadata_read_success_review_only",
    }.items()) or any(evidence.get(k) is not False for k in FALSE_ONLY):
        return empty_metadata("invalid_or_failed_metadata_artifact")
    catalog = evidence.get("catalog")
    if not isinstance(catalog, dict) or any(
            catalog.get(k) is not False for k in CATALOG_FALSE_ONLY):
        return empty_metadata("invalid_or_failed_metadata_artifact")
    objects, relations = catalog.get("objects"), catalog.get("relationship")
    if not isinstance(objects, dict) or not isinstance(relations, dict):
        return empty_metadata("invalid_or_failed_metadata_artifact")

    result = empty_metadata("sanitized_metadata_observed_this_run")
    result["this_run_data_360_catalog_verified"] = True
    for name in OBJECTS:
        raw = objects.get(name)
        raw = raw if isinstance(raw, dict) else {}
        category = raw.get("observed_category")
        category = category if type(category) is str and category in CATEGORIES else "UNVERIFIED"
        prefix = SCANS[name]
        count = relations.get(prefix + "_relationship_count")
        count = count if type(count) is int and 0 <= count <= 500 else None
        result["dmos"][name] = {
            "readable": "yes" if raw.get("metadata_readable") is True else "unverified",
            "category": category,
            "primary_key": "yes" if raw.get("id_field_is_primary_key") is True else "unverified",
            "relationships_count": count,
            "relationships_complete": (
                "yes" if relations.get(prefix + "_relationship_scan_complete") is True
                else "unverified"
            ),
        }
    for key, (first_seen, first_active, second_seen, second_active, intermediate) in ROUTES.items():
        complete = all(relations.get(SCANS[n] + "_relationship_scan_complete") is True
                       for n in ("SalesOrder", {
                           "account": "Account",
                           "account_contact": "AccountContact",
                           "contact_point_email": "ContactPointEmail",
                       }[key]))
        both_active = all(relations.get(x) is True for x in (
            first_seen, first_active, second_seen, second_active))
        if complete and both_active and all(
            result["dmos"][n]["readable"] == "yes"
            for n in ("SalesOrder", {
                "account": "Account", "account_contact": "AccountContact",
                "contact_point_email": "ContactPointEmail",
            }[key], "Individual")
        ):
            result["buyer_routes"][key] = "two_active_legs_observed_mapping_unapproved"
        else:
            result["buyer_routes"][key] = "not_certified"
    return result


def build_report(sources, metadata=None):
    required = ("closure", "freeze", "packet", "privacy", "mapping", "project", "schema")
    if not isinstance(sources, dict) or any(k not in sources for k in required):
        good = False
    else:
        good = local_contract_verified(*(sources[k] for k in required))
    return {
        "report_version": 1,
        "merchant": "ABH",
        "product": "marketing_cloud_growth_data_360",
        "stage": "g4_offline_and_metadata_readonly_preflight",
        "local_contract": "verified_pending_owner_approval" if good
                          else "blocked_invalid_local_contract",
        "schema_candidate": {
            "version": "v0.2", "objects": ["PSCustomer", "PSOrder"],
            "field_count": 22,
            "sha256": SOURCE_HASH,
            "hash_verified": bool(good),
            "status": "technical_review_only_not_selected_for_publication",
        },
        "pilot": {
            "module_version_last_observed": "0.7.1",
            "mode_last_observed": "test",
            "shop_sql_engine": "not_observed",
            "shop_growth_schema_installability": "not_certified",
        },
        "lab": {
            "prestashop_versions": ["1.7.8.11", "8.2.8", "9.1.5"],
            "runtime_ci_run_reference": 43624367487,
            "database_in_prestashop_runtime": "mysql_5_7",
            "growth_sql_check_capability_in_runtime": "blocked",
            "ci_result_scope": "disposable_ci_only_not_pilot_database",
        },
        "data_360": metadata_summary(metadata),
        "remaining_owner_actions": list(OWNER_ACTIONS),
        "g3_owner_signoffs_received": False,
        "g3_formally_closed": False,
        "g4_schema_upload_authorized": False,
        "g4_data_stream_create_authorized": False,
        "g4_dlo_dmo_mapping_save_authorized": False,
        "data_360_ingestion_authorized": False,
        "historical_backfill_authorized": False,
        "marketing_sends_authorized": False,
        "new_prestashop_module_installation_required": False,
        "production_impact": "none",
    }


def load_sources(root=ROOT):
    try:
        def read(path):
            return json.loads((root / path).read_text(encoding="utf-8"))
        return {
            "closure": read("config/g3-abh-technical-closure-candidate.json"),
            "freeze": read("config/g3-pst-review-fingerprint-freeze.json"),
            "packet": read("config/g3-pst-schema-review.json"),
            "privacy": read("config/g3-field-purpose-owner-decisions.json"),
            "mapping": read("config/g3-pst-dlo-dmo-mapping-candidate.json"),
            "project": read("config/project-contract.json"),
            "schema": (root / SOURCE_SCHEMA).read_bytes(),
        }
    except (OSError, ValueError, UnicodeError):
        return {}


def read_metadata(path):
    if not path.exists():
        return None
    try:
        if path.is_symlink() or not path.is_file() \
                or path.stat().st_size > MAX_METADATA_BYTES:
            return {"status": "invalid"}
        with path.open("r", encoding="utf-8") as f:
            return json.load(f)
    except (OSError, ValueError, UnicodeError):
        return {"status": "invalid"}


def markdown(report):
    state = report["local_contract"]
    md = [
        "## ABH · G4 preflight unificado (solo lectura, sin aprobación)",
        "",
        "**G3:** 3 decisiones para firma; "
        + ("contratos técnicos íntegros" if state == "verified_pending_owner_approval"
           else "contratos locales inconsistentes, investigar")
        + ". **G4, ingesta, mapeos y envíos: BLOQUEADOS.**",
        "",
        "| Fuente | Resultado |",
        "|---|---|",
        "| Esquema candidato | v0.2, 22 campos; hash "
        + ("verificado" if report["schema_candidate"]["hash_verified"] else "NO verificado")
        + "; no publicable |",
        "| Barcelona Confort | 0.7.1 Test; motor SQL real no observado |",
        "| PrestaShop CI 1.7/8/9 | 3/3 aprobadas en run #43624367487; "
        "MySQL 5.7 bloquea el nuevo DDL Growth |",
        "| Catálogo Data 360 en esta ejecución | "
        + report["data_360"]["state"] + "; mapping NO aprobado |",
        "",
        "### Metadatos de objetos (solo flags saneados)",
        "",
        "| DMO | Legible | Categoría | PK | Relaciones / paginación |",
        "|---|---|---|---|---|",
    ]
    for name, info in report["data_360"]["dmos"].items():
        count = info["relationships_count"]
        md.append("| " + name + " | " + info["readable"]
                  + " | " + info["category"] + " | " + info["primary_key"]
                  + " | " + ("no verificado" if count is None else str(count))
                  + " / " + info["relationships_complete"] + " |")
    md += [
        "",
        "### Rutas observadas (nunca aprobadas para mapeo)",
        "",
        "| Ruta | Estado |", "|---|---|",
    ]
    for route, status in report["data_360"]["buyer_routes"].items():
        md.append("| " + route + " | " + status + " |")
    md += [
        "", "### Únicas decisiones formales restantes",
        "",
        "1. Namespace, finalidad, consentimiento, retención y borrado.",
        "2. Relación cliente-pedido en el tenant + estrategia de actualización.",
        "3. Firma del esquema definitivo, hash y preview PST sin publicar.",
        "",
        "**No se consultaron clientes, pedidos, carritos ni direcciones de correo.** "
        "La lectura de metadatos, si se ejecutó, pertenece al workflow "
        "protegido existente. Las relaciones estándar inactivas pueden "
        "no aparecer como activas; no interpretar una ruta no observada "
        "como inexistente. Nunca hay autorización implícita de publicación.",
        "",
    ]
    return "\n".join(md)


def main():
    if len(sys.argv) != 1:
        raise SystemExit("No arguments accepted")
    report = build_report(load_sources(), read_metadata(Path(SOURCE_METADATA)))
    # Fixed output basename. Only approved keys and bounded enum values.
    Path(DEST_REPORT).write_text(
        json.dumps(report, indent=2, sort_keys=True, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    destination = os.environ.get("GITHUB_STEP_SUMMARY")
    if destination:
        with open(destination, "a", encoding="utf-8") as f:
            f.write(markdown(report))
    print("G4 consolidated readiness: " + report["local_contract"]
          + "; DMO metadata: " + report["data_360"]["state"]
          + "; no schema upload, mapping, ingestion, or sends.")
    if report["local_contract"] != "verified_pending_owner_approval":
        raise SystemExit(1)


if __name__ == "__main__":
    main()
