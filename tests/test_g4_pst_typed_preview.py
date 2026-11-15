"""G4 typed PST offline review artifact: owner acceptance, no network/writes."""
import copy
import hashlib
import importlib.util
import json
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
