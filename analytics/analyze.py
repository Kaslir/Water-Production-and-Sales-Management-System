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
    moving_average_predictions = []

    # Generating moving-average predictions
    for index in range(1, len(values)):
        previous = values[max(0, index - window):index]
        moving_average_predictions.append(sum(previous) / len(previous))

    # Calculating moving-average error using MAE
    actual = values[1:]
    moving_average = metrics(actual, moving_average_predictions)
    # Estimating the next day's sales demand
    next_moving_average = sum(values[-window:]) / min(len(values), window)

    return {
        'moving_average': moving_average,
        'best_method': 'moving_average',
        'forecast_quantity': round(next_moving_average, 2),
    }


# Reading sales data and returning the forecast
def main():
    values = [float(value) for value in json.load(sys.stdin).get('sales', [])]
    print(json.dumps(analyze(values)))


if __name__ == '__main__':
    main()
