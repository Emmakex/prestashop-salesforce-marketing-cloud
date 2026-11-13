"""Synthetic negative cases: G3 review metadata must never imply approval."""
import copy
import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/ci/verify-g3-schema-review.py"
spec = importlib.util.spec_from_file_location("g3_review", SCRIPT)
g3 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(g3)


class ReviewPacketTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.packet = g3.read_packet(ROOT)
        cls.v02 = g3.read_schema(ROOT, "ABH_PST_SCHEMA_REVIEW.yaml")
        cls.v03 = g3.read_schema(ROOT, "ABH_PST_SCHEMA_UTC_CANDIDATE.yaml")

    def reject(self, mutator):
        doc = copy.deepcopy(self.packet)
        v02 = copy.deepcopy(self.v02)
        v03 = copy.deepcopy(self.v03)
        mutator(doc, v02, v03)
        self.assertNotEqual(g3.validate_packet(doc, v02, v03), [])

    def test_all_eight_gates_remain_pending_and_fields_match_schemas(self):
        self.assertEqual(g3.validate_repository(ROOT), [])

    def test_growth_cannot_silently_become_engagement(self):
        self.reject(lambda p, a, b: p.update(target_product="marketing_cloud_engagement"))

    def test_cannot_mark_schema_approved(self):
        self.reject(lambda p, a, b: p.update(approval_state="approved"))

    def test_cannot_pick_upload_schema_without_signoff(self):
        self.reject(lambda p, a, b: p.update(schema_to_publish="v0.3"))

    def test_cannot_claim_schema_uploaded(self):
        self.reject(lambda p, a, b: p.update(uploaded_to_tenant=True))

    def test_cannot_enable_streams_ingestion_or_sending(self):
        for key in ("streams_approved", "real_data_ingestion_approved", "marketing_sends_approved"):
            with self.subTest(field=key):
                self.reject(lambda p, a, b: p.update({key: True}))

    def test_cannot_approve_timestamp_refresh_or_retention(self):
        for key in ("record_modified_field", "refresh_mode", "retention_policy"):
            with self.subTest(field=key):
                self.reject(lambda p, a, b: p["object_decisions"]["PSOrder"].update({key: "yes"}))

    def test_cannot_skip_g3_decision(self):
        self.reject(lambda p, a, b: p["open_decisions"].pop())

    def test_cannot_mislabel_email_personal_data(self):
        self.reject(lambda p, a, b: p["field_inventory"]["PSCustomer"][4].update(sensitivity="source_state"))

    def test_cannot_classify_newsletter_as_send_consent(self):
        self.reject(lambda p, a, b: p["field_inventory"]["PSCustomer"][7].update(purpose="send_authorized"))

    def test_cannot_use_local_date_for_ordering(self):
        self.reject(lambda p, a, b: p["field_inventory"]["PSOrder"][10].update(purpose="ordering_candidate"))

    def test_cannot_omit_field_from_manifest(self):
        self.reject(lambda p, a, b: p["field_inventory"]["PSOrder"].pop())

    def test_cannot_add_unreviewed_field_to_candidate_yaml(self):
        self.reject(lambda p, a, b: b["components"]["schemas"]["PSCustomer"]["properties"].update({
            "unreviewed_field": {"type": "string"}
        }))

    def test_utc_field_must_remain_candidate_datetime(self):
        self.reject(lambda p, a, b: b["components"]["schemas"]["PSOrder"]["properties"]["source_updated_utc"].update({
            "format": "email"
        }))

    def test_invalid_contract(self):
        self.assertTrue(g3.validate_packet(None, self.v02, self.v03))
        self.assertTrue(g3.validate_packet({}, self.v02, self.v03))


if __name__ == "__main__":
    unittest.main()
