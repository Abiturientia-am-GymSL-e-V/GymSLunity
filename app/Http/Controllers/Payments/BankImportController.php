<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Payments\BankCsvImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BankImportController extends Controller
{
    public function __invoke(Request $request, BankCsvImport $import): RedirectResponse
    {
        $request->validate(['csv' => ['required', 'file', 'max:5120', 'mimes:csv,txt']]);
        $result = $import->handle($request->file('csv'), $request->user());
        Inertia::flash('toast', [
            'type' => $result['unmatched'] ? 'warning' : 'success',
            'message' => $result['imported'].' Zahlungen verbucht'.($result['unmatched'] ? ', '.$result['unmatched'].' Zeilen nicht zugeordnet.' : '.'),
        ]);

        return back();
    }
}
