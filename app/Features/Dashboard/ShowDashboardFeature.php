<?php

namespace App\Features\Dashboard;

use Illuminate\Http\Request;

/**
 * Serves the main menu (home screen) tiles used to navigate to each area of the app.
 */
class ShowDashboardFeature
{
    public function __invoke(Request $request)
    {
        $tiles = [
            [
                'title' => 'Daily Calibrations',
                'subtitle' => 'Equipment checks & sign-off',
                'icon' => 'wrench',
                'route' => 'calibrations.daily',
            ],
            [
                'title' => 'Metal Detections',
                'subtitle' => 'Daily register & hourly checks',
                'icon' => 'scanner',
                'route' => 'metal-detector.daily',
            ],
            [
                'title' => 'Quality & Lab Testing',
                'subtitle' => 'QC results & lab records',
                'icon' => 'microscope',
                'route' => 'quality.lab-testing',
            ],
            [
                'title' => 'Intermediate Production',
                'subtitle' => 'Search MOs & start batches',
                'icon' => 'gear',
                'route' => 'manufacturing-orders.search',
            ],
            [
                'title' => 'IBC Production',
                'subtitle' => 'Outstanding Wet Packed IBC orders',
                'icon' => 'trolley',
                'route' => 'production.ibc',
            ],
            [
                'title' => 'Bucketing',
                'subtitle' => 'Outstanding Wet Packed bucket orders',
                'icon' => 'bucket',
                'route' => 'production.bucketing',
            ],
        ];

        return view('dashboard', [
            'tiles' => $tiles,
        ]);
    }
}
