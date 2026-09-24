<?php

namespace App\Http\Controllers\Forms;

use App\Configuration\MailConfigurator;
use App\Documents\SignatureImage;
use App\Http\Controllers\Controller;
use App\Mail\ReceiptMail;
use App\Models\ClubSetting;
use App\Models\Receipt;
use App\Receipts\IssueReceipt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ReceiptController extends Controller
{
    public function index(Request $request): Response
    {
        $tab = (string) $request->route('tab', 'create');
        $tabs = [
            'create' => ['Quittung erstellen', route('receipts.index')],
            'list' => ['Quittungsarchiv', route('receipts.archive')],
        ];
        abort_unless(isset($tabs[$tab]), 404);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $search = trim($filters['search'] ?? '');
        $receipts = Receipt::query()->select(['id', 'receipt_number', 'receipt_date', 'amount_cents', 'currency', 'payer', 'payee', 'purpose', 'created_by_name'])
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                foreach (['receipt_number', 'payer', 'payee', 'purpose'] as $column) {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
            }))->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('forms/Receipts', [
            'activeTab' => $tab,
            'navigationBreadcrumb' => ['title' => $tabs[$tab][0], 'href' => $tabs[$tab][1]],
            'receipts' => $receipts, 'search' => $search, 'creationKey' => (string) Str::uuid(),
            'club' => Arr::only(ClubSetting::current()->data, ['name', 'street', 'postal_code', 'city', 'email']),
            'hasProfileSignature' => $request->user()->hasProfileSignature(),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, IssueReceipt $issue): RedirectResponse
    {
        $data = $request->validate([
            'creation_key' => ['required', 'uuid'],
            'receipt_number' => ['nullable', 'string', 'max:40', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_\/-]*\z/'],
            'receipt_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
            'amount' => ['bail', 'required', 'string', 'regex:/\A\d{1,9}(?:[.,]\d{1,2})?\z/', function ($attribute, $value, $fail): void {
                if ((float) str_replace(',', '.', $value) <= 0) {
                    $fail('Der Betrag muss größer als null sein.');
                }
            }],
            'currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'vat_rate' => ['required', Rule::in([0, 7, 19])],
            'vat_reason' => ['nullable', 'required_unless:vat_rate,19', 'string', 'max:500'],
            'payer_source' => ['required', Rule::in(['club', 'other'])], 'payee_source' => ['required', Rule::in(['club', 'other'])],
            'payer' => ['nullable', 'required_if:payer_source,other', 'string', 'max:1000'], 'payee' => ['nullable', 'required_if:payee_source,other', 'string', 'max:1000'],
            'payer_email' => ['nullable', 'email:rfc', 'max:255'], 'payee_email' => ['nullable', 'email:rfc', 'max:255'],
            'purpose' => ['required', 'string', 'max:1000'], 'signer_name' => ['required', 'string', 'max:255'],
            'signature_method' => ['required', Rule::in(['digital', 'profile', 'drawn'])],
            'signature_data' => [Rule::requiredIf(fn (): bool => $request->input('signature_method') === 'drawn'), 'nullable', 'string', 'max:'.SignatureImage::MAX_DATA_URL_LENGTH],
            'confirmed' => ['accepted'],
        ]);
        $receipt = $issue->handle($data, $request->user(), $request->ip());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Quittung '.$receipt->receipt_number.' ausgestellt. Original und Kopie stehen bereit.']);

        return redirect('/formulare/quittungen/'.$receipt->id);
    }

    public function show(Receipt $receipt): Response
    {
        return Inertia::render('forms/Receipt', [
            'receipt' => ['id' => $receipt->id, ...$receipt->snapshot],
            'deliveries' => DB::table('receipt_deliveries')->where('receipt_id', $receipt->id)->latest('id')->limit(50)->get(['edition', 'recipient', 'sent_by_name', 'created_at']),
        ]);
    }

    public function document(Request $request, Receipt $receipt, string $edition): HttpResponse
    {
        abort_unless(in_array($edition, ['original', 'copy'], true), 404);
        $request->validate(['inline' => ['nullable', 'boolean']]);

        return response($receipt->pdf($edition), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => ($request->boolean('inline') ? 'inline' : 'attachment').'; filename="'.$receipt->filename($edition).'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function send(Request $request, Receipt $receipt, MailConfigurator $mailConfigurator): RedirectResponse
    {
        $data = $request->validate(['edition' => ['required', Rule::in(['original', 'copy'])], 'recipient' => ['required', 'email:rfc', 'max:255']]);
        $mailConfigurator->applyStored();
        try {
            Mail::to($data['recipient'])->send(new ReceiptMail($receipt, $data['edition']));
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['recipient' => 'Die E-Mail konnte nicht versendet werden. Bitte prüfe die E-Mail-Konfiguration und versuche es erneut.']);
        }
        DB::table('receipt_deliveries')->insert([
            'receipt_id' => $receipt->id, 'edition' => $data['edition'], 'recipient' => $data['recipient'],
            'sent_by' => $request->user()->id, 'sent_by_name' => $request->user()->name, 'created_at' => now(),
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Quittung per E-Mail versendet.']);

        return back();
    }
}
