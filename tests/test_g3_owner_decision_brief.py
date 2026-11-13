"""Negative tests: the G3 owner brief is not an approval or activation."""
import copy
import importlib.util
import unittest
from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/ci/verify-g3-owner-decision-brief.py"
spec = importlib.util.spec_from_file_location("owner_brief", SCRIPT)
briefcheck = importlib.util.module_from_spec(spec)
spec.loader.exec_module(briefcheck)


class G3OwnerBriefTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.brief = (ROOT / "docs/G3_OWNER_DECISION_READY.md").read_text("utf-8")
        cls.freeze = json.loads((ROOT / "config/g3-pst-review-fingerprint-freeze.json").read_text("utf-8"))
        cls.packet = json.loads((ROOT / "config/g3-pst-schema-review.json").read_text("utf-8"))

    def reject(self, mutate):
        brief = self.brief
        freeze = copy.deepcopy(self.freeze)
        packet = copy.deepcopy(self.packet)
        new_brief = mutate(brief, freeze, packet)
        if new_brief is not None:
            brief = new_brief
        self.assertNotEqual(briefcheck.validate_brief(brief, freeze, packet), [])

    def test_current_brief_is_exact_and_unsigned(self):
        self.assertEqual(briefcheck.validate_repo(ROOT), [])

    def test_missing_gate_row_fails(self):
        self.reject(lambda t, f, p: t.replace("**G3-07 · Privacidad y conservación**", "**X · Privacidad y conservación**"))

    def test_duplicate_gate_row_fails(self):
        self.reject(lambda t, f, p: t.replace("**G3-07 · Privacidad y conservación**", "**G3-06 · Privacidad y conservación**"))

    def test_owner_signature_must_not_be_presumed(self):
        def mutate(t, f, p):
            f["owner_signoff"] = "approved"
        self.reject(mutate)

    def test_recorded_schema_cannot_be_selected_early(self):
        def mutate(t, f, p):
            f["schema_to_publish"] = "v0.3"
        self.reject(mutate)

    def test_data_ingest_and_marketing_remain_blocked(self):
        for flag in briefcheck.BLOCKED_FLAGS:
            with self.subTest(flag=flag):
                def mutate(t, f, p, name=flag):
                    f[name] = True
                self.reject(mutate)

    def test_no_premature_owner_decision(self):
        def mutate(t, f, p):
            p["open_decisions"][3]["state"] = "approved"
        self.reject(mutate)

    def test_owner_brief_must_match_real_fingerprints(self):
        self.reject(lambda t, f, p: t.replace(self.freeze["artifacts"]["schemas/ABH_PST_SCHEMA_REVIEW.yaml"]["sha256"], "0" * 64))
        def mutate(t, f, p):
            f["artifacts"]["schemas/ABH_PST_SCHEMA_UTC_CANDIDATE.yaml"]["sha256"] = "1" * 64
        self.reject(mutate)

    def test_must_not_remove_purpose_or_privacy_guard(self):
        self.reject(lambda t, f, p: t.replace("No existe cifra de días aprobada", "Se aprueban 365 días"))

    def test_wrong_provider_is_rejected(self):
        self.reject(lambda t, f, p: t.replace("Marketing Cloud **Growth/Next + Data 360**", "Marketing Cloud **Engagement**"))


if __name__ == "__main__":
    unittest.main()
