# AquaTrack

AquaTrack is an internal bottled-water production and sales management system. It records production batches, quality decisions, saleable stock, customer orders, deliveries, invoices and payments. It also uses historical customer orders to estimate short-term demand and help managers decide how many cases to produce.

The project was developed as a university capstone. Its demonstration records are **synthetic**; they do not represent transactions from a real company.

## What the system does

- Manages four products: 500 mL, 1 L, 5 L and 20 L bottled water, with sales and stock measured in cases.
- Records production batches with an approved manufacturing policy, production date, calculated expiry date and estimated costs.
- Holds new batches for quality review. Only released, unexpired stock can be reserved or dispatched.
- Records customer orders, reserves eligible batches, and allocates stock by earliest expiry first.
- Tracks dispatch and delivery through the single-truck Nairobi distribution workflow, including delivery zones and failed attempts.
- Issues invoices, records payments and credits, and shows management estimates of revenue and profit.
- Provides stock, expiry, sales, production and financial reports, plus notifications and audit records.
- Forecasts demand by product and supports a manager's production-planning decision.

Users have role-based access as administrators, managers, production staff, inventory staff, distribution staff or sales staff. Employee access is approved and managed by an administrator. Sign-in uses an emailed security code; password recovery also uses email verification.

## Main business workflow

1. A manager maintains product prices and approved manufacturing settings.
2. Production staff record a batch. The batch remains on quality hold until an authorised quality decision releases it.
3. Sales staff record a customer order. Confirmation reserves suitable stock; saving a draft does not remove stock.
4. Distribution staff dispatch the full order from its allocated batches and record the delivery outcome. Dispatch reduces physical stock once.
5. The system links delivered cases to an invoice. Payments are recorded against the invoice rather than treated as a second sale.
6. Managers review stock, expiry, financial reports and production recommendations. Batch links allow affected customers to be identified if stock is recalled.

Expired, rejected, quarantined and recalled cases are blocked from new dispatches. Stock adjustments and significant decisions are recorded for traceability.

## Demand forecasting

AquaTrack uses Python and scikit-learn. The application currently uses **Random Forest** for its seven-day product demand forecast and production recommendation. It also displays a 30-day estimate for longer-range review; only the valid seven-day forecast supports production-plan approval. Recommendations combine forecast demand with relevant confirmed orders, a safety allowance, eligible stock and unfinished approved production.

The research comparison evaluates a historical-demand benchmark, Random Forest and Gradient Boosting on chronological training, validation and later test periods. The comparison scripts and explanation are in [`analytics/EXPERIMENT_METHOD.md`](analytics/EXPERIMENT_METHOD.md), [`analytics/compare_product_demand.py`](analytics/compare_product_demand.py) and [`analytics/train_product_demand_colab.ipynb`](analytics/train_product_demand_colab.ipynb). Comparison scores for the raw models are **not** the same as scores for the application's deployed weekly procedure, which retrains and applies an error correction. The separate replay is implemented in [`analytics/evaluate_deployed_weekly.py`](analytics/evaluate_deployed_weekly.py).

All reported model results are based on synthetic data. They do not prove accuracy for a real water producer; genuine later orders would be needed for that assessment.

## Technology

- Laravel 13 and PHP 8.4 for the web application
- MySQL for operational records
- Bootstrap and Vite for the interface
- Python, NumPy and scikit-learn for forecasting
- PHPUnit and Python `unittest` for automated checks

## Set up a fresh local installation

You need PHP 8.4 with the extensions required by Laravel and MySQL, Composer, MySQL, Node.js/npm and Python. Use a **new empty database** for a new installation. The existing demonstration database should be backed up and handled separately.

1. Install dependencies and create a local environment file:

   ```powershell
   composer install
   npm install
   npm run build
   Copy-Item .env.example .env
   python -m pip install -r analytics/requirements.txt
   ```

2. In `.env`, set `APP_NAME=AquaTrack`, `APP_URL`, `BUSINESS_TIMEZONE=Africa/Nairobi`, and your own MySQL `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`. This project's XAMPP installation uses port `3307`; other installations may differ. Set `PYTHON_BINARY` if `python` is not on the command path. Configure a working email transport and verified sender before testing sign-in, invitations or password recovery.
3. For a **new empty database**, generate an application key and create the schema:

   ```powershell
   php artisan key:generate
   php artisan migrate
   ```

4. Start the application:

   ```powershell
   php artisan serve --host=127.0.0.1 --port=8000
   ```

   Open `http://127.0.0.1:8000`. An administrator must set up employee access. Do not publish demonstration passwords or security codes.

Do not run `db:seed`, `migrate:fresh` or a dataset replacement command against an existing or hosted database. The legacy seeder does not create the current packaged-water demonstration dataset. The current dataset import requires an isolated target and the checks described in the [dataset integration plan](database/packaged-data-next/REPLACEMENT_AND_INTEGRATION_PLAN.md).

## Demonstration data

The versioned synthetic dataset covers **1 January 2024 to 5 November 2026**. It includes daily product demand, closed Sundays, orders, batches, quality events, deliveries, invoices, payments and stock movements. Its assumptions and checks are documented in [`database/packaged-data-next/ASSUMPTIONS_AND_METHOD.md`](database/packaged-data-next/ASSUMPTIONS_AND_METHOD.md) and [`database/packaged-data-next/validate_dataset.py`](database/packaged-data-next/validate_dataset.py).

The CSV files are not loaded merely by running migrations. Import them only into a reviewed, backed-up demonstration database. Do not mix them with genuine operational records.

## Tests

Run these from the project directory after installing the dependencies:

```powershell
php artisan test
python -m unittest discover -s analytics -p "test_*.py"
python database/packaged-data-next/validate_dataset.py
```

The first two commands check Laravel and Python behaviour. The final command checks the synthetic dataset and its internal consistency. Retain results with the corresponding code and dataset version when presenting the project.

## Deployment and limitations

A public HTTPS deployment is **in preparation and testing**. This README does not claim that a hosted release is available yet. A live link should be shared only after the database, email, authentication, forecasting and main business workflows have been tested on the hosted service.

This system provides management estimates, not audited financial statements. Its synthetic dataset and model evaluations are suitable for demonstrating the implementation, not for claiming proven performance at a real company.
