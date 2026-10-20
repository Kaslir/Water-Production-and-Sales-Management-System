import json
import sys

import numpy as np
from sklearn.ensemble import GradientBoostingRegressor, RandomForestRegressor


HORIZONS = (7, 30)
MIN_HISTORY = 90
METHODS = ("moving_average", "random_forest", "gradient_boosting")


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


def baseline(history, horizon):
    return max(0.0, float(np.mean(history[-7:])) * horizon)


def model_for(method):
    if method == "random_forest":
        return RandomForestRegressor(
            n_estimators=120, max_depth=5, min_samples_leaf=4, random_state=2026
        )
    return GradientBoostingRegressor(
        n_estimators=120, max_depth=2, min_samples_leaf=5,
        learning_rate=0.04, random_state=2026,
    )


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
            if method in ("random_forest", "gradient_boosting"):
                estimator = model_for(method)
                estimator.fit(x_train, y_train)
                predictions = estimator.predict(x_test)
            else:
                predictions = [baseline(values[: origin + 1], horizon) for origin in testing_origins]
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
    latest_history = values[:]
    latest_features = np.asarray([features(values, count - 1)])
    for method in METHODS:
        if method in ("random_forest", "gradient_boosting"):
            estimator = model_for(method)
            estimator.fit(
                np.asarray([features(values, origin) for origin in origins]),
                np.asarray([sum(values[origin + 1 : origin + horizon + 1]) for origin in origins]),
            )
            estimate = estimator.predict(latest_features)[0]
        else:
            estimate = baseline(latest_history, horizon)
        scores[method]["predicted_cases"] = round(max(0.0, float(estimate)), 2)

    return {"available": True, "best_method": best, "methods": scores}


def main():
    payload = json.load(sys.stdin)
    values = [max(0.0, float(value)) for value in payload["daily_cases"]]
    result = {str(horizon): forecast_horizon(values, horizon) for horizon in HORIZONS}
    print(json.dumps(result))


if __name__ == "__main__":
    main()
