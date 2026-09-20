<?php
namespace App\Http\Controllers;
use App\Models\{Distribution,Inventory,InventoryTransaction,Production,Sale}; use Illuminate\Support\Facades\DB;
class DashboardController extends Controller { public function __invoke(){ $u=request()->user(); $data=['inventory'=>Inventory::first()->quantity_available ?? 0,'production'=>Production::sum('quantity_produced'),'distribution'=>Distribution::sum('quantity_distributed'),'salesQuantity'=>Sale::sum('quantity_sold'),'revenue'=>Sale::sum('total_amount'),'activities'=>InventoryTransaction::with('user')->latest()->take(8)->get(),'salesChart'=>Sale::selectRaw('sale_date, SUM(total_amount) total')->groupBy('sale_date')->orderBy('sale_date')->limit(30)->get()]; return view('dashboard',compact('data','u'));} }
