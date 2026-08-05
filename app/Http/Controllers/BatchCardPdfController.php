<?php

namespace App\Http\Controllers;

use App\Models\BatchCard;
use Illuminate\Support\Facades\Storage;

class BatchCardPdfController extends Controller
{
    public function __invoke(BatchCard $batchCard)
    {
        $path = (string) ($batchCard->pdf_path ?? '');
        if ($path === '' || ! Storage::disk('public')->exists($path)) {
            abort(404, 'Batch card PDF not found.');
        }

        $filePath = Storage::disk('public')->path($path);
        $downloadName = $batchCard->document_code.'_rev_'.$batchCard->revision.'.pdf';

        return response()->file($filePath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$downloadName.'"',
        ]);
    }
}
