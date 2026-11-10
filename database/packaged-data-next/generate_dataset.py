"""Generate a reproducible, fictional Nairobi bottled-water operations scenario.

This generator never connects to AquaTrack's database. All dates, demand and
financial records are derived from the same event simulation and fixed seed.
"""

import csv
import hashlib
import json
import math
import random
from collections import Counter, defaultdict
from datetime import date, timedelta
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path


ROOT = Path(__file__).resolve().parent
START = date(2024, 1, 1)
END = date(2026, 11, 5)
SEED = 20261105
RNG = random.Random(SEED)
CENT = Decimal("0.01")
PRODUCTS = [
    (1, "WATER-500ML", "500 mL bottled water", 500, 24, Decimal("620.00"), Decimal("405.00")),
    (2, "WATER-1L", "1 L bottled water", 1000, 12, Decimal("540.00"), Decimal("350.00")),
    (3, "WATER-5L", "5 L bottled water", 5000, 4, Decimal("720.00"), Decimal("470.00")),
    (4, "WATER-20L", "20 L bottled water", 20000, 1, Decimal("300.00"), Decimal("185.00")),
]
AREAS = [
    ("Industrial Area", "A", 800), ("South B", "A", 800),
    ("Nairobi CBD", "B", 1000), ("Eastleigh", "B", 1000),
    ("Westlands", "C", 1200), ("Parklands", "C", 1200),
    ("Kilimani", "D", 1500), ("Lavington", "D", 1500),
    ("Karen", "E", 2000), ("Runda", "E", 2000),
]
NAMES = [
    ("Amani Retail Shop", "retail"), ("Baraka Restaurant", "restaurant"),
    ("Blue Nile Hotel", "hotel"), ("Clear Springs School", "school"),
    ("Green Valley Hospital", "hospital"), ("Horizon Office", "office"),
    ("Juja Residential Estate", "residential"), ("Karibu Supermarket", "supermarket"),
    ("Lakeview Retail Shop", "retail"), ("Mwangaza Restaurant", "restaurant"),
    ("Nairobi Hotel", "hotel"), ("New Hope School", "school"),
    ("Oakridge Office", "office"), ("Pamoja Hospital", "hospital"),
    ("Riverside Supermarket", "supermarket"), ("Sunrise Residential Estate", "residential"),
    ("Tulia Guest House", "hotel"), ("Ufanisi Retail Shop", "retail"),
    ("Valley View Restaurant", "restaurant"), ("West Park Office", "office"),
    ("Yetu Supermarket", "supermarket"), ("Zawadi School", "school"),
    ("Acacia Residential Estate", "residential"), ("Beacon Clinic", "hospital"),
    ("Cedar Retail Shop", "retail"), ("Diani Foods Nairobi", "restaurant"),
    ("Elm Business Centre", "office"), ("Fairview Hotel", "hotel"),
    ("Garden City Mini Mart", "retail"), ("Hilltop School", "school"),
    ("Ivy Residential Estate", "residential"), ("Jasiri Supermarket", "supermarket"),
]
BUY_PROB = {"retail": .48, "restaurant": .38, "hotel": .34, "school": .28,
            "office": .30, "hospital": .26, "residential": .18, "supermarket": .43}
BASE_CASES = {"retail": 8, "restaurant": 11, "hotel": 15, "school": 13,
              "office": 10, "hospital": 14, "residential": 18, "supermarket": 19}
PRODUCT_WEIGHTS = {
    "retail": (5, 3, 1, .5), "restaurant": (3, 3, 1, 1),
    "hotel": (3, 3, 2, 1), "school": (4, 3, 2, .3),
    "office": (3, 4, 1, 2), "hospital": (2, 2, 3, 2),
    "residential": (2, 2, 2, 5), "supermarket": (5, 4, 2, 1),
}
SEASON = {1: 1.12, 2: 1.17, 3: 1.09, 4: .94, 5: .89, 6: .94,
          7: .98, 8: 1.04, 9: 1.10, 10: 1.08, 11: 1.02, 12: 1.13}


def money(value):
    return str(Decimal(str(value)).quantize(CENT, rounding=ROUND_HALF_UP))


def write(name, rows):
    empty_headers = {"batch_disposals": ["disposal_id", "batch_id", "cases", "reason", "disposed_on"]}
    fieldnames = list(rows[0]) if rows else empty_headers.get(name)
    if not fieldnames:
        raise ValueError(f"No {name} rows or schema")
    with (ROOT / f"{name}.csv").open("w", newline="", encoding="utf-8") as stream:
        writer = csv.DictWriter(stream, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)


def is_term(day):
    # Documented synthetic school-term proxy, not an observed school calendar.
    return day.month in (1, 2, 3, 5, 6, 7, 9, 10, 11)


def business_factor(day):
    age = (day - START).days / (END - START).days
    growth = 1 + .13 * age + .035 * math.sin(2 * math.pi * age * 1.7)
    temporary_slowdown = .91 if date(2025, 4, 1) <= day <= date(2025, 5, 31) else 1
    return SEASON[day.month] * growth * temporary_slowdown


def customer_quantity(customer, product_id, day):
    category = customer["category"]
    frequency = customer["frequency_factor"]
    weekday = day.weekday()
    probability = BUY_PROB[category] * frequency
    if weekday == 5:
        probability *= {"office": .22, "school": .12, "restaurant": 1.35,
                        "retail": 1.22, "supermarket": 1.28}.get(category, .85)
    elif weekday == 4 and category in ("retail", "restaurant", "supermarket"):
        probability *= 1.18
    if category == "school" and not is_term(day):
        probability *= .27
    if RNG.random() > min(.90, probability):
        return 0
    weight = PRODUCT_WEIGHTS[category][product_id - 1]
    month_factor = business_factor(day)
    if product_id == 4 and category in ("residential", "office") and day.month in (1, 2):
        month_factor *= 1.18
    if product_id == 1 and category in ("retail", "school") and day.month == 12:
        month_factor *= 1.18
    if category == "school" and not is_term(day):
        month_factor *= .28
    if weekday == 5 and category in ("restaurant", "retail", "supermarket"):
        month_factor *= 1.12
    expected = BASE_CASES[category] * customer["size_factor"] * weight / sum(PRODUCT_WEIGHTS[category]) * month_factor
    noisy = expected * RNG.uniform(.64, 1.38)
    if RNG.random() < .008:
        noisy *= RNG.uniform(1.7, 2.5)  # Rare event or large institutional order.
    return max(1, round(noisy))


def ordering_day(customer, day):
    # A customer first decides whether to order today; product choice is separate.
    cadence = customer["ordering_cadence"]
    weekday = day.weekday()
    offset = int(customer["cycle_offset"])
    if cadence == "daily":
        return True
    if cadence == "several_per_week":
        return RNG.random() < .68
    if cadence == "weekly":
        return weekday == offset
    if cadence == "fortnightly":
        return weekday == offset and ((day - START).days // 7) % 2 == customer["customer_id"] % 2
    return RNG.random() < .13


def generate():
    products = [{"product_id": p[0], "sku": p[1], "name": p[2], "volume_ml": p[3],
                 "bottles_per_case": p[4], "price_per_case_ksh": money(p[5])} for p in PRODUCTS]
    customers = []
    for customer_id, (name, category) in enumerate(NAMES, 1):
        area, zone, fee = AREAS[(customer_id - 1) % len(AREAS)]
        cadence = ("daily", "several_per_week", "several_per_week", "weekly",
                   "weekly", "fortnightly", "fortnightly", "occasional")[(customer_id - 1) % 8]
        customers.append({"customer_id": customer_id, "name": name, "category": category,
                          "phone_number": f"07{10000000 + customer_id:08d}",
                          "email": f"customer{customer_id}@example.invalid", "address": f"{area}, Nairobi",
                          "delivery_area": area, "delivery_zone_code": zone, "delivery_fee_ksh": money(fee),
                          "ordering_cadence": cadence, "cycle_offset": (customer_id * 3) % 6,
                          "frequency_factor": round(RNG.uniform(.65, 1.35), 4),
                          "size_factor": round(RNG.uniform(.70, 1.35), 4)})
    policies = []
    for product in PRODUCTS:
        for version, effective in enumerate((date(2024, 1, 1), date(2025, 7, 1), date(2026, 7, 1)), 1):
            base = product[6] * Decimal(str(1 + .045 * (version - 1)))
            parts = [base * Decimal(str(x)) for x in (.08, .48, .05, .12, .14, .08)]
            parts = [Decimal(money(x)) for x in parts]
            policies.append({"policy_id": (product[0] - 1) * 3 + version,
                             "product_id": product[0], "effective_from": effective.isoformat(),
                             "shelf_life_days": 180,
                             "shelf_life_evidence": "Synthetic scenario assumption only; not a certified shelf life",
                             "water_treatment_per_case": money(parts[0]), "bottle_per_case": money(parts[1]),
                             "inbound_transport_per_case": money(parts[2]), "cap_label_per_case": money(parts[3]),
                             "labour_per_case": money(parts[4]), "electricity_per_case": money(parts[5]),
                             "other_production_per_case": money(base - sum(parts))})
    policy_by_product = defaultdict(list)
    for policy in policies:
        policy_by_product[policy["product_id"]].append(policy)

    batches, orders, items, trips, trip_orders = [], [], [], [], []
    dispatches, lines, invoices, payments, movements, disposals, daily, closures = [], [], [], [], [], [], [], []
    quality_events, delivery_attempts = [], []
    stock = defaultdict(list)
    demand_history = defaultdict(list)
    next_id = Counter()

    def number(key):
        next_id[key] += 1
        return next_id[key]

    def movement(batch, signed, reason, reference_type, reference_id, day):
        movements.append({"movement_id": number("movement"), "batch_id": batch["batch_id"],
                          "signed_cases": signed, "reason": reason, "reference_type": reference_type,
                          "reference_id": reference_id, "movement_date": day.isoformat()})

    def quality_event(batch, old_status, new_status, day, reason):
        quality_events.append({"quality_event_id": number("quality_event"), "batch_id": batch["batch_id"],
            "event_date": day.isoformat(), "previous_status": old_status, "new_status": new_status,
            "reason": reason, "evidence_reference": f"SIM-QA-EVENT-{len(quality_events) + 1:06d}"})
        batch["status"] = new_status
        batch["quality_notes"] = reason

    def produce(product_id, day, saleable, quality_hold=False):
        policy = max((p for p in policy_by_product[product_id] if p["effective_from"] <= day.isoformat()),
                     key=lambda p: p["effective_from"])
        rejected = 0 if quality_hold else (max(1, round(saleable * .01)) if RNG.random() < .055 else 0)
        produced = saleable + rejected
        unit = sum(Decimal(policy[k]) for k in ("water_treatment_per_case", "bottle_per_case",
            "inbound_transport_per_case", "cap_label_per_case", "labour_per_case",
            "electricity_per_case", "other_production_per_case"))
        total = unit * produced
        batch_id = number("batch")
        batch = {"batch_id": batch_id, "batch_code": f"B-{day:%Y%m%d}-{product_id}-{batch_id:05d}",
                 "product_id": product_id, "policy_id": policy["policy_id"], "produced_on": day.isoformat(),
                 "expires_on": (day + timedelta(days=int(policy["shelf_life_days"]))).isoformat(),
                 "cases_produced": produced, "quality_rejected_cases": rejected,
                 "saleable_cases": saleable if not quality_hold else 0,
                 "cases_on_hand": saleable if not quality_hold else produced,
                 "status": "quality_hold" if quality_hold else "released",
                 "total_cost_ksh": money(total),
                 "cost_per_saleable_case_ksh": money(total / saleable) if not quality_hold else money(unit),
                 "quality_notes": "Awaiting laboratory release" if quality_hold else "Synthetic quality evidence: passed",
                 "quality_evidence_reference": "" if quality_hold else f"SIM-QA-{batch_id:06d}",
                 "water_source_reference": f"SIM-MUNICIPAL-{day:%Y%m%d}",
                 "water_input_litres": money(produced * PRODUCTS[product_id - 1][3] * PRODUCTS[product_id - 1][4] / 1000 * 1.025),
                 "treatment_log_reference": f"SIM-TREAT-{day:%Y%m%d}"}
        batches.append(batch)
        movement(batch, produced, "production", "ProductionBatch", batch_id, day)
        quality_event(batch, "", "quality_hold", day, "Batch recorded on quality hold")
        if rejected:
            movement(batch, -rejected, "quality_rejection", "ProductionBatch", batch_id, day)
        if not quality_hold:
            quality_event(batch, "quality_hold", "released", day, "Synthetic quality check passed")
            stock[product_id].append(batch)
        return batch

    current = START
    while current <= END:
        if current.weekday() == 6:
            for product in PRODUCTS:
                daily.append({"date": current.isoformat(), "product_id": product[0], "sku": product[1],
                              "day_type": "closed_sunday", "cases_ordered": 0, "cases_requested": 0,
                              "bottles_ordered": 0, "litres_ordered": 0})
            current += timedelta(days=1)
            continue

        # Explicit rare quality exceptions; these cases never enter saleable stock.
        if current == date(2024, 1, 2):
            batch = produce(3, current, 6, quality_hold=True)
            quality_event(batch, "quality_hold", "quarantined", current,
                          "Synthetic failed microbiological check; retained for disposal")
        if current == END:
            produce(1, current, 12, quality_hold=True)
            batch = produce(2, current, 8, quality_hold=True)
            quality_event(batch, "quality_hold", "quarantined", current,
                          "Synthetic failed microbiological check; investigation required")
            batch = produce(4, current, 5, quality_hold=True)
            quality_event(batch, "quality_hold", "released", current,
                          "Synthetic quality check passed before packaging concern")
            batch["quality_evidence_reference"] = f"SIM-QA-{batch['batch_id']:06d}"
            quality_event(batch, "released", "recalled", current,
                          "Synthetic packaging-integrity concern; no cases dispatched")

        for product in PRODUCTS:
            product_id = product[0]
            for batch in batches:
                if batch["product_id"] != product_id:
                    continue
                if batch["cases_on_hand"] and batch["expires_on"] <= current.isoformat():
                    cases = batch["cases_on_hand"]
                    batch["cases_on_hand"] = 0
                    disposal_id = number("disposal")
                    disposals.append({"disposal_id": disposal_id, "batch_id": batch["batch_id"],
                                      "cases": cases, "reason": "Expired stock disposed; synthetic scenario",
                                      "disposed_on": current.isoformat()})
                    movement(batch, -cases, "disposal", "BatchDisposal", disposal_id, current)
            if current.weekday() in (0, 3):
                history = demand_history[product_id][-28:]
                weekly_rate = (sum(history) / max(1, len(history))) * (4 if current.weekday() == 0 else 3)
                if len(history) < 14:
                    weekly_rate = {1: 170, 2: 125, 3: 82, 4: 38}[product_id]
                target = round(weekly_rate * 1.24 + 45)
                available = sum(b["cases_on_hand"] for b in stock[product_id]
                                if b["expires_on"] > current.isoformat())
                needed = max(0, target - available)
                if needed:
                    if RNG.random() < .018:
                        needed = max(1, round(needed * .34))  # Rare equipment disruption.
                    produce(product_id, current, min(500, needed))

        accepted = Counter()
        requested = Counter()
        committed = Counter()
        day_orders = []
        # Rare zero-order operating day, separately recorded from a closed Sunday.
        zero_order_day = RNG.random() < .007
        if not zero_order_day:
            for customer in customers:
                if not ordering_day(customer, current):
                    continue
                quantities = {p[0]: customer_quantity(customer, p[0], current) for p in PRODUCTS}
                quantities = {product_id: qty for product_id, qty in quantities.items() if qty}
                if not quantities:
                    continue
                order_id = number("order")
                cancelled = RNG.random() < .004
                enough = all(sum(b["cases_on_hand"] for b in stock[pid] if b["expires_on"] > current.isoformat()) - committed[pid] >= qty
                             for pid, qty in quantities.items())
                status = "cancelled" if cancelled else ("delivered" if enough else "draft")
                subtotal = Decimal("0.00")
                order_item_rows = []
                for product_id, qty in quantities.items():
                    requested[product_id] += qty
                    base_price = PRODUCTS[product_id - 1][5]
                    historical_factor = Decimal("0.95") if current < date(2025, 1, 1) else (Decimal("0.98") if current < date(2026, 1, 1) else Decimal("1"))
                    price = Decimal(money(base_price * historical_factor))
                    line_total = price * qty
                    subtotal += line_total
                    order_item_rows.append({"order_item_id": number("item"), "order_id": order_id,
                        "product_id": product_id, "cases_ordered": qty, "price_per_case_ksh": money(price),
                        "line_total_ksh": money(line_total)})
                discount = Decimal("0.00")
                fee = Decimal(customer["delivery_fee_ksh"])
                orders.append({"order_id": order_id, "order_number": f"SO-{order_id:07d}",
                    "customer_id": customer["customer_id"], "ordered_on": current.isoformat(),
                    "requested_delivery_on": current.isoformat(), "status": status,
                    "delivery_address": customer["address"], "delivery_area": customer["delivery_area"],
                    "delivery_zone_code": customer["delivery_zone_code"], "delivery_fee_ksh": money(fee),
                    "discount_ksh": money(discount), "total_amount_ksh": money(subtotal - discount + fee),
                    "data_origin": "synthetic"})
                items.extend(order_item_rows)
                if status == "delivered":
                    for product_id, qty in quantities.items():
                        accepted[product_id] += qty
                        committed[product_id] += qty
                    day_orders.append((orders[-1], order_item_rows))

        if not day_orders:
            closures.append({"order_day": current.isoformat(), "reason": "No accepted customer orders on operating day"})

        if day_orders:
            trip_id = number("trip")
            zone_count = len({order["delivery_zone_code"] for order, _ in day_orders})
            fuel = Decimal(550 + 110 * len(day_orders) + 90 * zone_count)
            other = Decimal(40 * len(day_orders))
            trips.append({"trip_id": trip_id, "trip_reference": f"DT-{trip_id:06d}",
                "delivery_on": current.isoformat(), "status": "completed",
                "fuel_expense_ksh": money(fuel), "other_expense_ksh": money(other),
                "expense_reference": f"SIM-TRIP-{trip_id:06d}"})
            for stop_index, (order, order_items) in enumerate(day_orders):
                order_id = order["order_id"]
                trip_orders.append({"trip_id": trip_id, "order_id": order_id})
                dispatch_id = number("dispatch")
                unresolved_attempt = current == END and stop_index == 0
                retry_attempt = not unresolved_attempt and RNG.random() < .004
                if unresolved_attempt:
                    order["status"] = "dispatched"
                    trips[-1]["status"] = "dispatched"
                dispatches.append({"dispatch_id": dispatch_id, "order_id": order_id,
                    "dispatched_on": current.isoformat(), "delivered_on": "" if unresolved_attempt else current.isoformat(),
                    "status": "dispatched" if unresolved_attempt else "delivered",
                    "delivery_reference": f"SIM-DEL-{dispatch_id:07d}",
                    "received_by": "" if unresolved_attempt else "Synthetic receiving contact",
                    "receipt_reference": "" if unresolved_attempt else f"SIM-POD-{dispatch_id:07d}"})
                if unresolved_attempt or retry_attempt:
                    delivery_attempts.append({"attempt_id": number("attempt"), "trip_id": trip_id,
                        "order_id": order_id, "dispatch_id": dispatch_id, "attempted_on": current.isoformat(),
                        "attempt_status": "failed", "reason": "Recipient unavailable at delivery address",
                        "resolution": "Awaiting customer contact" if unresolved_attempt else "Successful retry on the same trip"})
                if retry_attempt:
                    delivery_attempts.append({"attempt_id": number("attempt"), "trip_id": trip_id,
                        "order_id": order_id, "dispatch_id": dispatch_id, "attempted_on": current.isoformat(),
                        "attempt_status": "delivered", "reason": "Recipient confirmed receipt on retry",
                        "resolution": "Signed receipt recorded"})
                for item in order_items:
                    remaining = item["cases_ordered"]
                    for batch in sorted(stock[item["product_id"]], key=lambda b: (b["expires_on"], b["batch_id"])):
                        if not remaining:
                            break
                        if batch["status"] != "released" or batch["expires_on"] <= current.isoformat():
                            continue
                        take = min(remaining, batch["cases_on_hand"])
                        if not take:
                            continue
                        batch["cases_on_hand"] -= take
                        remaining -= take
                        lines.append({"dispatch_line_id": number("line"), "dispatch_id": dispatch_id,
                            "order_item_id": item["order_item_id"], "batch_id": batch["batch_id"],
                            "product_id": item["product_id"], "cases": take,
                            "cost_per_case_ksh": batch["cost_per_saleable_case_ksh"]})
                        movement(batch, -take, "customer_dispatch", "Dispatch", dispatch_id, current)
                    assert remaining == 0
                if unresolved_attempt:
                    continue  # Stock left the warehouse, but delivery and invoicing await resolution.
                invoice_id = number("invoice")
                invoices.append({"invoice_id": invoice_id, "order_id": order_id,
                    "invoice_number": f"INV-{invoice_id:07d}", "issued_on": current.isoformat(),
                    "amount_ksh": order["total_amount_ksh"], "status": "unpaid"})
                order["status"] = "invoiced"
                amount = Decimal(order["total_amount_ksh"])
                payment_chance = RNG.random()
                if payment_chance < .90:
                    paid = amount if payment_chance < .76 else Decimal(money(amount * Decimal("0.5")))
                    payments.append({"payment_id": number("payment"), "invoice_id": invoice_id,
                        "amount_ksh": money(paid), "paid_on": current.isoformat(),
                        "method": "Bank transfer", "reference": f"SIM-PAY-{invoice_id:07d}"})
                    invoices[-1]["status"] = "paid" if paid == amount else "part_paid"
                    if paid == amount:
                        order["status"] = "paid"

        for product in PRODUCTS:
            pid = product[0]
            cases = accepted[pid]
            daily.append({"date": current.isoformat(), "product_id": pid, "sku": product[1],
                "day_type": "operating_with_orders" if cases else "operating_zero_orders",
                "cases_ordered": cases, "cases_requested": requested[pid],
                "bottles_ordered": cases * product[4],
                "litres_ordered": cases * product[4] * product[3] // 1000})
            demand_history[pid].append(cases)
        current += timedelta(days=1)

    tables = {"products": products, "customers": customers, "product_manufacturing_policies": policies,
        "production_batches": batches, "sales_orders": orders, "sales_order_items": items,
        "delivery_trips": trips, "delivery_trip_orders": trip_orders, "dispatches": dispatches,
        "dispatch_lines": lines, "invoices": invoices, "payments": payments,
        "stock_movements": movements, "batch_disposals": disposals,
        "daily_product_demand": daily, "daily_order_closures": closures,
        "batch_quality_events": quality_events, "delivery_attempts": delivery_attempts}
    for name, rows in tables.items():
        write(name, rows)
    hashes = {name: hashlib.sha256((ROOT / f"{name}.csv").read_bytes()).hexdigest() for name in tables}
    metadata = {"dataset_name": "AquaTrack fictional Nairobi bottled-water operations, 2024-01-01 to 2026-11-05",
        "dataset_type": "Entirely synthetic", "period_start": START.isoformat(), "period_end": END.isoformat(),
        "random_seed": SEED, "operating_days": "Monday through Saturday", "closed_weekday": "Sunday",
        "forecast_horizons": "7 and 30 calendar days, including closed Sundays with zero orders",
        "forecast_target": "Accepted customer orders in cases by order date and product; draft and cancelled requests excluded",
        "record_counts": {name: len(rows) for name, rows in tables.items()}, "sha256": hashes,
        "source_limitations": ["All names, demand patterns, costs, policies, evidence references and events are fictional.",
            "Seasonal factors are explicit proxies, not observed Nairobi weather.",
            "The 180-day shelf life is a synthetic scenario assumption, not a certified industry standard.",
            "Same-day quality approval and delivery simplify the historical scenario.",
            "Rare failed delivery attempts are logged; one latest-day order remains dispatched pending customer contact.",
            "Rare quality holds, quarantine, recall and expiry disposal are demonstration exceptions, never dispatched.",
            "Customer ordering cadence is a synthetic daily, several-per-week, weekly, fortnightly or occasional schedule.",
            "Accuracy on this dataset cannot establish accuracy for a real company."]}
    (ROOT / "dataset_metadata.json").write_text(json.dumps(metadata, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"result": "generated", "record_counts": metadata["record_counts"]}, indent=2))


if __name__ == "__main__":
    generate()
