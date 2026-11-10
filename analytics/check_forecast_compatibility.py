"""Smoke-test the unchanged deployed Python forecast against the new calendar."""

import json
from datetime import date
from pathlib import Path

from compare_product_demand import load_data
from product_forecast import forecast_payload

root = Path(__file__).resolve().parents[1]
dataset, _, _ = load_data(root / "database" / "packaged-data-next")
result = {}
for product_id, dated in sorted(dataset.items(), key=lambda pair: int(pair[0])):
    through_sunday = [row for row in dated if row[0] <= date(2026, 11, 1)]
    payload = {"start_date": through_sunday[0][0].isoformat(),
               "daily_cases": [row[1] for row in through_sunday]}
    response = forecast_payload(payload)
    result[product_id] = {horizon: {"available": result["available"],
                                    "predicted_cases": result.get("methods", {}).get("random_forest", {}).get("predicted_cases")}
                          for horizon, result in response.items()}
    if not all(item["available"] for item in result[product_id].values()):
        raise RuntimeError(f"Deployed Python forecast unavailable for product {product_id}")
path = root / "analytics" / "experiments" / "synthetic_20261105_05" / "python_compatibility.json"
path.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
print(json.dumps(result, indent=2))
