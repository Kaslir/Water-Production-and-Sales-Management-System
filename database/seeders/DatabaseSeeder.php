<?php

namespace Database\Seeders;

use App\Models\{Customer,Inventory,Production,Sale,User};
use App\Services\InventoryService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    
    public function run(): void
    {
        $users=[]; foreach (['administrator'=>'Administrator','manager'=>'Manager','production_staff'=>'Production Staff','inventory_staff'=>'Inventory Staff','distribution_staff'=>'Distribution Staff','sales_staff'=>'Sales Staff'] as $role=>$name) $users[$role]=User::create(['name'=>$name,'email'=>str_replace('_','.',$role).'@aquatrack.test','role'=>$role,'password'=>Hash::make('Password123!')]);
        Inventory::create(['quantity_available'=>0,'last_updated'=>now()]); $stock=app(InventoryService::class);
        $customer=Customer::create(['name'=>'Nairobi Fresh Mart','phone_number'=>'0712345678']);
        for($i=60;$i>=1;$i--){$date=now()->subDays($i)->toDateString();$qty=500+($i%7)*35;$p=Production::create(['production_date'=>$date,'quantity_produced'=>$qty,'user_id'=>$users['production_staff']->user_id]);$stock->add($qty,$date,$users['production_staff']->user_id,'production',$p->production_id,'Synthetic demo production'); if($i%3===0){$saleQty=120+($i%5)*15;$sale=Sale::create(['sale_date'=>$date,'quantity_sold'=>$saleQty,'unit_price'=>35,'total_amount'=>$saleQty*35,'customer_id'=>$customer->customer_id,'user_id'=>$users['sales_staff']->user_id]);$stock->remove($saleQty,$date,$users['sales_staff']->user_id,'sale',$sale->sale_id,'Synthetic demo sale');}}
    }
}
