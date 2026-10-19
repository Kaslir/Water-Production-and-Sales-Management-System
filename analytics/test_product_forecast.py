import math
import unittest

from product_forecast import METHODS, forecast_horizon


class ProductForecastTests(unittest.TestCase):
    def test_both_ml_models_are_compared_with_chronological_baselines(self):
        daily_cases = [16 + day % 7 + 3 * math.sin(day / 25) for day in range(610)]
        for horizon in (7, 30):
            result = forecast_horizon(daily_cases, horizon)
            self.assertTrue(result["available"])
            self.assertEqual(set(result["methods"]), set(METHODS))
            self.assertGreaterEqual(result["methods"]["random_forest"]["backtest_points"], 12)
            self.assertGreaterEqual(result["methods"]["gradient_boosting"]["backtest_points"], 12)
            self.assertGreaterEqual(result["methods"][result["best_method"]]["predicted_cases"], 0)

    def test_insufficient_history_does_not_claim_a_best_model(self):
        result = forecast_horizon([10.0] * 120, 30)
        self.assertFalse(result["available"])


if __name__ == "__main__":
    unittest.main()
