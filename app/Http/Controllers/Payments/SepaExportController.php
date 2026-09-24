<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Payments\SepaDirectDebit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SepaExportController extends Controller
{
    public function __invoke(Request $request, SepaDirectDebit $export): Response
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['integer', 'distinct', 'exists:contributions,id'],
            'collection_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ]);
        $xml = $export->export($data['ids'], $data['collection_date'], $request->user());

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="sepa-lastschriften-'.now()->format('Y-m-d-His').'.xml"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
