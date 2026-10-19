import json
import sys


# Calculating forecast error using MAE
def metrics(actual, predicted):
    count = len(actual)
    mae = sum(abs(a - p) for a, p in zip(actual, predicted)) / count
    return {'mae': round(mae, 4)}


def analyze(values):
    # Checking whether forecasting is possible
    if len(values) < 2:
        raise ValueError('At least two sales observations are required.')

    # Setting the forecasting parameters
    window = 3
    alpha = 0.3
    moving_average_predictions = []
    smoothing_predictions = []
    smoothing_level = values[0]

    # Generating moving-average and exponential-smoothing predictions
    for index in range(1, len(values)):
        previous = values[max(0, index - window):index]
        moving_average_predictions.append(sum(previous) / len(previous))
        smoothing_predictions.append(smoothing_level)
        smoothing_level = alpha * values[index] + (1 - alpha) * smoothing_level

    # Comparing forecast methods using MAE
    actual = values[1:]
    moving_average = metrics(actual, moving_average_predictions)
    exponential_smoothing = metrics(actual, smoothing_predictions)
    best_method = 'moving_average' if moving_average['mae'] <= exponential_smoothing['mae'] else 'exponential_smoothing'
    # Estimating the next day's sales demand
    next_moving_average = sum(values[-window:]) / min(len(values), window)
    next_estimate = next_moving_average if best_method == 'moving_average' else smoothing_level

    return {
        'moving_average': moving_average,
        'exponential_smoothing': exponential_smoothing,
        'best_method': best_method,
        'forecast_quantity': round(next_estimate, 2),
    }


# Reading sales data and returning the forecast
def main():
    values = [float(value) for value in json.load(sys.stdin).get('sales', [])]
    print(json.dumps(analyze(values)))


if __name__ == '__main__':
    main()
