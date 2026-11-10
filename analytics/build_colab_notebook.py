"""Write the human-readable Colab entry point for the versioned experiment."""

import json
from pathlib import Path


def markdown(source):
    return {"cell_type": "markdown", "metadata": {}, "source": source.splitlines(keepends=True)}


def code(source):
    return {"cell_type": "code", "execution_count": None, "metadata": {}, "outputs": [],
            "source": source.splitlines(keepends=True)}


cells = [
    markdown("""# AquaTrack: compare product-demand models

This notebook compares a historical-demand baseline, Random Forest and Gradient Boosting on the **entirely synthetic** AquaTrack dataset dated 1 January 2024 to 5 November 2026. It keeps the application database and deployed model unchanged. Run cells top to bottom in a Google Colab CPU runtime after the new dataset and code have been committed and pushed to GitHub. The notebook saves a new evidence folder in Google Drive every run.

The 7-day target means the next Monday through Sunday (six operating days and a closed Sunday). The 30-day target means 30 calendar days. The model sees only completed demand before each forecast origin. Validation is used to compare methods; the later dates are reported as a chronological test. Some later 2026 dates were examined in earlier project work, so this is **not** a genuinely untouched external test.
"""),
    code("""%pip -q install 'numpy>=2,<3' 'pandas>=2,<3' 'scikit-learn>=1.6,<2' 'joblib>=1.4,<2'
from google.colab import drive
drive.mount('/content/drive')
"""),
    markdown("""## 1. Get the exact project version

The commit ID connects these results to the code and data used. Set `GIT_COMMIT` to the commit you pushed. Do not use a changing branch name for a final reported experiment.
"""),
    code("""import subprocess
from pathlib import Path

REPOSITORY_URL = 'https://github.com/Kaslir/Water-Production-and-Sales-Management-System.git'
GIT_COMMIT = 'PASTE_THE_COMMIT_HASH_HERE'
if GIT_COMMIT == 'PASTE_THE_COMMIT_HASH_HERE':
    raise ValueError('Paste the pushed commit hash before running this cell.')
project = Path('/content/aquatrack')
subprocess.run(['git', 'clone', REPOSITORY_URL, str(project)], check=True)
subprocess.run(['git', 'checkout', GIT_COMMIT], cwd=project, check=True)
print('Checked out:', subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=project, text=True).strip())
"""),
    markdown("""## 2. Validate the generated operations

The validator checks date coverage, Sunday closure, references, order totals, delivery fees, stock reconciliation, invoices, payments and an isolated relational load. The demand CSV has one row per product per calendar day; a closed Sunday is explicitly marked and has zero demand.
"""),
    code("""import json
import pandas as pd

data_dir = project / 'database' / 'packaged-data-next'
subprocess.run(['python', str(data_dir / 'validate_dataset.py')], check=True)
metadata = json.loads((data_dir / 'dataset_metadata.json').read_text())
demand = pd.read_csv(data_dir / 'daily_product_demand.csv', parse_dates=['date'])
display(pd.DataFrame([{'first_date': demand.date.min(), 'last_date': demand.date.max(),
                       'daily_rows': len(demand), 'products': demand.product_id.nunique(),
                       'closed_sunday_rows': (demand.day_type == 'closed_sunday').sum(),
                       'operating_zero_order_rows': (demand.day_type == 'operating_zero_orders').sum()}]))
print('Sunday operational orders:', demand.loc[demand.date.dt.weekday == 6, 'cases_ordered'].sum())
"""),
    markdown("""## 3. Run the chronological experiment

Training targets finish by 31 December 2025. Validation targets finish by 30 June 2026. Test targets finish by 5 November 2026. Both models use the same historical features as the deployed forecast: recent demand, weekly lags and rolling summaries; the weekly model also uses annual sine and cosine terms. The baseline repeats the previous comparable period. No future production or orders enter the features. Hyperparameters and seed are fixed in `analytics/compare_product_demand.py`.
"""),
    code("""from datetime import datetime, timezone

run_id = datetime.now(timezone.utc).strftime('aquatrack_synthetic_%Y%m%dT%H%M%SZ')
output = Path('/content/drive/MyDrive/AquaTrack/experiments') / run_id
script = project / 'analytics' / 'compare_product_demand.py'
subprocess.run(['python', str(script), '--data', str(data_dir), '--output', str(output)], check=True)
print('Evidence folder:', output)
"""),
    markdown("""## 4. Read the results

MAE and RMSE are prediction errors in cases. Bias is predicted minus actual cases; negative bias means underprediction. A model is selected using validation MAE only. The test column reports later performance and does not reselect the model. The figures show actual and predicted totals for each product and horizon.
"""),
    code("""from IPython.display import display, SVG

metrics = pd.read_csv(output / 'metrics.csv')
display(metrics[metrics.product_id.astype(str) == 'overall'].sort_values(['horizon_days','split','mae_cases']))
summary = json.loads((output / 'summary.json').read_text())
print('Validation-selected methods:')
display(pd.DataFrame(summary['selected_from_validation'].items(), columns=['product and horizon', 'method']))
display(SVG(filename=str(output / 'charts' / 'p1_7d_test.svg')))
"""),
    markdown("""## 5. Keep the evidence

The run folder contains configuration, exact dataset hash, dependency versions, validation report, per-product and overall metrics, every actual and predicted value, model files, charts, and an execution log. Preserve the entire folder, including unsuccessful runs. The experiment models are **not automatically deployed**. Compare the evaluated prediction procedure with AquaTrack's weekly bias correction and 30-day routine before any deployment decision.
"""),
]

notebook = {"cells": cells, "metadata": {"colab": {"provenance": []},
            "kernelspec": {"display_name": "Python 3", "name": "python3"},
            "language_info": {"name": "python"}}, "nbformat": 4, "nbformat_minor": 5}
path = Path(__file__).with_name("train_product_demand_colab.ipynb")
path.write_text(json.dumps(notebook, indent=2) + "\n", encoding="utf-8")
print(path)
