"""Reproducible, chronological comparison of AquaTrack demand forecasts.

Run from the repository root with Python from analytics/requirements.txt.
The final test is evaluated once after validation selects a method.
"""

import argparse
import csv
import hashlib
import json
import math
import os
import platform
import subprocess
import sys
from collections import defaultdict
from datetime import date, timedelta, datetime, timezone
from pathlib import Path

import joblib
import numpy as np
import sklearn
from sklearn.ensemble import GradientBoostingRegressor, RandomForestRegressor

from product_forecast import features, weekly_features

TRAIN_END = date(2025, 12, 31)
VALID_END = date(2026, 6, 30)
TEST_END = date(2026, 11, 5)
SEED = 20261105
FEATURE_NAMES = {
    7: ["last_day", "same_day_last_week", "same_day_two_weeks_ago", "same_day_four_weeks_ago",
        "last_week_total", "prior_week_total", "last_28_day_mean", "last_28_day_std",
        "weekly_change", "annual_sin", "annual_cos"],
    30: ["last_day", "same_day_last_week", "same_day_two_weeks_ago", "same_day_four_weeks_ago",
         "last_7_day_mean", "last_28_day_mean", "last_28_day_std", "origin_weekday_offset"],
}
PARAMS = {
    "random_forest": {"n_estimators": 120, "max_depth": 5, "min_samples_leaf": 4, "random_state": SEED, "n_jobs": 1},
    "gradient_boosting": {"n_estimators": 120, "max_depth": 3, "learning_rate": .05,
                          "min_samples_leaf": 4, "random_state": SEED},
}


def load_data(folder):
    meta = json.loads((folder / "dataset_metadata.json").read_text(encoding="utf-8"))
    csv_path = folder / "daily_product_demand.csv"
    digest = hashlib.sha256(csv_path.read_bytes()).hexdigest()
    if digest != meta["sha256"]["daily_product_demand"]:
        raise ValueError("Demand CSV hash does not match dataset metadata")
    by_product = defaultdict(list)
    with csv_path.open(newline="", encoding="utf-8") as stream:
        for row in csv.DictReader(stream):
            day = date.fromisoformat(row["date"])
            quantity = int(row["cases_ordered"])
            if day.weekday() == 6 and (quantity or row["day_type"] != "closed_sunday"):
                raise ValueError("Sunday must be a closed zero-demand day")
            if day.weekday() != 6 and row["day_type"] not in ("operating_with_orders", "operating_zero_orders"):
                raise ValueError("Operating day has an invalid label")
            by_product[row["product_id"]].append((day, quantity, row["sku"]))
    for product, values in by_product.items():
        values.sort()
        if values[0][0] != date(2024, 1, 1) or values[-1][0] != TEST_END or len(values) != 1040:
            raise ValueError(f"Product {product} has incomplete daily coverage")
        if any(values[i + 1][0] != values[i][0] + timedelta(days=1) for i in range(len(values) - 1)):
            raise ValueError(f"Product {product} has missing dates")
    return dict(by_product), meta, digest


def examples(dated, horizon):
    start = dated[0][0]
    values = [r[1] for r in dated]
    result = []
    for origin in range(55 if horizon == 7 else 89, len(values) - horizon):
        day = start + timedelta(days=origin)
        if horizon == 7 and day.weekday() != 6:
            continue  # Sunday origin predicts the following Monday-Sunday calendar week.
        if horizon == 30 and day.weekday() != 6:
            continue  # Weekly origins limit overlapping 30-calendar-day targets.
        target_end = day + timedelta(days=horizon)
        if target_end > TEST_END:
            continue
        x = weekly_features(values, origin, start) if horizon == 7 else features(values, origin)
        actual = sum(values[origin + 1:origin + horizon + 1])
        baseline = sum(values[origin - horizon + 1:origin + 1])
        result.append({"origin": day, "target_end": target_end, "features": x,
                       "actual": actual, "baseline": baseline})
    return result


def fit(method, training):
    estimator = RandomForestRegressor(**PARAMS[method]) if method == "random_forest" else GradientBoostingRegressor(**PARAMS[method])
    estimator.fit(np.asarray([r["features"] for r in training]), np.asarray([r["actual"] for r in training]))
    return estimator


def score(rows):
    if not rows:
        return {"n": 0, "mae_cases": None, "rmse_cases": None, "bias_cases": None}
    errors = np.asarray([r["predicted_cases"] - r["actual_cases"] for r in rows], dtype=float)
    return {"n": len(rows), "mae_cases": round(float(np.mean(np.abs(errors))), 3),
            "rmse_cases": round(float(np.sqrt(np.mean(errors ** 2))), 3),
            "bias_cases": round(float(np.mean(errors)), 3)}


def make_svg(path, rows, product, horizon, split):
    choices = [r for r in rows if r["product_id"] == product and r["horizon_days"] == horizon and r["split"] == split]
    if not choices:
        return
    names = ["historical_baseline", "random_forest", "gradient_boosting"]
    labels = sorted({r["origin_date"] for r in choices})
    peak = max(float(r[k]) for r in choices for k in ("actual_cases", "predicted_cases")) * 1.1
    width, height = 950, 360
    colors = {"actual": "#222", "historical_baseline": "#888", "random_forest": "#ac2334", "gradient_boosting": "#138e7c"}
    parts = [f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width} {height}">',
             '<rect width="100%" height="100%" fill="white"/>',
             f'<text x="55" y="27" font-size="17">Product {product} · {horizon}-day {split} · cases</text>']
    for method in ["actual"] + names:
        subset = [r for r in choices if r["method"] == ("historical_baseline" if method == "actual" else method)]
        key = "actual_cases" if method == "actual" else "predicted_cases"
        points = " ".join(f'{55 + labels.index(r["origin_date"]) * 840 / max(1, len(labels)-1):.1f},{320 - float(r[key]) * 275 / peak:.1f}' for r in subset)
        parts.append(f'<polyline fill="none" stroke="{colors[method]}" stroke-width="{2.5 if method == "actual" else 1.7}" points="{points}"/>')
    for i, method in enumerate(["actual"] + names):
        parts.append(f'<text x="{60+i*220}" y="347" fill="{colors[method]}" font-size="13">{method.replace("_", " ")}</text>')
    parts.append('</svg>')
    path.write_text("\n".join(parts), encoding="utf-8")


def run(data_folder, output_folder):
    if output_folder.exists():
        raise FileExistsError(f"Experiment folder already exists: {output_folder}")
    dataset, metadata, digest = load_data(data_folder)
    report = subprocess.run([sys.executable, str(data_folder / "validate_dataset.py")], capture_output=True, text=True)
    if report.returncode:
        raise RuntimeError("Dataset validation failed: " + report.stdout + report.stderr)
    output_folder.mkdir(parents=True)
    (output_folder / "models").mkdir()
    (output_folder / "charts").mkdir()
    (output_folder / "dataset_metadata.json").write_text(json.dumps(metadata, indent=2), encoding="utf-8")
    (output_folder / "validation_report.json").write_bytes((data_folder / "validation_report.json").read_bytes())
    predictions, metrics = [], []
    chosen = {}
    leakage_checks = []
    for product_id, dated in sorted(dataset.items(), key=lambda entry: int(entry[0])):
        for horizon in (7, 30):
            all_examples = examples(dated, horizon)
            train = [r for r in all_examples if r["target_end"] <= TRAIN_END]
            valid = [r for r in all_examples if TRAIN_END < r["origin"] and r["target_end"] <= VALID_END]
            test = [r for r in all_examples if VALID_END < r["origin"] and r["target_end"] <= TEST_END]
            if min(len(train), len(valid), len(test)) < 5:
                raise ValueError(f"Too few examples for product {product_id}, horizon {horizon}")
            # Test fitting can only use outcomes fully known by the end of validation.
            train_valid = [r for r in all_examples if r["target_end"] <= VALID_END]
            leakage_checks.append(max(r["target_end"] for r in train) <= TRAIN_END and
                                  max(r["target_end"] for r in train_valid) <= VALID_END and
                                  min(r["origin"] for r in test) > VALID_END)
            for split_name, evaluation, training in (("validation", valid, train), ("test", test, train_valid)):
                for method in ("historical_baseline", "random_forest", "gradient_boosting"):
                    estimator = None if method == "historical_baseline" else fit(method, training)
                    if estimator is not None:
                        joblib.dump(estimator, output_folder / "models" / f"p{product_id}_{horizon}d_{split_name}_{method}.joblib")
                    for ex in evaluation:
                        prediction = ex["baseline"] if estimator is None else max(0.0, float(estimator.predict(np.asarray([ex["features"]]))[0]))
                        predictions.append({"product_id": product_id, "sku": dated[0][2], "horizon_days": horizon,
                            "split": split_name, "method": method, "origin_date": ex["origin"].isoformat(),
                            "target_end": ex["target_end"].isoformat(), "actual_cases": ex["actual"],
                            "predicted_cases": round(prediction, 4)})
            scores = {m: score([p for p in predictions if p["product_id"] == product_id and p["horizon_days"] == horizon
                               and p["split"] == "validation" and p["method"] == m])["mae_cases"]
                      for m in ("historical_baseline", "random_forest", "gradient_boosting")}
            chosen[f"product_{product_id}_{horizon}d"] = min(scores, key=scores.get)
            for split in ("validation", "test"):
                make_svg(output_folder / "charts" / f"p{product_id}_{horizon}d_{split}.svg", predictions, product_id, horizon, split)
    if not all(leakage_checks):
        raise AssertionError("Chronological data leakage check failed")
    for product in sorted(dataset, key=int):
        for horizon in (7, 30):
            for split in ("validation", "test"):
                for method in ("historical_baseline", "random_forest", "gradient_boosting"):
                    subset = [p for p in predictions if p["product_id"] == product and p["horizon_days"] == horizon and
                              p["split"] == split and p["method"] == method]
                    metrics.append({"product_id": product, "horizon_days": horizon, "split": split,
                                    "method": method, **score(subset)})
    for horizon in (7, 30):
        for split in ("validation", "test"):
            for method in ("historical_baseline", "random_forest", "gradient_boosting"):
                subset = [p for p in predictions if p["horizon_days"] == horizon and p["split"] == split and p["method"] == method]
                metrics.append({"product_id": "overall", "horizon_days": horizon, "split": split,
                                "method": method, **score(subset)})
    with (output_folder / "predictions.csv").open("w", newline="", encoding="utf-8") as stream:
        writer = csv.DictWriter(stream, fieldnames=list(predictions[0])); writer.writeheader(); writer.writerows(predictions)
    with (output_folder / "metrics.csv").open("w", newline="", encoding="utf-8") as stream:
        writer = csv.DictWriter(stream, fieldnames=list(metrics[0])); writer.writeheader(); writer.writerows(metrics)
    git = subprocess.run(["git", "rev-parse", "HEAD"], cwd=Path(__file__).resolve().parents[1], capture_output=True, text=True)
    config = {"experiment_id": output_folder.name, "executed_utc": datetime.now(timezone.utc).isoformat(),
              "git_commit": git.stdout.strip() if git.returncode == 0 else "unavailable",
              "dataset_demand_sha256": digest, "seed": SEED, "train_target_end": str(TRAIN_END),
              "validation_target_end": str(VALID_END), "test_target_end": str(TEST_END),
              "horizons": "7 and 30 calendar days; Sunday is closed with zero demand",
              "target": "Accepted customer order cases; cancelled and draft requests excluded",
              "origin_schedule": "Sunday weekly origins", "feature_names": FEATURE_NAMES,
              "parameters": PARAMS, "python": sys.version, "platform": platform.platform(),
              "numpy": np.__version__, "sklearn": sklearn.__version__, "joblib": joblib.__version__,
              "leakage_checks_passed": all(leakage_checks), "validation_selected_method_by_product": chosen,
              "test_caveat": "Chronologically held out in this run, but prior project work examined some 2026 dates; not a genuinely untouched external test."}
    (output_folder / "config.json").write_text(json.dumps(config, indent=2), encoding="utf-8")
    preprocessing = {"numeric_imputation": "none; complete daily calendar required",
                     "scaling": "none; tree regressors and the baseline use raw case counts",
                     "closed_sunday": "known zero cases, never an unknown observation",
                     "feature_names": FEATURE_NAMES,
                     "fitted_preprocessing_artifact": "none required"}
    (output_folder / "preprocessing.json").write_text(json.dumps(preprocessing, indent=2), encoding="utf-8")
    overall = [m for m in metrics if m["product_id"] == "overall"]
    summary = {"experiment_id": output_folder.name, "dataset_sha256": digest,
               "selected_from_validation": chosen, "overall_metrics": overall,
               "interpretation": "Validation chooses methods. Test scores are reported, not used to reselect. Synthetic performance does not prove real-company accuracy."}
    (output_folder / "summary.json").write_text(json.dumps(summary, indent=2), encoding="utf-8")
    (output_folder / "execution.txt").write_text(f"Dataset validation passed. Product count: {len(dataset)}. Chronology checks passed.\n"
        f"Prediction rows: {len(predictions)}. Model artefacts: {len(list((output_folder / 'models').glob('*.joblib')))}.\n", encoding="utf-8")
    print(json.dumps(summary, indent=2))


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--data", type=Path, default=Path(__file__).resolve().parents[1] / "database" / "packaged-data-next")
    parser.add_argument("--output", type=Path, default=Path(__file__).resolve().parent / "experiments" /
                        datetime.now(timezone.utc).strftime("synthetic_%Y%m%dT%H%M%SZ"))
    args = parser.parse_args()
    run(args.data.resolve(), args.output.resolve())
