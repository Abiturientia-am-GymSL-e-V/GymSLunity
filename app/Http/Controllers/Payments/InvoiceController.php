<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Configuration\ClubSettings;
use App\Configuration\MailConfigurator;
use App\Http\Controllers\Controller;
use App\Mail\ContributionInvoiceMail;
use App\Models\Contribution;
use App\Payments\ContributionInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class InvoiceController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function generate(Request $request, ContributionInvoice $invoices): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct', 'exists:contributions,id'],
            'tax_deductible' => ['nullable', 'boolean'],
        ]);
        $canSetTaxDeductible = $this->clubSettings->enabled('contributions_tax_deductible');
        $count = 0;
        DB::transaction(function () use ($data, $canSetTaxDeductible, $invoices, &$count): void {
            $contributions = Contribution::query()->whereKey($data['ids'])->where('kind', 'contribution')->orderBy('id')->get();
            if ($contributions->count() !== count($data['ids'])) {
                throw ValidationException::withMessages(['ids' => 'Die Auswahl enthält ungültige Rechnungsposten.']);
            }
            foreach ($contributions as $contribution) {
                if ($canSetTaxDeductible && ($data['tax_deductible'] ?? false)) {
                    $contribution->update(['tax_deductible' => true]);
                }
                if ($contribution->invoice_number === null) {
                    $invoices->number($contribution);
                    $count++;
                }
            }
        }, attempts: 3);
        Inertia::flash('toast', [
            'type' => $count > 0 ? 'success' : 'info',
            'message' => $count > 0 ? $count.' Beitragsrechnungen erzeugt.' : 'Alle ausgewählten Rechnungen waren bereits erzeugt.',
        ]);

        return back();
    }

    public function document(Request $request, Contribution $contribution, ContributionInvoice $invoices): Response
    {
        abort_unless($contribution->invoice_number !== null, 404);
        $format = $request->validate(['format' => ['nullable', 'in:pdf,print']])['format'] ?? 'pdf';
        if ($format === 'print') {
            return response($invoices->html($contribution, true), 200, [
                'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'",
            ]);
        }

        return response($invoices->pdf($contribution), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$contribution->invoice_number.'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function combined(Request $request, ContributionInvoice $invoices): Response
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct', 'exists:contributions,id'],
        ]);
        $contributions = Contribution::query()->whereKey($data['ids'])->where('kind', 'contribution')
            ->whereNotNull('invoice_number')->orderBy('invoice_number')->get();
        if ($contributions->count() !== count($data['ids'])) {
            throw ValidationException::withMessages(['ids' => 'Für den Sammeldownload müssen alle ausgewählten Rechnungen bereits erzeugt sein.']);
        }

        return response($invoices->combinedPdf($contributions), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="beitragsrechnungen-'.now()->format('Y-m-d-His').'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function send(Request $request, ContributionInvoice $invoices, MailConfigurator $mailConfigurator): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct', 'exists:contributions,id'],
        ]);
        $contributions = Contribution::query()->with('account.member')->whereKey($data['ids'])->where('kind', 'contribution')->get();
        if ($contributions->count() !== count($data['ids'])) {
            throw ValidationException::withMessages(['ids' => 'Die Auswahl enthält ungültige Rechnungsposten.']);
        }
        $missing = $contributions->filter(fn (Contribution $item): bool => ! $item->account->member->email)->count();
        if ($missing) {
            throw ValidationException::withMessages(['ids' => $missing.' ausgewählte Mitglieder haben keine E-Mail-Adresse.']);
        }
        $mailConfigurator->applyStored();
        foreach ($contributions as $contribution) {
            $contribution = $invoices->number($contribution);
            Mail::to($contribution->account->member->email)->send(new ContributionInvoiceMail($contribution));
            $contribution->update(['invoice_sent_at' => now()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $contributions->count().' Beitragsrechnungen versendet.']);

        return back();
    }
}
