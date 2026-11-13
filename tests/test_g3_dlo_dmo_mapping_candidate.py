"""G3-05/06 negative tests for DLO/DMO owner review and schema drift."""
import copy
import importlib.util
import json
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "scripts/ci/verify-g3-dlo-dmo-mapping-candidate.py"
spec = importlib.util.spec_from_file_location("g3_model_review", SOURCE)
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)


class G3MappingReviewTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.mapping = json.loads((ROOT / audit.CANDIDATE).read_text("utf-8"))
        cls.inventory = json.loads((ROOT / audit.REVIEW).read_text("utf-8"))
        cls.schemas = {
            "v0.2": yaml.safe_load((ROOT / audit.SCHEMA_02).read_text("utf-8")),
            "v0.3": yaml.safe_load((ROOT / audit.SCHEMA_03).read_text("utf-8")),
        }

    def reject(self, mutator):
        manifest = copy.deepcopy(self.mapping)
        inventory = copy.deepcopy(self.inventory)
        schemas = copy.deepcopy(self.schemas)
        mutator(manifest, inventory, schemas)
        self.assertTrue(audit.validate(manifest, inventory, schemas))

    def test_unapproved_24_fields_and_sources(self):
        self.assertEqual(audit.validate_files(ROOT), [])
        self.assertEqual(sum(len(x["field_dispositions"]) for x in
                             self.mapping["objects"].values()), 24)
        self.assertIsNone(self.mapping["relation_proposal"]["selected_dmo_target_field"])
        self.assertFalse(self.mapping["identity_resolution_authorized"])

    def test_no_premature_approval_of_tenant_or_remote_mapping(self):
        for key, value in (
            ("owner_signoff", "approved"),
            ("data_space_approved", True),
            ("tenant_dmo_catalog_verified", True),
            ("dlo_dmo_mapping_save_authorized", True),
            ("identity_resolution_authorized", True),
            ("schema_version_selected", "v0.3"),
            ("data_360_ingestion_authorized", True),
            ("growth_marketing_sends_authorized", True),
        ):
            with self.subTest(key=key):
                self.reject(lambda m, i, s, k=key, v=value: m.__setitem__(k, v))

    def test_no_mislabeled_dmo_or_unverified_pk(self):
        for obj in ("PSCustomer", "PSOrder"):
            for key, value in (
                ("dmo_candidate", "ssot__Other__dlm"),
                ("dlo_primary_key_proposal", "email"),
                ("dmo_primary_key_candidate", "ssot__ExternalRecordId__c"),
                ("category_approved", True),
                ("mapping_approved", True),
                ("dmo_primary_key_mapping_verified_in_tenant", True),
            ):
                with self.subTest(object=obj, key=key):
                    self.reject(
                        lambda m, i, s, o=obj, k=key, v=value:
                        m["objects"][o].__setitem__(k, v)
                    )

    def test_no_auto_identity_join_cart_or_email(self):
        for key, value in (
            ("selected_dmo_target_field", "ssot__SoldToCustomerId__c"),
            ("dmo_relationship_verified_in_tenant", True),
            ("email_dedupe_authorized", True),
            ("cart_stream_relationship_authorized", True),
            ("automatic_identity_stitching_authorized", True),
            ("cross_scope_action", "join_by_email"),
            ("missing_parent_action", "create_placeholder_person"),
        ):
            with self.subTest(key=key):
                self.reject(
                    lambda m, i, s, k=key, v=value:
                    m["relation_proposal"].__setitem__(k, v)
                )

    def test_every_field_has_a_decision_and_schema_drift_fails(self):
        self.reject(lambda m, i, s:
            m["objects"]["PSOrder"]["field_dispositions"].pop("cart_source_key"))
        self.reject(lambda m, i, s:
            m["objects"]["PSCustomer"]["field_dispositions"].update(
                email="mapped_to_contact_point_email"
            ))
        self.reject(lambda m, i, s:
            s["v0.3"]["components"]["schemas"]["PSOrder"]["properties"].pop(
                "source_updated_utc"
            ))
        self.reject(lambda m, i, s:
            s["v0.2"]["components"]["schemas"]["PSCustomer"]["required"].remove(
                "source_key"
            ))
        self.reject(lambda m, i, s:
            m.update(transport_credentials="forbidden"))
        self.reject(lambda m, i, s:
            i.update(schema_to_publish="v0.3"))


if __name__ == "__main__":
    unittest.main()
