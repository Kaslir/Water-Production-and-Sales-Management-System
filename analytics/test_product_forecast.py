import math
import unittest
from datetime import date

from product_forecast import METHOD, forecast_horizon, forecast_weekly


class ProductForecastTests(unittest.TestCase):
    def test_thirty_day_forecast_uses_only_random_forest(self):
        daily_cases = [16 + day % 7 + 3 * math.sin(day / 25) for day in range(610)]
        result = forecast_horizon(daily_cases, 30)
        self.assertTrue(result["available"])
        self.assertEqual(result["best_method"], METHOD)
        self.assertEqual(set(result["methods"]), {"random_forest"})
        self.assertGreaterEqual(result["methods"][METHOD]["backtest_points"], 12)
        self.assertGreaterEqual(result["methods"][METHOD]["predicted_cases"], 0)

    def test_insufficient_history_does_not_claim_a_best_model(self):
        result = forecast_horizon([10.0] * 120, 30)
        self.assertFalse(result["available"])

    def test_weekly_forecast_uses_sunday_origin_and_random_forest(self):
        values = [16 + day % 7 + 3 * math.sin(day / 25) for day in range(1001)]
        result = forecast_weekly(values, date(2024, 1, 1))
        self.assertTrue(result["available"])
        self.assertEqual(result["week_start"], "2026-09-28")
        self.assertEqual(result["week_end"], "2026-10-04")
        self.assertEqual(result["best_method"], METHOD)
        self.assertEqual(set(result["methods"]), {"random_forest"})
        self.assertEqual(result["methods"][result["best_method"]]["backtest_points"], 25)
        self.assertGreaterEqual(result["methods"][result["best_method"]]["predicted_cases"], 0)

    def test_weekly_forecast_rejects_partial_week(self):
        result = forecast_weekly([10.0] * 1004, date(2024, 1, 1))
        self.assertFalse(result["available"])

if __name__ == "__main__":
    unittest.main()
