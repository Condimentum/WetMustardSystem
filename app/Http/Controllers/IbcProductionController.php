<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class IbcProductionController extends Controller
{
    public function __invoke(Request $request)
    {
        $orders = $this->serve(\App\Features\ManufacturingOrders\ShowProductionOrdersFeature::class, 29, 2);

        return view('production.orders', [
            'title' => 'IBC Production',
            'subtitle' => 'Outstanding Wet Packed orders — UnitOfMeasure 2 (IBC)',
            'orders' => $orders,
        ]);
    }
}
