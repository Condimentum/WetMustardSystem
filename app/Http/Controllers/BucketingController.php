<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class BucketingController extends Controller
{
    public function __invoke(Request $request)
    {
        $orders = $this->serve(\App\Features\ManufacturingOrders\ShowProductionOrdersFeature::class, 29, 44);

        return view('production.orders', [
            'title' => 'Bucketing',
            'subtitle' => 'Outstanding Wet Packed orders — UnitOfMeasure 44 (Buckets)',
            'orders' => $orders,
        ]);
    }
}
