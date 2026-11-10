# Deployed seven-day forecast replay — 6 November 2026

This run evaluates every eligible Sunday origin whose following seven days are fully inside the validation or test period. It calls the unchanged `forecast_weekly()` procedure from `analytics/product_forecast.py` on the historical prefix available at each origin. That procedure retrains on completed weeks and applies its existing correction from earlier residuals. `predictions.csv` contains 168 actual-versus-predicted observations; `summary.json` records the data hash, exact dates, model parameters, dependency versions, and per-product scores. The previous-week total is a benchmark, not a deployed model.

The input is the synthetic `database/packaged-data-next/daily_product_demand.csv`, SHA-256 `a0f72d8319b1214aecbee16e4fbe4eef41deee0359f3c7c215233c2043ef9f9b`, covering 1 January 2024 through 5 November 2026. Validation targets end by 30 June 2026; test targets run from July through 5 November 2026. The CSV was not replaced or regenerated.

| Product | Validation RF MAE | Validation benchmark MAE | Test RF MAE | Test benchmark MAE |
|---|---:|---:|---:|---:|
| 500 mL | 28.393 | 38.280 | 20.102 | 26.059 |
| 1 L | 19.627 | 22.840 | 22.063 | 25.882 |
| 5 L | 14.987 | 19.280 | 8.263 | 12.412 |
| 20 L | 9.890 | 15.120 | 9.363 | 15.353 |

The deployed weekly procedure beats the previous-week benchmark on MAE for all four products in these synthetic validation and test periods. This does not establish performance at a real producer. The earlier fixed-cutoff notebook comparison is a different experiment and its scores must not be described as deployed-procedure scores. Some later dates had been examined during earlier project work, so this is a chronological replay rather than an untouched external final test. Repeat both experiments if the dataset changes, and evaluate on genuine future orders before claiming operational accuracy.
