import math
import unittest

from product_forecast import METHODS, forecast_horizon
from analyze import analyze


class ProductForecastTests(unittest.TestCase):
    def test_both_ml_models_are_compared_with_a_moving_average_baseline(self):
        daily_cases = [16 + day % 7 + 3 * math.sin(day / 25) for day in range(610)]
        for horizon in (7, 30):
            result = forecast_horizon(daily_cases, horizon)
            self.assertTrue(result["available"])
            self.assertEqual(set(result["methods"]), set(METHODS))
            self.assertEqual(set(METHODS), {"moving_average", "random_forest", "gradient_boosting"})
            self.assertGreaterEqual(result["methods"]["moving_average"]["backtest_points"], 12)
            self.assertGreaterEqual(result["methods"]["random_forest"]["backtest_points"], 12)
            self.assertGreaterEqual(result["methods"]["gradient_boosting"]["backtest_points"], 12)
            self.assertGreaterEqual(result["methods"][result["best_method"]]["predicted_cases"], 0)

    def test_insufficient_history_does_not_claim_a_best_model(self):
        result = forecast_horizon([10.0] * 120, 30)
        self.assertFalse(result["available"])

    def test_aggregate_forecast_only_uses_moving_average(self):
        result = analyze([10.0, 20.0, 30.0, 40.0])
        self.assertEqual(result["best_method"], "moving_average")
        self.assertEqual(result["forecast_quantity"], 30.0)
        self.assertEqual(result["moving_average"]["mae"], 15.0)
        self.assertEqual(set(result), {"moving_average", "best_method", "forecast_quantity"})


if __name__ == "__main__":
    unittest.main()
