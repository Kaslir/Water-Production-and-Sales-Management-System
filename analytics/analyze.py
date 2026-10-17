import json
import math
import sys


def metrics(actual, predicted):
    count = len(actual)
    mae = sum(abs(a - p) for a, p in zip(actual, predicted)) / count
    rmse = math.sqrt(sum((a - p) ** 2 for a, p in zip(actual, predicted)) / count)
    percentage_errors = [abs((a - p) / a) * 100 for a, p in zip(actual, predicted) if a]
    mape = sum(percentage_errors) / len(percentage_errors) if percentage_errors else 0
    return {'mae': round(mae, 4), 'rmse': round(rmse, 4), 'mape': round(mape, 4)}


def analyze(values):
    if len(values) < 2:
        raise ValueError('At least two sales observations are required.')

    window = 3
    alpha = 0.3
    moving_average_predictions = []
    smoothing_predictions = []
    smoothing_level = values[0]

    for index in range(1, len(values)):
        previous = values[max(0, index - window):index]
        moving_average_predictions.append(sum(previous) / len(previous))
        smoothing_predictions.append(smoothing_level)
        smoothing_level = alpha * values[index] + (1 - alpha) * smoothing_level

    actual = values[1:]
    moving_average = metrics(actual, moving_average_predictions)
    exponential_smoothing = metrics(actual, smoothing_predictions)
    best_method = 'moving_average' if moving_average['mae'] <= exponential_smoothing['mae'] else 'exponential_smoothing'
    next_moving_average = sum(values[-window:]) / min(len(values), window)
    next_estimate = next_moving_average if best_method == 'moving_average' else smoothing_level

    return {
        'moving_average': moving_average,
        'exponential_smoothing': exponential_smoothing,
        'best_method': best_method,
        'forecast_quantity': round(next_estimate, 2),
    }


def main():
    values = [float(value) for value in json.load(sys.stdin).get('sales', [])]
    print(json.dumps(analyze(values)))


if __name__ == '__main__':
    main()
