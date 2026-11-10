# Chapter 5 notes: exception-aware synthetic experiment

This run supersedes local run `04` after adding rare failed delivery attempts and batch quality exceptions. The dataset remains entirely synthetic. It spans 1 January 2024 through 5 November 2026, with Monday–Saturday operations and closed Sundays. The generator created 32 fictional customers, four products, 8,698 orders, 1,173 batches, 887 one-truck trips, 8,656 dispatches and 8,655 invoices. The 34 failed delivery attempts include 33 successful retries and one unresolved final-day stop. Batch outcomes include quality hold, quarantine, recall and an expiry disposal. The same stock was never dispatched twice and no blocked batch was dispatched. All 19 checks in `validation_report.json` passed, including isolated relational loading and fixed-seed regeneration.

The experiment compares a previous-period demand baseline, Random Forest and Gradient Boosting with the same chronological origins and historical features. Training labels finish by December 2025, validation labels by June 2026, and later test labels by 5 November 2026. Validation MAE selects a method; the test does not reselect it. The next 7 days are Monday–Sunday calendar days, and the 30-day view spans 30 calendar days. See `config.json`, `preprocessing.json`, `metrics.csv`, `predictions.csv`, `models/` and `charts/` for the complete evidence.

| Horizon | Split | Baseline MAE | Random Forest MAE | Gradient Boosting MAE | Random Forest bias |
|---|---|---:|---:|---:|---:|
| 7 days | Validation | 23.880 | 18.560 | 19.968 | -11.878 |
| 7 days | Test | 19.926 | 15.465 | 16.081 | -7.569 |
| 30 days | Validation | 42.375 | 37.182 | 41.622 | -26.579 |
| 30 days | Test | 41.232 | 36.457 | 39.422 | -33.227 |

Values are cases, averaged over product-origin predictions. Random Forest has the lowest **overall validation MAE** for both horizons, and also the lowest overall test MAE in this run. It does not win every product-level comparison: the 5 L 7-day validation result favours Gradient Boosting, and the 500 mL 30-day validation result favours the baseline. Negative bias means underprediction and should be considered when making stock decisions.

These results do not establish accuracy at a real company. Some 2026 dates had been examined during older project work, so the test is chronological but not genuinely untouched external evidence. The experimental models use fixed training cutoffs, whereas AquaTrack's deployed weekly model retrains and corrects bias; these scores must not be presented as the deployed pipeline's measured accuracy. After the experiment, the synthetic dataset was imported into an isolated MySQL clone and the local XAMPP app was switched to it with the user's approval. The deployed Python model was not changed.
