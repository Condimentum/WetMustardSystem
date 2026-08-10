<?php

namespace App\Http\Controllers;

use App\Features\MetalDetector\ExportDailyMetalDetectorPaperworkFeature;
use Illuminate\Http\Request;

class DailyMetalDetectorPaperworkController extends Controller
{
    public function __invoke(Request $request)
    {
        return $this->serve(
            ExportDailyMetalDetectorPaperworkFeature::class,
            $request->query('date')
        );
    }
}
