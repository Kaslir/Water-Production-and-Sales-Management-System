<?php

namespace App\Http\Controllers;

use App\Models\{Customer,Distribution,Inventory,InventoryTransaction,Production,Sale};
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->input('type', 'sales'); $from = $request->input('from', now()->subDays(30)->toDateString()); $to = $request->input('to', now()->toDateString());
        [$rows, $columns, $totals] = match ($type) {
            'production' => [Production::whereBetween('production_date',[$from,$to])->get(), ['Date'=>'production_date','Quantity (L)'=>'quantity_produced'], ['Total produced (L)'=>Production::whereBetween('production_date',[$from,$to])->sum('quantity_produced')]],
            'distribution' => [Distribution::whereBetween('distribution_date',[$from,$to])->get(), ['Date'=>'distribution_date','Destination'=>'destination','Quantity (L)'=>'quantity_distributed'], ['Total distributed (L)'=>Distribution::whereBetween('distribution_date',[$from,$to])->sum('quantity_distributed')]],
            'inventory' => [InventoryTransaction::whereBetween('transaction_date',[$from,$to])->get(), ['Date'=>'transaction_date','Type'=>'transaction_type','Quantity (L)'=>'quantity','Notes'=>'notes'], ['Current stock (L)'=>Inventory::first()?->quantity_available ?? 0]],
            'customers' => [Customer::withSum('sales','quantity_sold')->withSum('sales','total_amount')->get(), ['Customer'=>'name','Phone'=>'phone_number','Quantity (L)'=>'sales_sum_quantity_sold','Total spent (KSh)'=>'sales_sum_total_amount'], ['Customers'=>Customer::count()]],
            default => [Sale::with('customer')->whereBetween('sale_date',[$from,$to])->get(), ['Date'=>'sale_date','Customer'=>'customer.name','Quantity (L)'=>'quantity_sold','Revenue (KSh)'=>'total_amount'], ['Sales quantity (L)'=>Sale::whereBetween('sale_date',[$from,$to])->sum('quantity_sold'),'Revenue (KSh)'=>Sale::whereBetween('sale_date',[$from,$to])->sum('total_amount')]],
        };
        return view('reports.index', compact('type','from','to','rows','columns','totals'));
    }
}
