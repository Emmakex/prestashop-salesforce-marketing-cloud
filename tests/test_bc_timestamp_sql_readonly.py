"""Negative contract tests for the BC G3 read-only timestamp audit."""
import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "bc_g3_timestamp_audit", ROOT / "scripts/ci/verify-bc-timestamp-sql-readonly.py"
)
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)


class BCReadOnlyTimestampSqlTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sql = guard.QUERY.read_text(encoding="utf-8")

    def test_read_only_sanitized_shop_scoped_query_passes(self):
        self.assertEqual(guard.validate(self.sql), [])

    def reject(self, sql):
        self.assertNotEqual(guard.validate(sql), [])

    def test_sql_mutations_cannot_write_or_export(self):
        for extra in ("UPDATE bc_customer SET date_upd = NOW();",
                      "SELECT date_upd INTO OUTFILE '/tmp/dates';",
                      "DELETE FROM bc_orders;",
                      "CREATE TABLE backup_dates AS SELECT * FROM bc_orders;"):
            with self.subTest(sql=extra):
                self.reject(self.sql + "\n" + extra)

    def test_pii_or_ungrouped_export_is_rejected(self):
        self.reject(self.sql.replace("COUNT(*) AS total_shop_1_rows",
                                     "email, COUNT(*) AS total_shop_1_rows"))
        self.reject(self.sql.replace("COUNT(*) AS total_shop_1_rows",
                                     "id_customer, COUNT(*) AS total_shop_1_rows"))
        self.reject(self.sql + "\nSELECT * FROM bc_customer;")

    def test_both_sources_must_be_shop_scoped(self):
        self.reject(self.sql.replace("WHERE id_shop = 1", "WHERE id_shop > 0", 1))

    def test_config_cannot_expose_unapproved_value(self):
        self.reject(self.sql.replace("ELSE 'OTHER_ZONE_REVIEW'",
                                     "ELSE c.value"))

    def test_clocks_and_dst_windows_must_be_counted(self):
        self.reject(self.sql.replace(" AS spring_gap_2025_2026", " AS arbitrary_count"))
        self.reject(self.sql.replace("AS updated_future_utc_15min", "AS missing_utc"))

    def test_data_writes_are_not_exempt_by_comments(self):
        self.assertEqual(guard.validate("-- UPDATE would be forbidden in real SQL\n" + self.sql), [])


if __name__ == "__main__":
    unittest.main()
