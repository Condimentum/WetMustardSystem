<?php

namespace App\Http\Controllers;

use App\Features\Audit\ExportErrorLogCsvFeature;
use Illuminate\Http\Request;

class ErrorLogExportController extends Controller
{
    public function __invoke(Request $request)
    {
        return $this->serve(ExportErrorLogCsvFeature::class, $request->only([
            'date_from', 'date_to', 'level', 'context',
        ]));
    }
}
