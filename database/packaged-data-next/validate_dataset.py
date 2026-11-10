"""Validate the versioned scenario without touching the Laravel database."""

import csv
import hashlib
import json
import sqlite3
import subprocess
import sys
import tempfile
import shutil
from collections import Counter, defaultdict
from datetime import date, timedelta
from decimal import Decimal
from pathlib import Path

ROOT = Path(__file__).resolve().parent
START, END = date(2024, 1, 1), date(2026, 11, 5)
TABLES = json.loads((ROOT / "dataset_metadata.json").read_text())["record_counts"]


def rows(name):
    with (ROOT / f"{name}.csv").open(newline="", encoding="utf-8") as stream:
        return list(csv.DictReader(stream))


def dec(value):
    return Decimal(value)


def validate():
    data = {name: rows(name) for name in TABLES}
    checks = {}

    def check(name, condition):
        checks[name] = bool(condition)

    products = {r["product_id"]: r for r in data["products"]}
    customers = {r["customer_id"]: r for r in data["customers"]}
    policies = {r["policy_id"]: r for r in data["product_manufacturing_policies"]}
    batches = {r["batch_id"]: r for r in data["production_batches"]}
    orders = {r["order_id"]: r for r in data["sales_orders"]}
    items = {r["order_item_id"]: r for r in data["sales_order_items"]}
    dispatches = {r["dispatch_id"]: r for r in data["dispatches"]}
    invoices = {r["invoice_id"]: r for r in data["invoices"]}
    trips = {r["trip_id"]: r for r in data["delivery_trips"]}
    date_rows = data["daily_product_demand"]
    days = (END - START).days + 1
    check("exact_date_and_product_coverage", len(products) == 4 and len(date_rows) == days * 4 and
          {(r["date"], r["product_id"]) for r in date_rows} ==
          {((START + timedelta(days=i)).isoformat(), pid) for i in range(days) for pid in products})
    date_fields = {"production_batches": "produced_on", "sales_orders": "ordered_on",
                   "delivery_trips": "delivery_on", "dispatches": "dispatched_on",
                   "invoices": "issued_on", "payments": "paid_on", "stock_movements": "movement_date",
                   "batch_quality_events": "event_date", "delivery_attempts": "attempted_on",
                   "batch_disposals": "disposed_on"}
    check("no_sunday_or_future_transactions", all(
        START <= date.fromisoformat(r[field]) <= END and date.fromisoformat(r[field]).weekday() != 6
        for table, field in date_fields.items() for r in data[table]))
    check("sunday_and_zero_order_labels", all(
        (r["day_type"] == "closed_sunday" and int(r["cases_ordered"]) == 0 and int(r["cases_requested"]) == 0)
        if date.fromisoformat(r["date"]).weekday() == 6 else
        (r["day_type"] == ("operating_with_orders" if int(r["cases_ordered"]) else "operating_zero_orders"))
        for r in date_rows))
    check("valid_references", all(r["customer_id"] in customers for r in orders.values()) and
          all(r["order_id"] in orders and r["product_id"] in products for r in items.values()) and
          all(r["product_id"] in products and r["policy_id"] in policies for r in batches.values()) and
          all(r["order_id"] in orders for r in dispatches.values()) and
          all(r["order_id"] in orders for r in invoices.values()) and
          all(r["invoice_id"] in invoices for r in data["payments"]) and
          all(r["batch_id"] in batches for r in data["batch_quality_events"]) and
          all(r["trip_id"] in trips and r["order_id"] in orders and r["dispatch_id"] in dispatches
              for r in data["delivery_attempts"]) and
          all(r["batch_id"] in batches and r["dispatch_id"] in dispatches and r["order_item_id"] in items
              for r in data["dispatch_lines"]))
    by_order = defaultdict(list)
    for r in items.values():
        by_order[r["order_id"]].append(r)
    fees = {"A": 800, "B": 1000, "C": 1200, "D": 1500, "E": 2000}
    check("order_totals_and_zone_fees", all(
        dec(o["delivery_fee_ksh"]) == fees[o["delivery_zone_code"]] == dec(customers[o["customer_id"]]["delivery_fee_ksh"])
        and dec(o["total_amount_ksh"]) == sum((dec(i["price_per_case_ksh"]) * int(i["cases_ordered"]) for i in by_order[oid]), Decimal(0))
            - dec(o["discount_ksh"]) + dec(o["delivery_fee_ksh"])
        and all(dec(i["line_total_ksh"]) == dec(i["price_per_case_ksh"]) * int(i["cases_ordered"]) for i in by_order[oid])
        for oid, o in orders.items()))
    cost_fields = ("water_treatment_per_case", "bottle_per_case", "inbound_transport_per_case",
                   "cap_label_per_case", "labour_per_case", "electricity_per_case", "other_production_per_case")
    check("batch_policy_expiry_and_cost", all(
        b["product_id"] == policies[b["policy_id"]]["product_id"] and
        policies[b["policy_id"]]["effective_from"] <= b["produced_on"] and
        b["expires_on"] == (date.fromisoformat(b["produced_on"]) + timedelta(days=int(policies[b["policy_id"]]["shelf_life_days"]))).isoformat() and
        dec(b["total_cost_ksh"]) == sum((dec(policies[b["policy_id"]][f]) for f in cost_fields), Decimal(0)) * int(b["cases_produced"]) and
        (b["status"] != "released" or int(b["cases_produced"]) == int(b["saleable_cases"]) + int(b["quality_rejected_cases"]))
        for b in batches.values()))
    events_by_batch = defaultdict(list)
    for event in data["batch_quality_events"]:
        events_by_batch[event["batch_id"]].append(event)
    allowed_transitions = {("", "quality_hold"), ("quality_hold", "released"),
                           ("quality_hold", "quarantined"), ("released", "recalled")}
    check("quality_exception_statuses_and_events", {b["status"] for b in batches.values()} >=
          {"released", "quality_hold", "quarantined", "recalled"} and all(
              events_by_batch[bid] and events_by_batch[bid][-1]["new_status"] == b["status"] and
              all((e["previous_status"], e["new_status"]) in allowed_transitions and
                  (index == 0 or events_by_batch[bid][index-1]["new_status"] == e["previous_status"])
                  for index, e in enumerate(events_by_batch[bid]))
              for bid, b in batches.items()))
    by_batch = Counter()
    for m in data["stock_movements"]:
        by_batch[m["batch_id"]] += int(m["signed_cases"])
    check("stock_movement_reconciliation", all(by_batch[bid] == int(b["cases_on_hand"]) >= 0 for bid, b in batches.items()))
    disposal_by_batch = Counter()
    for disposal in data["batch_disposals"]:
        disposal_by_batch[disposal["batch_id"]] += int(disposal["cases"])
    check("expiry_disposal_documented", bool(data["batch_disposals"]) and all(
          date.fromisoformat(r["disposed_on"]) >= date.fromisoformat(batches[r["batch_id"]]["expires_on"])
          and r["batch_id"] in batches and int(r["cases"]) > 0 for r in data["batch_disposals"]) and
          all(disposal_by_batch[bid] == -sum(int(m["signed_cases"]) for m in data["stock_movements"]
              if m["batch_id"] == bid and m["reason"] == "disposal") for bid in disposal_by_batch))
    running_stock = Counter()
    fefo_ok = True
    for m in data["stock_movements"]:
        bid = m["batch_id"]
        batch = batches[bid]
        if m["reason"] == "customer_dispatch":
            earlier = [other for other_id, other in batches.items()
                       if other["product_id"] == batch["product_id"] and other["status"] == "released"
                       and other["produced_on"] <= m["movement_date"] < other["expires_on"]
                       and (other["expires_on"], int(other_id)) < (batch["expires_on"], int(bid))
                       and running_stock[other_id] > 0]
            if earlier or running_stock[bid] < -int(m["signed_cases"]):
                fefo_ok = False
        running_stock[bid] += int(m["signed_cases"])
        if running_stock[bid] < 0:
            fefo_ok = False
    check("fefo_and_nonnegative_stock_at_each_movement", fefo_ok)
    lines_by_item = Counter()
    lines_by_batch = Counter()
    for line in data["dispatch_lines"]:
        lines_by_item[line["order_item_id"]] += int(line["cases"])
        lines_by_batch[line["batch_id"]] += int(line["cases"])
    dispatched_ids = {d["order_id"] for d in dispatches.values()}
    delivered_ids = {d["order_id"] for d in dispatches.values() if d["status"] == "delivered"}
    check("full_order_dispatch_and_batch_eligibility", all(
        (lines_by_item[iid] == int(i["cases_ordered"]) if i["order_id"] in dispatched_ids else lines_by_item[iid] == 0)
        for iid, i in items.items()) and all(
        batches[l["batch_id"]]["status"] == "released" and
        batches[l["batch_id"]]["produced_on"] <= dispatches[l["dispatch_id"]]["dispatched_on"] < batches[l["batch_id"]]["expires_on"] and
        batches[l["batch_id"]]["product_id"] == l["product_id"] == items[l["order_item_id"]]["product_id"] and
        dispatches[l["dispatch_id"]]["order_id"] == items[l["order_item_id"]]["order_id"]
        for l in data["dispatch_lines"]))
    payment_totals = defaultdict(Decimal)
    for payment in data["payments"]:
        payment_totals[payment["invoice_id"]] += dec(payment["amount_ksh"])
    check("invoice_and_payment_consistency", all(
        inv["order_id"] in delivered_ids and dec(inv["amount_ksh"]) == dec(orders[inv["order_id"]]["total_amount_ksh"])
        for inv in invoices.values()) and all(
        payment_totals[iid] <= dec(inv["amount_ksh"])
        for iid, inv in invoices.items()))
    trip_assignments = data["delivery_trip_orders"]
    check("one_truck_no_overlapping_trips", all(t["status"] in ("completed", "dispatched") for t in trips.values()) and
          sum(t["status"] == "dispatched" for t in trips.values()) == 1 and
          len({t["delivery_on"] for t in trips.values()}) == len(trips) and
          len({r["order_id"] for r in trip_assignments}) == len(trip_assignments) and
          {r["order_id"] for r in trip_assignments} == dispatched_ids and
          all(r["trip_id"] in trips and trips[r["trip_id"]]["delivery_on"] == orders[r["order_id"]]["ordered_on"]
              for r in trip_assignments))
    attempts_by_dispatch = defaultdict(list)
    for attempt in data["delivery_attempts"]:
        attempts_by_dispatch[attempt["dispatch_id"]].append(attempt)
    check("failed_delivery_attempts_traced_without_double_billing", bool(data["delivery_attempts"]) and
          any(d["status"] == "dispatched" for d in dispatches.values()) and
          all(a["attempt_status"] in ("failed", "delivered") and
              a["order_id"] == dispatches[a["dispatch_id"]]["order_id"] for a in data["delivery_attempts"]) and
          all(any(a["attempt_status"] == "failed" for a in attempts_by_dispatch[did]) and
              (d["status"] == "dispatched" and not any(a["attempt_status"] == "delivered" for a in attempts_by_dispatch[did])
               or d["status"] == "delivered" and any(a["attempt_status"] == "delivered" for a in attempts_by_dispatch[did]))
              for did, d in dispatches.items() if did in attempts_by_dispatch) and
          all(d["order_id"] not in {inv["order_id"] for inv in invoices.values()} for d in dispatches.values()
              if d["status"] == "dispatched"))
    expected = Counter()
    requested = Counter()
    for i in items.values():
        key = (orders[i["order_id"]]["ordered_on"], i["product_id"])
        requested[key] += int(i["cases_ordered"])
        if orders[i["order_id"]]["status"] in ("delivered", "dispatched", "invoiced", "paid"):
            expected[key] += int(i["cases_ordered"])
    check("ml_labels_derived_from_orders", all(
        int(r["cases_ordered"]) == expected[r["date"], r["product_id"]] and
        int(r["cases_requested"]) == requested[r["date"], r["product_id"]]
        for r in date_rows))
    closure_dates = {r["order_day"] for r in data["daily_order_closures"]}
    positive_days = {r["date"] for r in date_rows if int(r["cases_ordered"]) > 0}
    check("zero_order_closures", closure_dates == {r["date"] for r in date_rows
          if r["day_type"] == "operating_zero_orders" and
          r["date"] not in positive_days})
    meta = json.loads((ROOT / "dataset_metadata.json").read_text())
    check("metadata_counts_and_hashes", all(len(data[n]) == c and
          hashlib.sha256((ROOT / f"{n}.csv").read_bytes()).hexdigest() == meta["sha256"][n]
          for n, c in meta["record_counts"].items()))
    with tempfile.TemporaryDirectory(prefix="aquatrack_repro_", dir=ROOT) as tmp:
        if not Path(tmp).resolve().is_relative_to(ROOT.resolve()):
            raise RuntimeError("Reproducibility directory escaped the dataset workspace")
        temp_script = Path(tmp) / "generate_dataset.py"
        shutil.copy2(ROOT / "generate_dataset.py", temp_script)
        regenerate = subprocess.run([sys.executable, str(temp_script)], capture_output=True, text=True)
        check("fixed_seed_reproduces_identical_csv_hashes", regenerate.returncode == 0 and all(
            hashlib.sha256((Path(tmp) / f"{name}.csv").read_bytes()).hexdigest() == digest
            for name, digest in meta["sha256"].items()))
    # The SQLite load is an isolated relational test; it never uses AquaTrack's MySQL connection.
    con = sqlite3.connect(":memory:")
    con.execute("PRAGMA foreign_keys = ON")
    con.executescript("""CREATE TABLE products(id TEXT PRIMARY KEY);
      CREATE TABLE customers(id TEXT PRIMARY KEY);
      CREATE TABLE batches(id TEXT PRIMARY KEY, product_id TEXT REFERENCES products(id));
      CREATE TABLE orders(id TEXT PRIMARY KEY, customer_id TEXT REFERENCES customers(id));
      CREATE TABLE items(id TEXT PRIMARY KEY, order_id TEXT REFERENCES orders(id), product_id TEXT REFERENCES products(id));
      CREATE TABLE dispatches(id TEXT PRIMARY KEY, order_id TEXT REFERENCES orders(id));
      CREATE TABLE dispatch_lines(id TEXT PRIMARY KEY, dispatch_id TEXT REFERENCES dispatches(id),
        item_id TEXT REFERENCES items(id), batch_id TEXT REFERENCES batches(id));
      CREATE TABLE invoices(id TEXT PRIMARY KEY, order_id TEXT REFERENCES orders(id));
      CREATE TABLE payments(id TEXT PRIMARY KEY, invoice_id TEXT REFERENCES invoices(id));""")
    try:
        for table, key, source, parent in (
            ("products", "product_id", "products", None), ("customers", "customer_id", "customers", None),
            ("batches", "batch_id", "production_batches", "product_id"),
            ("orders", "order_id", "sales_orders", "customer_id")):
            if parent:
                con.executemany(f"INSERT INTO {table} VALUES (?,?)", [(r[key], r[parent]) for r in data[source]])
            else:
                con.executemany(f"INSERT INTO {table} VALUES (?)", [(r[key],) for r in data[source]])
        con.executemany("INSERT INTO items VALUES (?,?,?)", [(r["order_item_id"], r["order_id"], r["product_id"]) for r in items.values()])
        con.executemany("INSERT INTO dispatches VALUES (?,?)", [(r["dispatch_id"], r["order_id"]) for r in dispatches.values()])
        con.executemany("INSERT INTO dispatch_lines VALUES (?,?,?,?)", [(r["dispatch_line_id"], r["dispatch_id"], r["order_item_id"], r["batch_id"]) for r in data["dispatch_lines"]])
        con.executemany("INSERT INTO invoices VALUES (?,?)", [(r["invoice_id"], r["order_id"]) for r in invoices.values()])
        con.executemany("INSERT INTO payments VALUES (?,?)", [(r["payment_id"], r["invoice_id"]) for r in data["payments"]])
        check("isolated_relational_load", con.execute("PRAGMA foreign_key_check").fetchall() == [] and
              con.execute("SELECT COUNT(*) FROM orders").fetchone()[0] == len(orders))
    except sqlite3.IntegrityError:
        check("isolated_relational_load", False)
    finally:
        con.close()
    report = {"dataset": meta["dataset_name"], "record_counts": meta["record_counts"],
              "checks": checks, "passed": sum(checks.values()), "total": len(checks)}
    (ROOT / "validation_report.json").write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2))
    return all(checks.values())


if __name__ == "__main__":
    sys.exit(0 if validate() else 1)
