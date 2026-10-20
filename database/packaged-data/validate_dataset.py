import csv
import json
from collections import Counter, defaultdict
from datetime import date, timedelta
from decimal import Decimal
from pathlib import Path


folder = Path(__file__).resolve().parent
metadata = json.loads((folder / 'dataset_metadata.json').read_text(encoding='utf-8'))


def read(name):
    with (folder / f'{name}.csv').open(newline='', encoding='utf-8') as handle:
        return list(csv.DictReader(handle))


tables = {name: read(name) for name in metadata['record_counts']}
for name, expected in metadata['record_counts'].items():
    assert len(tables[name]) == expected, (name, len(tables[name]), expected)

products = {int(row['product_id']): row for row in tables['products']}
customers = {int(row['customer_id']): row for row in tables['customers']}
orders = {int(row['order_id']): row for row in tables['sales_orders']}
items = {int(row['order_item_id']): row for row in tables['sales_order_items']}
batches = {int(row['batch_id']): row for row in tables['production_batches']}
dispatches = {int(row['dispatch_id']): row for row in tables['dispatches']}
invoices = {int(row['invoice_id']): row for row in tables['invoices']}

assert len(products) == 4 and {int(row['volume_ml']) for row in products.values()} == {500, 1000, 5000, 20000}
assert len(customers) == 24 and all(row['phone_number'].startswith('07') and len(row['phone_number']) == 10 for row in customers.values())
assert len(orders) == len(set(orders)) and len(items) == len(set(items)) and len(batches) == len(set(batches))

start = date.fromisoformat(metadata['period_start'])
end = date.fromisoformat(metadata['period_end'])
assert start == date(2024, 1, 1) and end == date(2026, 9, 30)
assert (end - start).days + 1 == metadata['days'] == 1004

demand = {(row['date'], int(row['product_id'])): int(row['cases_ordered']) for row in tables['daily_product_demand']}
assert len(demand) == metadata['days'] * len(products)
for offset in range(metadata['days']):
    day = (start + timedelta(days=offset)).isoformat()
    for product in products:
        assert (day, product) in demand

order_totals = defaultdict(Decimal)
derived_demand = Counter()
for item_id, item in items.items():
    order = orders[int(item['order_id'])]
    product_id = int(item['product_id'])
    cases = int(item['cases_ordered'])
    assert product_id in products and cases > 0
    assert Decimal(item['price_per_case_ksh']) * cases == Decimal(item['line_total_ksh'])
    order_totals[int(item['order_id'])] += Decimal(item['line_total_ksh'])
    derived_demand[(order['ordered_on'], product_id)] += cases
assert derived_demand == Counter({key: value for key, value in demand.items() if value})
for order_id, order in orders.items():
    assert int(order['customer_id']) in customers
    assert start.isoformat() <= order['ordered_on'] <= end.isoformat()
    assert order_totals[order_id] - Decimal(order['discount_ksh']) == Decimal(order['total_amount_ksh'])

dispatched_per_item = Counter()
batch_debits = Counter()
for line in tables['dispatch_lines']:
    item = items[int(line['order_item_id'])]
    dispatch = dispatches[int(line['dispatch_id'])]
    batch = batches[int(line['batch_id'])]
    cases = int(line['cases'])
    assert cases > 0
    assert int(item['order_id']) == int(dispatch['order_id'])
    assert item['product_id'] == batch['product_id'] == line['product_id']
    assert batch['produced_on'] <= dispatch['dispatched_on'] < batch['expires_on']
    assert Decimal(line['cost_per_case_ksh']) == Decimal(batch['cost_per_saleable_case_ksh'])
    dispatched_per_item[int(line['order_item_id'])] += cases
    batch_debits[int(line['batch_id'])] += cases
for item_id, item in items.items():
    expected = 0 if orders[int(item['order_id'])]['status'] == 'confirmed' else int(item['cases_ordered'])
    assert dispatched_per_item[item_id] == expected

disposed_per_batch = Counter()
for disposal in tables['batch_disposals']:
    batch = batches[int(disposal['batch_id'])]
    assert disposal['disposed_on'] >= batch['expires_on']
    disposed_per_batch[int(disposal['batch_id'])] += int(disposal['cases'])
for batch_id, batch in batches.items():
    produced = int(batch['cases_produced'])
    rejected = int(batch['quality_rejected_cases'])
    saleable = int(batch['saleable_cases'])
    assert produced - rejected == saleable > 0
    assert saleable - batch_debits[batch_id] - disposed_per_batch[batch_id] == int(batch['cases_on_hand']) >= 0
    assert Decimal(batch['total_cost_ksh']) > 0 and Decimal(batch['cost_per_saleable_case_ksh']) > 0

movement_balance = Counter()
for movement in tables['stock_movements']:
    movement_balance[int(movement['batch_id'])] += int(movement['signed_cases'])
for batch_id, batch in batches.items():
    assert movement_balance[batch_id] == int(batch['cases_on_hand'])

invoiced_orders = set()
for invoice in invoices.values():
    order_id = int(invoice['order_id'])
    assert orders[order_id]['status'] == 'delivered'
    assert Decimal(invoice['amount_ksh']) == Decimal(orders[order_id]['total_amount_ksh'])
    invoiced_orders.add(order_id)
assert invoiced_orders == {order_id for order_id, order in orders.items() if order['status'] == 'delivered'}
paid_by_invoice = defaultdict(Decimal)
for payment in tables['payments']:
    paid_by_invoice[int(payment['invoice_id'])] += Decimal(payment['amount_ksh'])
for invoice_id, invoice in invoices.items():
    due = Decimal(invoice['amount_ksh'])
    assert Decimal('0') <= paid_by_invoice[invoice_id] <= due
    assert (invoice['status'] == 'paid') == (paid_by_invoice[invoice_id] == due)

print(json.dumps({
    'result': 'passed', 'period': [metadata['period_start'], metadata['period_end']],
    'months': metadata['months'], 'days': metadata['days'], 'products': len(products),
    'orders': len(orders), 'order_items': len(items), 'batches': len(batches),
    'expired_disposals': len(tables['batch_disposals']), 'daily_product_observations': len(demand),
}, indent=2))
