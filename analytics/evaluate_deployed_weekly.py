"""Replay the exact deployed seven-day Random Forest procedure on dated history.

This is separate from compare_product_demand.py's fixed-cutoff model comparison.
Each origin uses only the observations available on that Sunday; forecast_weekly
performs its usual rolling retraining and residual correction without alteration.
"""

import argparse
import csv
import hashlib
import json
import platform
from datetime import date, timedelta
from pathlib import Path

import numpy
import sklearn

from product_forecast import forecast_weekly, model_for


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--data", type=Path, default=Path("database/packaged-data-next"))
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--max-origins-per-split", type=int, default=0,
                        help="0 evaluates every eligible week; a positive value selects evenly spaced weeks")
    args = parser.parse_args()
    if args.output.exists():
        raise SystemExit("Use a new output directory to preserve earlier evidence")

    csv_path = args.data / "daily_product_demand.csv"
    metadata = json.loads((args.data / "dataset_metadata.json").read_text(encoding="utf-8"))
    digest = hashlib.sha256(csv_path.read_bytes()).hexdigest()
    if digest != metadata["sha256"]["daily_product_demand"]:
        raise SystemExit("Dataset hash mismatch")

    products = {}
    with csv_path.open(newline="", encoding="utf-8") as stream:
        for row in csv.DictReader(stream):
            products.setdefault(row["product_id"], []).append((date.fromisoformat(row["date"]), int(row["cases_ordered"]), row["sku"]))

    rows = []
    for product_id, observations in sorted(products.items(), key=lambda item: int(item[0])):
        observations.sort()
        start = observations[0][0]
        if any(day != start + timedelta(days=index) for index, (day, _, _) in enumerate(observations)):
            raise SystemExit(f"Missing or duplicate dates for product {product_id}")
        values = [quantity for _, quantity, _ in observations]
        for split, lower, upper in [
            ("validation", date(2026, 1, 1), date(2026, 6, 30)),
            ("test", date(2026, 7, 1), date(2026, 11, 5)),
        ]:
            origins = [index for index, (day, _, _) in enumerate(observations)
                       if day.weekday() == 6 and day >= lower and day + timedelta(days=7) <= upper and index >= 180]
            if args.max_origins_per_split > 0 and len(origins) > args.max_origins_per_split:
                n = args.max_origins_per_split
                origins = [origins[round(i * (len(origins) - 1) / (n - 1))] for i in range(n)] if n > 1 else [origins[-1]]
            for origin in origins:
                report = forecast_weekly(values[:origin + 1], start)
                if not report["available"]:
                    raise SystemExit(f"Forecast unavailable at {observations[origin][0]}: {report['reason']}")
                result = report["methods"]["random_forest"]
                actual = sum(values[origin + 1:origin + 8])
                rows.append({"product_id": product_id, "sku": observations[origin][2], "split": split,
                             "origin_date": observations[origin][0].isoformat(),
                             "target_end": (observations[origin][0] + timedelta(days=7)).isoformat(),
                             "actual_cases": actual, "predicted_cases": result["predicted_cases"],
                             "historical_baseline_cases": sum(values[origin - 6:origin + 1]),
                             "correction_cases": result["correction_cases"],
                             "rolling_backtest_points": result["backtest_points"]})

    args.output.mkdir(parents=True)
    with (args.output / "predictions.csv").open("w", newline="", encoding="utf-8") as stream:
        writer = csv.DictWriter(stream, fieldnames=list(rows[0]))
        writer.writeheader()
        writer.writerows(rows)

    def metrics(items, key):
        errors = [float(row[key]) - row["actual_cases"] for row in items]
        return {"n": len(errors), "mae_cases": round(sum(map(abs, errors)) / len(errors), 3),
                "bias_cases": round(sum(errors) / len(errors), 3)}

    summary = {"dataset_sha256": digest, "dataset_first_date": str(min(r[0] for records in products.values() for r in records)),
               "dataset_last_date": str(max(r[0] for records in products.values() for r in records)),
               "procedure": "analytics/product_forecast.py::forecast_weekly (exact deployed logic)",
               "model_parameters": model_for().get_params(), "python_version": platform.python_version(),
               "numpy_version": numpy.__version__, "sklearn_version": sklearn.__version__,
               "sampling": "all eligible Sundays" if not args.max_origins_per_split else f"up to {args.max_origins_per_split} evenly spaced Sundays per product and split",
               "metrics": []}
    for split in ["validation", "test"]:
        for product_id in sorted(products, key=int):
            selected = [row for row in rows if row["split"] == split and row["product_id"] == product_id]
            if selected:
                summary["metrics"].append({"split": split, "product_id": product_id,
                                           "random_forest": metrics(selected, "predicted_cases"),
                                           "historical_baseline": metrics(selected, "historical_baseline_cases")})
    (args.output / "summary.json").write_text(json.dumps(summary, indent=2), encoding="utf-8")
    print(json.dumps({"rows": len(rows), "output": str(args.output), "dataset_sha256": digest}))


if __name__ == "__main__":
    main()
