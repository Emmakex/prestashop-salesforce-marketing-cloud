"""G3-02/G3-07 synthetic negative tests for exact 24-field review scope."""
import copy
import importlib.util
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
src = ROOT / "scripts/ci/verify-g3-field-purpose-owner-decisions.py"
spec = importlib.util.spec_from_file_location("g3_field_review", src)
check = importlib.util.module_from_spec(spec)
spec.loader.exec_module(check)


class G3FieldReviewTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.register = json.loads((ROOT / check.REGISTER).read_text("utf-8"))
        cls.inventory = json.loads((ROOT / check.INVENTORY).read_text("utf-8"))

    def reject(self, mutator):
        proposed = copy.deepcopy(self.register)
        source = copy.deepcopy(self.inventory)
        mutator(proposed, source)
        self.assertTrue(check.validate_review(proposed, source))

    def test_pending_24_fields_zero_exports_and_sends(self):
        self.assertEqual(check.validate_repository(ROOT), [])
        rows = self.register["field_decisions"]
        self.assertEqual(sum(len(v) for v in rows.values()), 24)
        for row in rows["PSCustomer"] + rows["PSOrder"]:
            self.assertIsNone(row["approved_processing_purpose"])
            self.assertFalse(row["export_to_data_360_authorized"])
            self.assertFalse(row["growth_send_authorized"])

    def test_cannot_pretend_legal_approval(self):
        for setting, value in (
            ("purpose_signoff", "approved"), ("retention_signoff", "approved"),
            ("state", "approved"), ("schema_selection", "v0.3"),
            ("data_cloud_schema_upload_authorized", True),
            ("data_cloud_ingestion_authorized", True),
            ("marketing_sends_authorized", True),
        ):
            with self.subTest(setting=setting):
                self.reject(lambda p, i, k=setting, v=value: p.__setitem__(k, v))

    def test_cannot_approve_individual_field_without_owner(self):
        for key, value in (
            ("decision", "approved"), ("approved_processing_purpose", "marketing"),
            ("lawful_basis_verified", True), ("retention_approved", True),
            ("export_to_data_360_authorized", True), ("growth_send_authorized", True),
        ):
            with self.subTest(field=key):
                self.reject(lambda p, i, k=key, v=value:
                    p["field_decisions"]["PSCustomer"][4].__setitem__(k, v))

    def test_cannot_reclassify_sensitive_fields(self):
        cases = [
            ("PSCustomer", "email", "consider_identity_only"),
            ("PSCustomer", "first_name", "consider_identity_only"),
            ("PSCustomer", "last_name", "consider_identity_only"),
            ("PSCustomer", "newsletter_flag", "withhold_pending_purpose"),
            ("PSCustomer", "partner_optin_flag", "withhold_pending_purpose"),
            ("PSCustomer", "source_updated_local", "review_business_necessity"),
            ("PSOrder", "source_updated_local", "review_business_necessity"),
            ("PSOrder", "source_updated_utc", "consider_identity_only"),
            ("PSOrder", "cart_source_key", "consider_identity_only"),
        ]
        for obj, field, proposal in cases:
            with self.subTest(obj=obj, field=field):
                def mutate(p, i):
                    row = next(x for x in p["field_decisions"][obj] if x["name"] == field)
                    row["minimization_proposal"] = proposal
                self.reject(mutate)

    def test_cannot_change_scope_or_hide_field(self):
        self.reject(lambda p, i: p["field_decisions"]["PSOrder"].pop())
        self.reject(lambda p, i: p["field_decisions"]["PSCustomer"].reverse())
        self.reject(lambda p, i: p["field_decisions"]["PSOrder"][0].update(
            name="unreviewed_phone"
        ))
        self.reject(lambda p, i: i["field_inventory"]["PSCustomer"][0].update(
            purpose="marketing_send"
        ))
        self.reject(lambda p, i: p.update(field_decisions={"PSCustomer": []}))
        self.reject(lambda p, i: p.update(new_permission=True))

    def test_requires_evidence_and_no_sample_personal_data(self):
        self.reject(lambda p, i: p["field_decisions"]["PSCustomer"][4].update(
            review_evidence_required=""
        ))
        self.reject(lambda p, i: p["field_decisions"]["PSCustomer"][4].update(
            review_evidence_required="Contact sample: real.customer@example.com"
        ))

    def test_no_external_io_or_data_processing_in_verifier(self):
        content = src.read_text("utf-8")
        for term in ("requests.", "urllib.request", "curl_", "subprocess.",
                     "salesforce.com/services", "https://"):
            self.assertNotIn(term, content)


if __name__ == "__main__":
    unittest.main()
