"""Synthetic-only tests: G3 owner review summaries must not leak tenant data."""
import importlib.util
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1]
PATH = ROOT / "scripts/ci/render-g3-dmo-review-summary.py"
SPEC = importlib.util.spec_from_file_location("g3_dmo_summary", PATH)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


def evidence():
    objects = {}
    relationships = {}
    for dmo, slug in MODULE.SCANS.items():
        objects[dmo] = {
            "metadata_readable": True,
            "observed_category": "PROFILE",
            "id_field_is_primary_key": True,
            "candidate_fields_present": {
                "tenant_sensitive_field__c": "secret-not-for-summary"
            },
        }
        relationships[slug + "_relationship_count"] = 7
        relationships[slug + "_relationship_scan_complete"] = True
    for row in MODULE.RELATIONS:
        for k in row[1:]:
            relationships[k] = True
    return {
        "schema_version": 1,
        "merchant": "ABH",
        "product": "marketing_cloud_growth_data_360",
        "stage": "g3_data_360_dmo_catalog_readonly",
        "status": "metadata_read_success_review_only",
        "credential_values_recorded": False,
        "record_values_recorded": False,
        "salesforce_record_reads": False,
        "salesforce_data_writes": False,
        "schema_uploaded": False,
        "data_stream_created": False,
        "dlo_dmo_mapping_saved": False,
        "marketing_messages_sent": False,
        "owner_g3_approval": False,
        "catalog": {
            "objects": objects,
            "relationship": relationships,
            "tenant_mapping_approved": False,
            "identity_resolution_authorized": False,
            "schema_upload_authorized": False,
            "data_360_ingestion_authorized": False,
            "marketing_sends_authorized": False,
            "salesforce_record_reads": False,
            "salesforce_data_writes": False,
        },
        "access_token": "secret-must-never-appear",
        "customer_email": "private@example.invalid",
    }


class G3DmoOwnerReviewSummaryTests(unittest.TestCase):
    def test_valid_summary_never_authorizes(self):
        summary = MODULE.render(evidence())
        self.assertIn("SalesOrder", summary)
        self.assertIn("AccountContact", summary)
        self.assertIn("ContactPointEmail", summary)
        self.assertIn("Visible ACTIVE", summary)
        self.assertIn("NO aprobación", summary)
        self.assertIn("G3 #45", summary)
        self.assertNotIn("secret-must-never-appear", summary)
        self.assertNotIn("private@example.invalid", summary)
        self.assertNotIn("secret-not-for-summary", summary)
        self.assertNotIn("tenant_sensitive_field__c", summary)

    def test_failure_or_missing_metadata_cannot_be_approval(self):
        for fixture in (None, [], {}, {"status": "failed"}, "fake"):
            summary = MODULE.render(fixture)
            self.assertIn("No hay evidencia validada", summary)
            self.assertIn("G3 pendiente", summary)
        for key in MODULE.FALSE_ONLY:
            bad = evidence()
            bad[key] = True
            self.assertIn("No hay evidencia validada", MODULE.render(bad), key)
        for key in MODULE.CATALOG_FALSE_ONLY:
            bad = evidence()
            bad["catalog"][key] = True
            self.assertIn("No hay evidencia validada", MODULE.render(bad), key)

    def test_untrusted_fields_and_categories_are_not_reflected(self):
        bad = evidence()
        bad["catalog"]["objects"]["SalesOrder"]["observed_category"] = (
            "javascript:private@example.invalid"
        )
        bad["catalog"]["relationship"]["sales_order_relationship_count"] = (
            "500\\n|fake|data|"
        )
        bad["catalog"]["objects"]["Account"]["metadata_readable"] = "true"
        bad["catalog"]["relationship"]["account_relationship_scan_complete"] = "true"
        bad["catalog"]["relationship"]["active_account_to_individual_relation_seen"] = "true"
        summary = MODULE.render(bad)
        self.assertNotIn("javascript", summary)
        self.assertNotIn("private@example.invalid", summary)
        self.assertNotIn("fake", summary)
        self.assertIn("Sin verificar", summary)
        self.assertIn("Parcial o no verificada", summary)
        self.assertIn("Visible; ACTIVE no confirmado", summary)

    def test_inactive_or_not_observed_never_means_missing_standard(self):
        bad = evidence()
        relation = bad["catalog"]["relationship"]
        relation["sales_order_to_contact_email_bill_to_relation_seen"] = False
        relation["active_sales_order_to_contact_email_bill_to_relation_seen"] = False
        relation["contact_email_to_individual_party_relation_seen"] = True
        relation["active_contact_email_to_individual_party_relation_seen"] = False
        summary = MODULE.render(bad)
        self.assertIn("No observada (no equivale a ausencia)", summary)
        self.assertIn("Visible; ACTIVE no confirmado", summary)
        self.assertNotIn("aprobado automáticamente", summary)

    def test_mapping_http_status_is_bounded_and_never_authorizes(self):
        candidate = evidence()
        objs = candidate["catalog"]["object_mappings"] = {
            "status": "fixed_dmo_mapping_metadata_review_only",
            "objects": {
                "Individual": {
                    "state": "unverified_http_403",
                    "http_status_code": 403,
                    "visible_mapping_count": None,
                    "visible_active_mapping_count": None,
                },
                "SalesOrder": {
                    "state": "unverified_http_404",
                    "http_status_code": 404,
                    "visible_mapping_count": None,
                    "visible_active_mapping_count": None,
                },
            },
            "field_mapping_activation_approved": False,
            "dlo_dmo_mapping_save_authorized": False,
            "identity_stitching_authorized": False,
            "schema_upload_authorized": False,
            "data_360_ingestion_authorized": False,
            "marketing_sends_authorized": False,
        }
        actual = MODULE.render(candidate)
        self.assertIn("GET 403: acceso no autorizado", actual)
        self.assertIn("GET 404: mapeo no encontrado o recurso no expuesto", actual)
        self.assertNotIn("mapeo aprobado", actual)
        self.assertIn("NO aprobación", actual)
        objs["objects"]["Individual"]["state"] = "secret@invalid.local"
        actual = MODULE.render(candidate)
        self.assertNotIn("secret@invalid.local", actual)
        self.assertIn("Sin verificar", actual)

    def test_pst_stream_filtered_empty_never_becomes_global_absence(self):
        candidate = evidence()
        candidate["catalog"]["pst_stream_presence"] = {
            "status": "pst_filtered_stream_metadata_review_only",
            "state": "filtered_page_empty_review_only",
            "first_page_count": 0,
            "full_tenant_stream_inventory_certified": False,
            "pst_stream_absence_certified": False,
            "pst_stream_schema_approved": False,
            "data_stream_creation_authorized": False,
            "dlo_dmo_mapping_save_authorized": False,
            "data_360_ingestion_authorized": False,
            "marketing_sends_authorized": False,
            "label": "private@example.invalid",
        }
        rendered = MODULE.render(candidate)
        self.assertIn("GET 200: primera página vacía", rendered)
        self.assertIn("No |", rendered)
        self.assertNotIn("private@example.invalid", rendered)
        candidate["catalog"]["pst_stream_presence"]["pst_stream_absence_certified"] = True
        rendered = MODULE.render(candidate)
        self.assertIn("Sin verificar", rendered)
        self.assertNotIn("primera página vacía", rendered)

    def test_pst_stream_http_denied_and_untrusted_counts(self):
        candidate = evidence()
        pst = candidate["catalog"]["pst_stream_presence"] = {
            "status": "pst_filtered_stream_metadata_review_only",
            "state": "unverified_http_403",
            "first_page_count": "private@example.invalid",
            "full_tenant_stream_inventory_certified": False,
            "pst_stream_absence_certified": False,
            "pst_stream_schema_approved": False,
            "data_stream_creation_authorized": False,
            "dlo_dmo_mapping_save_authorized": False,
            "data_360_ingestion_authorized": False,
            "marketing_sends_authorized": False,
        }
        rendered = MODULE.render(candidate)
        self.assertIn("GET 403: acceso no autorizado", rendered)
        self.assertNotIn("private@example.invalid", rendered)
        pst["state"] = "unverified_http_404"
        rendered = MODULE.render(candidate)
        self.assertIn("GET 404: recurso no encontrado", rendered)
        pst["state"] = "private@example.invalid"
        rendered = MODULE.render(candidate)
        self.assertNotIn("private@example.invalid", rendered)

    def test_source_is_read_only_no_external_calls(self):
        source = PATH.read_text(encoding="utf-8")
        for forbidden in (
            "requests.", "urllib.request", "subprocess.", "socket.",
            "https://", "Authorization:", "Bearer ", "curl_",
            "file_put_contents", "api/v1/ingest",
        ):
            self.assertNotIn(forbidden, source, forbidden)


if __name__ == "__main__":
    unittest.main()
