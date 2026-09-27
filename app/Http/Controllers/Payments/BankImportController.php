<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Member;
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

    public function assign(Request $request, int $paymentImport, int $row, BankCsvImport $import): RedirectResponse
    {
        $data = $request->validate(['member_number' => ['required', 'integer', 'exists:members,member_number']]);
        $member = Member::query()->where('member_number', $data['member_number'])->firstOrFail();
        $import->assign($paymentImport, $row, $member, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Die Bankbuchung wurde zugeordnet und verbucht.']);

        return back();
    }

    public function ignore(Request $request, int $paymentImport, int $row, BankCsvImport $import): RedirectResponse
    {
        $import->ignore($paymentImport, $row);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Die Bankbuchung wurde als nicht beitragsrelevant markiert.']);

        return back();
    }
}
