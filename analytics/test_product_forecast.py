import math
import unittest
from datetime import date

from product_forecast import METHODS, forecast_horizon, forecast_weekly


class ProductForecastTests(unittest.TestCase):
    def test_both_ml_models_are_compared(self):
        daily_cases = [16 + day % 7 + 3 * math.sin(day / 25) for day in range(610)]
        for horizon in (7, 30):
            result = forecast_horizon(daily_cases, horizon)
            self.assertTrue(result["available"])
            self.assertEqual(set(result["methods"]), set(METHODS))
            self.assertEqual(set(METHODS), {"random_forest", "gradient_boosting"})
            self.assertGreaterEqual(result["methods"]["random_forest"]["backtest_points"], 12)
            self.assertGreaterEqual(result["methods"]["gradient_boosting"]["backtest_points"], 12)
            self.assertGreaterEqual(result["methods"][result["best_method"]]["predicted_cases"], 0)

    def test_insufficient_history_does_not_claim_a_best_model(self):
        result = forecast_horizon([10.0] * 120, 30)
        self.assertFalse(result["available"])

    def test_weekly_forecast_uses_sunday_origin_and_separate_error_scores(self):
        values = [16 + day % 7 + 3 * math.sin(day / 25) for day in range(1001)]
        result = forecast_weekly(values, date(2024, 1, 1))
        self.assertTrue(result["available"])
        self.assertEqual(result["week_start"], "2026-09-28")
        self.assertEqual(result["week_end"], "2026-10-04")
        self.assertEqual(set(result["methods"]), {"previous_week", *METHODS})
        self.assertEqual(result["methods"][result["best_method"]]["backtest_points"], 25)
        self.assertGreaterEqual(result["methods"][result["best_method"]]["predicted_cases"], 0)

    def test_weekly_forecast_rejects_partial_week(self):
        result = forecast_weekly([10.0] * 1004, date(2024, 1, 1))
        self.assertFalse(result["available"])

if __name__ == "__main__":
    unittest.main()
