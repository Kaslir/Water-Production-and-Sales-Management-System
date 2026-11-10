"""Focused tests of calendar, split and feature availability."""

import csv
import sys
import unittest
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from compare_product_demand import examples, load_data, TRAIN_END, VALID_END, TEST_END

ROOT = Path(__file__).resolve().parents[1]
DATA = ROOT / "database" / "packaged-data-next"


class SyntheticExperimentTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.dataset, _, _ = load_data(DATA)

    def test_calendar_and_split_boundaries(self):
        for dated in self.dataset.values():
            for horizon in (7, 30):
                rows = examples(dated, horizon)
                self.assertTrue(rows)
                self.assertTrue(all(r["origin"].weekday() == 6 for r in rows))
                self.assertTrue(all(r["target_end"] == r["origin"] + timedelta(days=horizon) for r in rows))
                self.assertTrue(all(r["target_end"] <= TEST_END for r in rows))
                train = [r for r in rows if r["target_end"] <= TRAIN_END]
                valid = [r for r in rows if TRAIN_END < r["origin"] and r["target_end"] <= VALID_END]
                test = [r for r in rows if VALID_END < r["origin"] and r["target_end"] <= TEST_END]
                self.assertGreaterEqual(min(len(train), len(valid), len(test)), 5)
                self.assertLessEqual(max(r["target_end"] for r in train), TRAIN_END)
                self.assertLessEqual(max(r["target_end"] for r in valid), VALID_END)
                self.assertGreater(min(r["origin"] for r in test), VALID_END)

    def test_future_changes_cannot_change_past_features(self):
        for dated in self.dataset.values():
            for horizon in (7, 30):
                original = examples(dated, horizon)
                origin = original[len(original) // 2]["origin"]
                modified = [(day, cases + (9999 if day > origin else 0), sku) for day, cases, sku in dated]
                changed = examples(modified, horizon)
                before = next(r for r in original if r["origin"] == origin)
                after = next(r for r in changed if r["origin"] == origin)
                self.assertEqual(before["features"], after["features"])
                self.assertEqual(before["baseline"], after["baseline"])
                self.assertNotEqual(before["actual"], after["actual"])

    def test_sunday_is_closed_but_operating_zero_is_distinct(self):
        with (DATA / "daily_product_demand.csv").open(newline="", encoding="utf-8") as stream:
            rows = list(csv.DictReader(stream))
        self.assertTrue(any(r["day_type"] == "operating_zero_orders" for r in rows))
        for row in rows:
            if date.fromisoformat(row["date"]).weekday() == 6:
                self.assertEqual(row["day_type"], "closed_sunday")
                self.assertEqual(int(row["cases_ordered"]), 0)

    def test_exception_examples_are_present_and_traceable(self):
        def read(name):
            with (DATA / f"{name}.csv").open(newline="", encoding="utf-8") as stream:
                return list(csv.DictReader(stream))
        batches = {r["batch_id"]: r for r in read("production_batches")}
        self.assertTrue({"quality_hold", "quarantined", "recalled"} <= {r["status"] for r in batches.values()})
        dispatches = {r["dispatch_id"]: r for r in read("dispatches")}
        attempts = read("delivery_attempts")
        self.assertTrue(any(r["attempt_status"] == "failed" for r in attempts))
        self.assertTrue(any(r["status"] == "dispatched" for r in dispatches.values()))
        self.assertTrue(all(batches[r["batch_id"]]["status"] == "released" for r in read("dispatch_lines")))
        self.assertTrue(read("batch_disposals"))


if __name__ == "__main__":
    unittest.main()
