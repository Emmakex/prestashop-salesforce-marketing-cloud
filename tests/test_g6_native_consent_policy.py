"""Fail-closed checks for Growth native marketing consent policy."""
import copy
import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/ci/verify-g6-native-consent-policy.py"
SPEC = importlib.util.spec_from_file_location("g6_native_consent_policy", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class G6NativeConsentContract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.base = json.loads(
            (ROOT / "config/g6-growth-native-consent-policy.json")
            .read_text(encoding="utf8")
        )

    def test_current_strategy_locked(self):
        self.assertEqual(MODULE.errors(self.base), [])
        self.assertFalse(self.base["requirements"]["marketing_sends_authorized"])

    def test_never_promote_prestashop_flags(self):
        for field in (
            "prestashop_newsletter_grants_consent",
            "prestashop_partner_optin_grants_consent",
            "crm_flags_auto_sync_to_growth",
            "direct_consent_dmo_write_allowed",
            "direct_consent_dlo_write_allowed",
            "marketing_sends_authorized",
            "data_cloud_ingestion_authorized",
        ):
            with self.subTest(field=field):
                changed = copy.deepcopy(self.base)
                changed["requirements"][field] = True
                self.assertTrue(MODULE.errors(changed))

    def test_required_native_controls_cannot_be_disabled(self):
        for field in (
            "source_local_suppression_required", "purchase_recheck_required",
            "test_mode_only",
        ):
            with self.subTest(field=field):
                changed = copy.deepcopy(self.base)
                changed["requirements"][field] = False
                self.assertTrue(MODULE.errors(changed))

    def test_no_false_tenant_certification(self):
        for field in (
            "native_send_time_check_verified_in_tenant",
            "recipient_contact_point_association_verified",
            "subscription_business_scope_verified",
            "approved_existing_consent_evidence",
            "supported_consent_onboarding_completed",
        ):
            with self.subTest(field=field):
                changed = copy.deepcopy(self.base)
                changed["requirements"][field] = True
                self.assertTrue(MODULE.errors(changed))

    def test_subscription_and_contact_point_are_fixed(self):
        for field, value in (
            ("subscription_name", "Other"),
            ("channel", "SMS"),
            ("message_purpose", "Transactional"),
            ("consent_authority", "prestashop_optin"),
            ("check", "disable_consent"),
        ):
            with self.subTest(field=field):
                changed = copy.deepcopy(self.base)
                changed["target"][field] = value
                self.assertTrue(MODULE.errors(changed))

    def test_missing_and_expanded_fields_fail(self):
        missing = copy.deepcopy(self.base)
        del missing["requirements"]["marketing_sends_authorized"]
        self.assertTrue(MODULE.errors(missing))
        expanded = copy.deepcopy(self.base)
        expanded["custom_consent_override"] = True
        self.assertTrue(MODULE.errors(expanded))


if __name__ == "__main__":
    unittest.main()
