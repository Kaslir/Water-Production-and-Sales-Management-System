# Synthetic dataset import

The six files in `database/data` describe synthetic operations from 1 January 2025 to 31 August 2026. They are not real company records.

From the Laravel project directory, run `php artisan dataset:replace` to validate the files without changing MySQL. Before applying a replacement, export a full backup of the configured database. Then run `php artisan dataset:replace --apply` and confirm the database name shown by the command.

The command validates CSV structure, IDs, customer references, dates, sale arithmetic, daily inventory balances, metadata totals, and the final stock balance before any database change. The replacement runs in one database transaction. It clears prior customers, production, sales, distributions, inventory movements, stock, forecasts, and generated reports. It leaves all existing login users, sessions, security codes, and settings intact. Three inactive users labelled `Synthetic Dataset` provide audit attribution for the imported production, sales, and distribution records. The daily summary is used for reconciliation and is not stored as another inventory movement.

Expected result: 25 customers, 608 production records, 4,278 sales, 261 distributions, 5,147 inventory movements, and 798,322 litres of closing stock. Total production is 4,122,049 litres, sales are 3,202,528 litres, non-sale distributions are 121,199 litres, and calculated sales value is KSh 113,039,840.

Do not use `php artisan db:seed` after importing: this project's default seeder creates the old short demonstration dataset. Do not use `php artisan migrate:fresh` on a database whose users or other records must be preserved. Re-running `dataset:replace --apply` deliberately replaces operational records again, including any manual changes made since the previous import.
