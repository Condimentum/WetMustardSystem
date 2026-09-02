<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PackedProductionController extends Controller
{
    public function __invoke(Request $request)
    {
        $orders = $this->serve(\App\Features\ManufacturingOrders\ShowProductionOrdersFeature::class, 29);

        return view('production.orders', [
            'title' => 'Wet Mustard - Packed',
            'subtitle' => 'Outstanding classification 29 orders, all pack types',
            'orders' => $orders,
        ]);
    }
}
