<?php

declare(strict_types=1);

namespace App\Http\Controllers\Forms;

use App\Configuration\ClubSettings;
use App\Configuration\MailConfigurator;
use App\Demo\DemoAccounts;
use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\ReceiptFilterRequest;
use App\Http\Requests\Forms\StoreReceiptRequest;
use App\Mail\ReceiptMail;
use App\Members\MemberReportWriter;
use App\Models\Receipt;
use App\Receipts\IssueReceipt;
use App\Support\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ReceiptController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function index(ReceiptFilterRequest $request): Response
    {
        $tab = (string) $request->route('tab', 'list');
        $tabs = [
            'list' => ['Übersicht', route('receipts.index')],
            'create' => ['Quittung erstellen', route('receipts.create')],
        ];
        abort_unless(isset($tabs[$tab]), 404);
        $normalizedFilters = $request->filters();
        $receipts = $this->filteredQuery($normalizedFilters)
            ->select(['id', 'receipt_number', 'receipt_date', 'amount_cents', 'currency', 'payer', 'payee', 'purpose', 'created_by_name', 'exported_at', 'cancelled_at'])
            ->latest('receipt_date')->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('forms/Receipts', [
            'activeTab' => $tab,
            'navigationBreadcrumb' => ['title' => $tabs[$tab][0], 'href' => $tabs[$tab][1]],
            'receipts' => $receipts, 'filters' => $normalizedFilters, 'creationKey' => (string) Str::uuid(),
            'club' => Arr::only($this->clubSettings->data(), ['name', 'street', 'postal_code', 'city', 'email']),
            'hasProfileSignature' => $request->user()->hasProfileSignature(),
            'today' => Clock::todayString(),
        ]);
    }

    public function report(ReceiptFilterRequest $request): HttpResponse
    {
        $filters = $request->filters();
        $query = $this->filteredQuery($filters)
            ->select(['id', 'receipt_number', 'receipt_date', 'amount_cents', 'currency', 'payer', 'payee', 'purpose', 'exported_at', 'cancelled_at'])
            ->latest('receipt_date')->latest('id');
        if ((clone $query)->count() > 5000) {
            throw ValidationException::withMessages(['scope' => 'Der Bericht ist auf 5.000 Quittungen begrenzt. Bitte den Zeitraum oder die Filter einschränken.']);
        }
        $receipts = $query->get();
        $settings = $this->clubSettings;
        $club = $settings->data();
        $logo = $settings->logoDataUri();
        $printedAt = now()->setTimezone(config('app.display_timezone'));
        $html = view('receipts.ledger-report', compact('receipts', 'filters', 'club', 'logo', 'printedAt'))->render();
        $pdf = MemberReportWriter::pdf($html, true);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="quittungsbuch-'.Clock::localNow()->format('Y-m-d-His').'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(StoreReceiptRequest $request, IssueReceipt $issue): RedirectResponse
    {
        $data = $request->validated();
        $receipt = $issue->handle($data, $request->user(), DemoAccounts::documentIp($request->ip()));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Quittung '.$receipt->receipt_number.' ausgestellt. Original und Kopie stehen bereit.']);

        return redirect('/formulare/quittungen/'.$receipt->id);
    }

    public function show(Receipt $receipt): Response
    {
        return Inertia::render('forms/Receipt', [
            'receipt' => [
                'id' => $receipt->id, ...$receipt->snapshot,
                'exported_at' => $receipt->exported_at?->toIso8601String(),
                'cancelled_at' => $receipt->cancelled_at?->toIso8601String(),
                'cancelled_by_name' => $receipt->cancelled_by_name,
                'cancellation_reason' => $receipt->cancellation_reason,
                'can_cancel' => $receipt->cancelled_at === null && $receipt->exported_at === null && ! DB::table('receipt_deliveries')->where('receipt_id', $receipt->id)->exists(),
            ],
            'deliveries' => DB::table('receipt_deliveries')->where('receipt_id', $receipt->id)->latest('id')->limit(50)->get(['edition', 'recipient', 'sent_by_name', 'created_at']),
        ]);
    }

    public function document(Request $request, Receipt $receipt, string $edition): HttpResponse
    {
        abort_unless(in_array($edition, ['original', 'copy'], true), 404);
        $request->validate(['inline' => ['nullable', 'boolean']]);
        DB::transaction(function () use ($receipt): void {
            $current = DB::table('receipts')->where('id', $receipt->id)->lockForUpdate()->first(['cancelled_at', 'exported_at']);
            abort_if($current === null, 404);
            abort_if($current->cancelled_at !== null, 409, 'Eine stornierte Quittung kann nicht mehr exportiert werden.');
            if ($current->exported_at === null) {
                DB::table('receipts')->where('id', $receipt->id)->update(['exported_at' => now()]);
            }
        });

        return response($receipt->pdf($edition), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => ($request->boolean('inline') ? 'inline' : 'attachment').'; filename="'.$receipt->filename($edition).'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function send(Request $request, Receipt $receipt, MailConfigurator $mailConfigurator): RedirectResponse
    {
        $data = $request->validate(['edition' => ['required', Rule::in(['original', 'copy'])], 'recipient' => ['required', 'email:rfc', 'max:255']]);
        $markedForDelivery = DB::transaction(function () use ($receipt): bool {
            $current = DB::table('receipts')->where('id', $receipt->id)->lockForUpdate()->first(['cancelled_at', 'exported_at']);
            if ($current === null || $current->cancelled_at !== null) {
                throw ValidationException::withMessages(['recipient' => 'Eine stornierte Quittung kann nicht versendet werden.']);
            }
            if ($current->exported_at !== null) {
                return false;
            }
            DB::table('receipts')->where('id', $receipt->id)->update(['exported_at' => now()]);

            return true;
        });
        $mailConfigurator->applyStored();
        try {
            Mail::to($data['recipient'])->send(new ReceiptMail($receipt, $data['edition']));
        } catch (Throwable $exception) {
            report($exception);
            if ($markedForDelivery) {
                DB::table('receipts')->where('id', $receipt->id)
                    ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('receipt_deliveries')->whereColumn('receipt_deliveries.receipt_id', 'receipts.id'))
                    ->update(['exported_at' => null]);
            }

            return back()->withErrors(['recipient' => 'Die E-Mail konnte nicht versendet werden. Bitte die E-Mail-Konfiguration prüfen und den Versand erneut versuchen.']);
        }
        DB::table('receipt_deliveries')->insert([
            'receipt_id' => $receipt->id, 'edition' => $data['edition'], 'recipient' => $data['recipient'],
            'sent_by' => $request->user()->id, 'sent_by_name' => $request->user()->name, 'created_at' => now(),
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Quittung per E-Mail versendet.']);

        return back();
    }

    public function cancel(Request $request, Receipt $receipt): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $receipt, $data): void {
            $current = DB::table('receipts')->where('id', $receipt->id)->lockForUpdate()->first(['cancelled_at', 'exported_at']);
            abort_if($current === null, 404);
            if ($current->cancelled_at !== null) {
                return;
            }
            if ($current->exported_at !== null || DB::table('receipt_deliveries')->where('receipt_id', $receipt->id)->exists()) {
                throw ValidationException::withMessages(['reason' => 'Die Quittung wurde bereits exportiert oder versendet und kann deshalb nicht mehr storniert werden.']);
            }
            DB::table('receipts')->where('id', $receipt->id)->update([
                'cancelled_at' => now(), 'cancelled_by' => $request->user()->id,
                'cancelled_by_name' => $request->user()->name, 'cancellation_reason' => $data['reason'],
            ]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Quittung '.$receipt->receipt_number.' wurde storniert.']);

        return back();
    }

    /** @param array<string, mixed> $filters
     * @return Builder<Receipt>
     */
    private function filteredQuery(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return Receipt::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function (Builder $query) use ($term): void {
                    foreach (['receipt_number', 'payer', 'payee', 'purpose'] as $column) {
                        $query->orWhere($column, 'like', $term);
                    }
                });
            })
            ->when(! empty($filters['from']), fn (Builder $query) => $query->whereDate('receipt_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn (Builder $query) => $query->whereDate('receipt_date', '<=', $filters['to']))
            ->when(($filters['status'] ?? null) === 'available', fn (Builder $query) => $query->whereNull('exported_at')->whereNull('cancelled_at'))
            ->when(($filters['status'] ?? null) === 'exported', fn (Builder $query) => $query->whereNotNull('exported_at')->whereNull('cancelled_at'))
            ->when(($filters['status'] ?? null) === 'cancelled', fn (Builder $query) => $query->whereNotNull('cancelled_at'));
    }
}
