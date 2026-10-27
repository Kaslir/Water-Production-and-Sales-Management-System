import json
import sys
from collections import deque
from datetime import date, timedelta

import numpy as np
from sklearn.ensemble import GradientBoostingRegressor, RandomForestRegressor


HORIZONS = (7, 30)
MIN_HISTORY = 90
METHODS = ("random_forest", "gradient_boosting")
WEEKLY_METHODS = ("previous_week",) + METHODS


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


def model_for(method):
    if method == "random_forest":
        return RandomForestRegressor(
            n_estimators=120, max_depth=5, min_samples_leaf=4, random_state=2026
        )
    return GradientBoostingRegressor(
        n_estimators=120, max_depth=2, min_samples_leaf=5,
        learning_rate=0.04, random_state=2026,
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

    errors = {method: [] for method in WEEKLY_METHODS}
    raw_history = {method: deque(maxlen=8) for method in METHODS}
    for origin in evaluation_origins:
        training_origins = [candidate for candidate in completed_origins if candidate + 7 <= origin]
        if len(training_origins) < 8:
            return {"available": False, "reason": "Not enough earlier weeks to train both models."}
        x_train = np.asarray([weekly_features(values, candidate, start_date) for candidate in training_origins])
        y_train = np.asarray([sum(values[candidate + 1 : candidate + 8]) for candidate in training_origins])
        x_test = np.asarray([weekly_features(values, origin, start_date)])
        actual = sum(values[origin + 1 : origin + 8])
        baseline = sum(values[origin - 6 : origin + 1])
        errors["previous_week"].append(baseline - actual)
        for method in METHODS:
            estimator = model_for(method)
            estimator.fit(x_train, y_train)
            raw = max(0.0, float(estimator.predict(x_test)[0]))
            correction = float(np.mean(raw_history[method])) if len(raw_history[method]) >= 4 else 0.0
            errors[method].append(max(0.0, raw + correction) - actual)
            raw_history[method].append(actual - raw)

    scores = {}
    latest_origin = count - 1
    x_train = np.asarray([weekly_features(values, origin, start_date) for origin in completed_origins])
    y_train = np.asarray([sum(values[origin + 1 : origin + 8]) for origin in completed_origins])
    latest_features = np.asarray([weekly_features(values, latest_origin, start_date)])
    for method in WEEKLY_METHODS:
        method_errors = errors[method]
        if method == "previous_week":
            prediction = sum(values[-7:])
            correction = 0.0
        else:
            estimator = model_for(method)
            estimator.fit(x_train, y_train)
            raw = max(0.0, float(estimator.predict(latest_features)[0]))
            correction = float(np.mean(raw_history[method])) if len(raw_history[method]) >= 4 else 0.0
            prediction = max(0.0, raw + correction)
        scores[method] = {
            "predicted_cases": round(prediction, 2),
            "mae_cases": round(float(np.mean(np.abs(method_errors))), 2),
            "bias_cases": round(float(np.mean(method_errors)), 2),
            "backtest_points": len(method_errors),
            "correction_cases": round(correction, 2),
        }

    best = min(WEEKLY_METHODS, key=lambda method: scores[method]["mae_cases"])
    return {"available": True, "best_method": best, "methods": scores, "week_start": str(start_date + timedelta(days=count)), "week_end": str(start_date + timedelta(days=count + 6))}


def forecast_horizon(values, horizon):
    count = len(values)
    if count < MIN_HISTORY + horizon + 35:
        return {"available": False, "reason": "Not enough dated product demand for this horizon."}

    origins = list(range(MIN_HISTORY - 1, count - horizon, 7))
    if len(origins) < 12:
        return {"available": False, "reason": "Not enough historical evaluation points."}

    folds = [int(count * fraction) for fraction in (0.55, 0.7, 0.85)]
    actual_by_method = {method: [] for method in METHODS}
    predicted_by_method = {method: [] for method in METHODS}

    for cutoff in folds:
        training_origins = [origin for origin in origins if origin + horizon < cutoff]
        testing_origins = [origin for origin in origins if cutoff <= origin < min(cutoff + 60, count - horizon)]
        if len(training_origins) < 8 or not testing_origins:
            continue
        x_train = np.asarray([features(values, origin) for origin in training_origins])
        y_train = np.asarray([sum(values[origin + 1 : origin + horizon + 1]) for origin in training_origins])
        x_test = np.asarray([features(values, origin) for origin in testing_origins])
        actuals = [sum(values[origin + 1 : origin + horizon + 1]) for origin in testing_origins]

        for method in METHODS:
            estimator = model_for(method)
            estimator.fit(x_train, y_train)
            predictions = estimator.predict(x_test)
            actual_by_method[method].extend(actuals)
            predicted_by_method[method].extend(max(0.0, float(prediction)) for prediction in predictions)

    if len(actual_by_method[METHODS[0]]) < 12:
        return {"available": False, "reason": "At least 12 chronological backtest points are required."}

    scores = {}
    for method in METHODS:
        errors = [prediction - actual for prediction, actual in zip(predicted_by_method[method], actual_by_method[method])]
        scores[method] = {
            "mae_cases": round(sum(abs(error) for error in errors) / len(errors), 2),
            "bias_cases": round(sum(errors) / len(errors), 2),
            "backtest_points": len(errors),
        }

    best = min(METHODS, key=lambda method: scores[method]["mae_cases"])
    latest_features = np.asarray([features(values, count - 1)])
    for method in METHODS:
        estimator = model_for(method)
        estimator.fit(
            np.asarray([features(values, origin) for origin in origins]),
            np.asarray([sum(values[origin + 1 : origin + horizon + 1]) for origin in origins]),
        )
        estimate = estimator.predict(latest_features)[0]
        scores[method]["predicted_cases"] = round(max(0.0, float(estimate)), 2)

    return {"available": True, "best_method": best, "methods": scores}


def main():
    payload = json.load(sys.stdin)
    values = [max(0.0, float(value)) for value in payload["daily_cases"]]
    start_date = date.fromisoformat(payload["start_date"])
    result = {"7": forecast_weekly(values, start_date), "30": forecast_horizon(values, 30)}
    print(json.dumps(result))


if __name__ == "__main__":
    main()
