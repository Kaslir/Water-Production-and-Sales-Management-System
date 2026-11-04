import json
import sys
from collections import deque
from datetime import date, timedelta

import numpy as np
from sklearn.ensemble import RandomForestRegressor


HORIZONS = (7, 30)
MIN_HISTORY = 90
METHOD = "random_forest"


def features(values, origin):
    history = values[: origin + 1]
    return [
        history[-1],
        history[-7],
        history[-14],
        history[-28],
        float(np.mean(history[-7:])),
        float(np.mean(history[-28:])),
        float(np.std(history[-28:])),
        origin % 7,
    ]


def model_for():
    return RandomForestRegressor(
        n_estimators=120, max_depth=5, min_samples_leaf=4, random_state=2026
    )


def weekly_features(values, origin, start_date):
    recent_week = float(sum(values[origin - 6 : origin + 1]))
    earlier_week = float(sum(values[origin - 13 : origin - 6]))
    four_weeks = values[origin - 27 : origin + 1]
    next_monday = start_date + timedelta(days=origin + 1)
    angle = 2 * np.pi * (next_monday.timetuple().tm_yday - 1) / 365.25
    return [
        values[origin], values[origin - 7], values[origin - 14], values[origin - 28],
        recent_week, earlier_week, float(np.mean(four_weeks)),
        float(np.std(four_weeks)), recent_week - earlier_week,
        float(np.sin(angle)), float(np.cos(angle)),
    ]


def forecast_weekly(values, start_date):
    count = len(values)
    if count < 180 or (start_date + timedelta(days=count - 1)).weekday() != 6:
        return {"available": False, "reason": "At least 180 dated days ending on Sunday are needed for a weekly forecast."}

    completed_origins = [
        origin for origin in range(55, count - 7)
        if (start_date + timedelta(days=origin)).weekday() == 6
    ]
    evaluation_origins = completed_origins[-25:]
    if len(evaluation_origins) < 12:
        return {"available": False, "reason": "At least 12 completed weeks are needed for chronological evaluation."}

    errors = []
    raw_history = deque(maxlen=8)
    for origin in evaluation_origins:
        training_origins = [candidate for candidate in completed_origins if candidate + 7 <= origin]
        if len(training_origins) < 8:
            return {"available": False, "reason": "Not enough earlier weeks to train the model."}
        x_train = np.asarray([weekly_features(values, candidate, start_date) for candidate in training_origins])
        y_train = np.asarray([sum(values[candidate + 1 : candidate + 8]) for candidate in training_origins])
        x_test = np.asarray([weekly_features(values, origin, start_date)])
        actual = sum(values[origin + 1 : origin + 8])
        estimator = model_for()
        estimator.fit(x_train, y_train)
        raw = max(0.0, float(estimator.predict(x_test)[0]))
        correction = float(np.mean(raw_history)) if len(raw_history) >= 4 else 0.0
        errors.append(max(0.0, raw + correction) - actual)
        raw_history.append(actual - raw)

    scores = {}
    latest_origin = count - 1
    x_train = np.asarray([weekly_features(values, origin, start_date) for origin in completed_origins])
    y_train = np.asarray([sum(values[origin + 1 : origin + 8]) for origin in completed_origins])
    latest_features = np.asarray([weekly_features(values, latest_origin, start_date)])
    estimator = model_for()
    estimator.fit(x_train, y_train)
    raw = max(0.0, float(estimator.predict(latest_features)[0]))
    correction = float(np.mean(raw_history)) if len(raw_history) >= 4 else 0.0
    prediction = max(0.0, raw + correction)
    scores[METHOD] = {
        "predicted_cases": round(prediction, 2),
        "mae_cases": round(float(np.mean(np.abs(errors))), 2),
        "bias_cases": round(float(np.mean(errors)), 2),
        "backtest_points": len(errors),
        "correction_cases": round(correction, 2),
    }

    return {"available": True, "best_method": METHOD, "methods": scores, "week_start": str(start_date + timedelta(days=count)), "week_end": str(start_date + timedelta(days=count + 6))}


def forecast_horizon(values, horizon):
    count = len(values)
    if count < MIN_HISTORY + horizon + 35:
        return {"available": False, "reason": "Not enough dated product demand for this horizon."}

    origins = list(range(MIN_HISTORY - 1, count - horizon, 7))
    if len(origins) < 12:
        return {"available": False, "reason": "Not enough historical evaluation points."}

    folds = [int(count * fraction) for fraction in (0.55, 0.7, 0.85)]
    actual_values = []
    predicted_values = []

    for cutoff in folds:
        training_origins = [origin for origin in origins if origin + horizon < cutoff]
        testing_origins = [origin for origin in origins if cutoff <= origin < min(cutoff + 60, count - horizon)]
        if len(training_origins) < 8 or not testing_origins:
            continue
        x_train = np.asarray([features(values, origin) for origin in training_origins])
        y_train = np.asarray([sum(values[origin + 1 : origin + horizon + 1]) for origin in training_origins])
        x_test = np.asarray([features(values, origin) for origin in testing_origins])
        actuals = [sum(values[origin + 1 : origin + horizon + 1]) for origin in testing_origins]

        estimator = model_for()
        estimator.fit(x_train, y_train)
        predictions = estimator.predict(x_test)
        actual_values.extend(actuals)
        predicted_values.extend(max(0.0, float(prediction)) for prediction in predictions)

    if len(actual_values) < 12:
        return {"available": False, "reason": "At least 12 chronological backtest points are required."}

    errors = [prediction - actual for prediction, actual in zip(predicted_values, actual_values)]
    scores = {METHOD: {
        "mae_cases": round(sum(abs(error) for error in errors) / len(errors), 2),
        "bias_cases": round(sum(errors) / len(errors), 2),
        "backtest_points": len(errors),
    }}
    latest_features = np.asarray([features(values, count - 1)])
    estimator = model_for()
    estimator.fit(
        np.asarray([features(values, origin) for origin in origins]),
        np.asarray([sum(values[origin + 1 : origin + horizon + 1]) for origin in origins]),
    )
    estimate = estimator.predict(latest_features)[0]
    scores[METHOD]["predicted_cases"] = round(max(0.0, float(estimate)), 2)

    return {"available": True, "best_method": METHOD, "methods": scores}


def forecast_payload(payload):
    values = [max(0.0, float(value)) for value in payload["daily_cases"]]
    start_date = date.fromisoformat(payload["start_date"])
    return {"7": forecast_weekly(values, start_date), "30": forecast_horizon(values, 30)}


def main():
    payload = json.load(sys.stdin)
    if "products" in payload:
        result = {
            product_id: forecast_payload(product_payload)
            for product_id, product_payload in payload["products"].items()
        }
    else:
        result = forecast_payload(payload)
    print(json.dumps(result))


if __name__ == "__main__":
    main()
