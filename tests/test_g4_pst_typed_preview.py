"""G4 typed PST offline review artifact: owner acceptance, no network/writes."""
import copy
import hashlib
import importlib.util
import json
import os
import subprocess
import unittest
from pathlib import Path
from unittest.mock import patch

import yaml

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "g4_pst_typed", ROOT / "scripts/ci/build-g4-pst-typed-preview.py"
)
g4 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(g4)


class G4TypedPstPreviewTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.baseline = (ROOT / g4.BASE_PATH).read_bytes()
        cls.typed = (ROOT / g4.TYPED_PATH).read_bytes()
        cls.acceptance = json.loads((ROOT / g4.ACCEPTANCE_PATH).read_text("utf-8"))

    def test_preview_is_actual_flat_v04_readiness_artifact(self):
        data, manifest = g4.build_preview(
            self.baseline, self.typed, self.acceptance
        )
        obj = yaml.safe_load(data)
        self.assertEqual(obj["openapi"], "3.0.3")
        self.assertEqual(obj["paths"], {})
        self.assertEqual(set(obj["components"]["schemas"]), {"PSCustomer", "PSOrder"})
        fields = obj["components"]["schemas"]
        self.assertEqual(sum(len(fields[k]["properties"]) for k in fields), 22)
        self.assertEqual(manifest["source_sha256"], g4.TYPED_SHA)
        self.assertEqual(hashlib.sha256(data).hexdigest(), manifest["preview_sha256"])
        self.assertTrue(manifest["owner_acceptance_recorded"])
        for f in ("legal_and_tenant_signoff_inferred", "schema_upload_authorized",
                  "data_cloud_ingestion_authorized", "marketing_sends_authorized",
                  "production_mode_authorized"):
            self.assertIs(manifest[f], False)
        self.assertNotIn("PSCart", fields)
        self.assertNotIn("synthetic@fixture.example.invalid", data.decode())
        for schema in fields.values():
            for field in schema["properties"].values():
                self.assertLessEqual(set(field), {"type", "format"})
        self.assertEqual(
            obj["components"]["schemas"]["PSOrder"]["properties"]["customer_source_key"],
            {"type": "string"},
        )

    def test_php_typed_payload_matches_upload_shape_for_each_ps_family(self):
        generated, _ = g4.build_preview(self.baseline, self.typed, self.acceptance)
        fixtures = []
        for version in ("1.7.8.11", "8.2.8", "9.1.5"):
            env = dict(os.environ, G4_PST_FIXTURE_PS_VERSION=version)
            raw = subprocess.check_output(
                ["php", str(ROOT / "tests/g4-pst-typed-payload-fixture.php")],
                cwd=str(ROOT), env=env,
            )
            fixture = json.loads(raw)
            self.assertTrue(g4.validate_synthetic_payload(generated, fixture))
            fixtures.append(fixture)
        self.assertEqual(fixtures[0], fixtures[1])
        self.assertEqual(fixtures[1], fixtures[2])

    def test_payload_parity_rejects_old_key_cross_shop_and_schema_drift(self):
        generated, _ = g4.build_preview(self.baseline, self.typed, self.acceptance)
        raw = subprocess.check_output(
            ["php", str(ROOT / "tests/g4-pst-typed-payload-fixture.php")],
            cwd=str(ROOT),
        )
        fixture = json.loads(raw)
        bad_cases = [
            lambda f: f["PSCustomer"]["data"][0].update(source_key="growth_ci_abhtwo:ps_2_44"),
            lambda f: f["PSOrder"]["data"][0].update(customer_source_key="growth_ci_abhtwo:3:customer:44"),
            lambda f: f["PSOrder"]["data"][0].update(paid_flag=True),
            lambda f: f["PSCustomer"]["data"][0].update(email="PRIVATE_EMAIL"),
            lambda f: f["PSOrder"]["data"][0].update(shop_id="2"),
            lambda f: f["PSOrder"]["data"][0].update(marketing_consent=True),
            lambda f: f.update(PSCart={"data": []}),
        ]
        for mutation in bad_cases:
            with self.subTest(mutation=mutation):
                bad = copy.deepcopy(fixture)
                mutation(bad)
                with self.assertRaises(ValueError):
                    g4.validate_synthetic_payload(generated, bad)

    def test_acceptance_is_recorded_without_forged_certification(self):
        self.assertEqual(
            self.acceptance["owner_accepts_g3_decisions"],
            {key: True for key in g4.OWNER_KEYS},
        )
        for bad in (
            lambda x: x["owner_accepts_g3_decisions"].update(data_privacy_direction=False),
            lambda x: x["limits"].update(schema_upload_authorized=True),
            lambda x: x["limits"].update(third_party_signatures_inferred=True),
            lambda x: x.update(schema_source_sha256="0" * 64),
            lambda x: x.update(schema_prepared_for_preview="v0.2"),
            lambda x: x.update(evidence_kind="signed_by_architect"),
        ):
            with self.subTest(bad=bad):
                tampered = copy.deepcopy(self.acceptance)
                bad(tampered)
                with self.assertRaisesRegex(ValueError, "owner_acceptance_or_safety"):
                    g4.build_preview(self.baseline, self.typed, tampered)

    def test_schema_modification_cannot_be_rehashed_in_place(self):
        for b, t in (
            (self.baseline + b"\n", self.typed),
            (self.baseline, self.typed + b"\n"),
        ):
            with self.assertRaisesRegex(ValueError, "source_schema_hash"):
                g4.build_preview(b, t, self.acceptance)

    def test_even_with_hash_adjustment_added_field_and_type_are_rejected(self):
        for kind in ("new_object", "type_drift", "required_drift", "key_pattern"):
            obj = yaml.safe_load(self.typed)
            schemas = obj["components"]["schemas"]
            if kind == "new_object":
                schemas["PSCart"] = copy.deepcopy(schemas["PSOrder"])
            elif kind == "type_drift":
                schemas["PSCustomer"]["properties"]["email"]["type"] = "number"
            elif kind == "required_drift":
                schemas["PSOrder"]["required"] = ["source_key"]
            else:
                schemas["PSOrder"]["properties"]["source_key"]["pattern"] = "^.*$"
            changed = yaml.safe_dump(obj, sort_keys=False).encode()
            with patch.object(g4, "TYPED_SHA", hashlib.sha256(changed).hexdigest()):
                # Changing the source hash in a fake owner's record is not sufficient
                # to assert a valid artifact; structural and typed-key gates persist.
                owner = copy.deepcopy(self.acceptance)
                owner["schema_source_sha256"] = g4.TYPED_SHA
                with self.assertRaises(ValueError):
                    g4.build_preview(self.baseline, changed, owner)


if __name__ == "__main__":
    unittest.main()
