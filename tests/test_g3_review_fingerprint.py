"""G3 negative tests for proposed, unsigned schema review snapshot only."""
import copy
import hashlib
import importlib.util
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/ci/verify-g3-review-fingerprint.py"
spec = importlib.util.spec_from_file_location("g3_review_fingerprint", SCRIPT)
fingerprint = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fingerprint)


class G3ReviewFingerprintTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.snapshot, cls.files = fingerprint.read_repository(ROOT)

    def reject(self, modifier):
        freeze = copy.deepcopy(self.snapshot)
        files = dict(self.files)
        modifier(freeze, files)
        self.assertNotEqual(fingerprint.validate_snapshot(freeze, files), [])

    def test_exact_proposal_snapshot_has_no_upload_approval(self):
        self.assertEqual(fingerprint.validate_repository(ROOT), [])
        self.assertTrue(self.snapshot["review_only"])
        self.assertEqual(self.snapshot["schema_to_publish"], "undecided")

    def test_changes_to_each_draft_or_review_packet_fail_closed(self):
        for path in fingerprint.SOURCES:
            with self.subTest(path=path):
                self.reject(lambda f, d, p=path: d.__setitem__(p, d[p] + b"\nchanged"))

    def test_rehashing_a_rogue_field_does_not_bypass_schema_contract(self):
        def mutate(f, d):
            path = "schemas/ABH_PST_SCHEMA_UTC_CANDIDATE.yaml"
            d[path] += b"\n# extra review data\n"
            f["artifacts"][path]["sha256"] = hashlib.sha256(d[path]).hexdigest()
            # Text-only drift must be part of a new reviewed fingerprint.
            # Real field-scope alteration is independently validated below.
            obj = "config/g3-pst-schema-review.json"
            packet = json.loads(d[obj])
            packet["field_inventory"]["PSCustomer"].append({
                "name": "unreviewed_personal_field",
                "purpose": "sending",
                "sensitivity": "direct_personal_data",
                "review_note": "not reviewed",
            })
            d[obj] = json.dumps(packet, ensure_ascii=False).encode()
            f["artifacts"][obj]["sha256"] = hashlib.sha256(d[obj]).hexdigest()
        self.reject(mutate)

    def test_owner_signature_cannot_be_invented(self):
        self.reject(lambda f, d: f.__setitem__("owner_signoff", "approved"))
        self.reject(lambda f, d: f.__setitem__("approved_by", "fake-admin"))
        self.reject(lambda f, d: f.__setitem__("approved_at", "2026-11-13"))

    def test_upload_ingestion_and_sends_remain_forbidden(self):
        for field in ("data_360_upload_authorized", "data_360_ingestion_authorized",
                      "growth_marketing_sends_authorized"):
            with self.subTest(field=field):
                self.reject(lambda f, d, k=field: f.__setitem__(k, True))

    def test_cannot_change_product_or_choose_published_schema(self):
        self.reject(lambda f, d: f.__setitem__("target_product", "marketing_cloud_engagement"))
        self.reject(lambda f, d: f.__setitem__("schema_to_publish", "v0.3"))
        self.reject(lambda f, d: f.__setitem__("review_only", False))

    def test_digest_field_and_file_scope_are_strict(self):
        key = "schemas/ABH_PST_SCHEMA_REVIEW.yaml"
        self.reject(lambda f, d: f["artifacts"][key].__setitem__("sha256", "bad"))
        self.reject(lambda f, d: f["artifacts"][key].__setitem__("field_count", 24))
        self.reject(lambda f, d: f["artifacts"].pop(key))
        self.reject(lambda f, d: f["artifacts"].__setitem__("../secrets", {}))
        self.reject(lambda f, d: d.pop(key))

    def test_eight_decisions_must_stay_open_and_ordered(self):
        self.reject(lambda f, d: f["open_decision_ids"].pop())
        self.reject(lambda f, d: f["open_decision_ids"].reverse())

    def test_file_content_is_never_sent_to_external_api(self):
        source = SCRIPT.read_text("utf-8")
        for banned in ("requests.", "urllib.request", "curl_", "salesforce.com/services",
                       "subprocess.", "os.system", "https://"):
            self.assertNotIn(banned, source)


if __name__ == "__main__":
    unittest.main()
