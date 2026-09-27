<?php

namespace App\Http\Controllers\Donations;

use App\Configuration\MailConfigurator;
use App\Documents\SignatureImage;
use App\Donations\DonationAudit;
use App\Donations\DonationCertificateGenerator;
use App\Donations\DonationPurposes;
use App\Donations\DonationSequence;
use App\Http\Controllers\Controller;
use App\Mail\DonationCertificateMail;
use App\Models\ClubSetting;
use App\Models\Donation;
use App\Models\DonationCertificate;
use App\Payments\Money;
use App\Support\FormOfAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class DonationController extends Controller
{
    public function index(Request $request): Response
    {
        $tab = (string) $request->route('tab', 'ledger');
        $tabs = [
            'ledger' => ['Spendenbuch', route('donations')],
            'create' => ['Spende anlegen', route('donations.create')],
            'open' => ['Offene Zuwendungsbestätigungen', route('donations.open')],
        ];
        abort_unless(isset($tabs[$tab]), 404);
        $club = ClubSetting::current()->data;
        $configuredCodes = is_array($club['donation_purpose_codes'] ?? null) ? $club['donation_purpose_codes'] : [];
        $purposeOptions = collect(DonationPurposes::forFrontend())->whereIn('value', $configuredCodes)->values()->all();
        $donations = Donation::query()->with(['certificate.deliveries' => fn ($query) => $query->latest('created_at')])
            ->latest('donated_at')->latest('id')->limit(500)->get()
            ->map(fn (Donation $donation): array => $this->row($donation));

        return Inertia::render('Donations', [
            'activeTab' => $tab,
            'navigationBreadcrumb' => ['title' => $tabs[$tab][0], 'href' => $tabs[$tab][1]],
            'donations' => $donations,
            'purposes' => $purposeOptions,
            'configuration' => [
                'ready' => DonationCertificateGenerator::configurationErrors($club) === [],
                'errors' => DonationCertificateGenerator::configurationErrors($club),
                'contributions_tax_deductible' => (bool) ($club['contributions_tax_deductible'] ?? false),
            ],
            'hasProfileSignature' => $request->user()->hasProfileSignature(),
            'summary' => [
                'count' => $donations->count(),
                'amount_cents' => $donations->sum('amount_cents'),
                'open_count' => $donations->whereNull('certificate')->count(),
                'issued_count' => $donations->whereNotNull('certificate')->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (is_string($request->input('amount'))) {
            $request->merge(['amount' => str_replace(',', '.', trim($request->input('amount')))]);
        }
        $data = $request->validate([
            'donor_name' => ['required', 'string', 'max:255'],
            'donor_street' => ['required', 'string', 'max:255'],
            'donor_postal_code' => ['required', 'string', 'max:20'],
            'donor_city' => ['required', 'string', 'max:255'],
            'donor_country' => ['required', 'string', 'size:2'],
            'donor_email' => ['nullable', 'email:rfc', 'max:255'],
            'donation_type' => ['required', Rule::in(['money', 'material', 'membership_fee', 'expense_waiver'])],
            'amount' => ['required', 'decimal:0,2', 'min:0.01', 'max:9999999.99'],
            'donated_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'purpose_code' => ['required', Rule::in(array_keys(DonationPurposes::options()))],
            'description' => ['nullable', 'required_if:donation_type,material', 'string', 'max:600'],
            'asset_origin' => ['nullable', 'required_if:donation_type,material', Rule::in(['business', 'private', 'unknown'])],
            'valuation_document_reference' => ['nullable', 'string', 'max:255'],
        ], [
            'description.required_if' => 'Bitte die Sachzuwendung genau mit Alter, Zustand und Kaufpreis beschreiben.',
            'asset_origin.required_if' => 'Bitte die Herkunft der Sachzuwendung angeben.',
        ]);
        $club = ClubSetting::current()->data;
        $configuredCodes = is_array($club['donation_purpose_codes'] ?? null) ? $club['donation_purpose_codes'] : [];
        if (! in_array($data['purpose_code'], $configuredCodes, true)) {
            throw ValidationException::withMessages(['purpose_code' => 'Der Zweck ist nicht in den Spenden-Stammdaten freigegeben.']);
        }
        if ($data['donation_type'] === 'membership_fee' && ! (bool) ($club['contributions_tax_deductible'] ?? false)) {
            throw ValidationException::withMessages(['donation_type' => 'Mitgliedsbeiträge sind laut Konfiguration nicht als Zuwendung abzugsfähig.']);
        }

        $donation = DB::transaction(function () use ($request, $data): Donation {
            $year = (int) substr($data['donated_at'], 0, 4);
            $donation = Donation::query()->create([
                'receipt_number' => sprintf('SP-%d-%06d', $year, DonationSequence::next('donation', $year)),
                'created_by' => $request->user()->id,
                'created_by_name' => $request->user()->name,
                'donor_name' => $data['donor_name'],
                'donor_street' => $data['donor_street'],
                'donor_postal_code' => $data['donor_postal_code'],
                'donor_city' => $data['donor_city'],
                'donor_country' => strtoupper($data['donor_country']),
                'donor_email' => $data['donor_email'] ?: null,
                'donation_type' => $data['donation_type'],
                'amount_cents' => Money::cents($data['amount']),
                'donated_at' => $data['donated_at'],
                'purpose_code' => $data['purpose_code'],
                'purpose_label' => DonationPurposes::options()[$data['purpose_code']],
                'description' => $data['description'] ?: null,
                'expense_waiver' => $data['donation_type'] === 'expense_waiver',
                'asset_origin' => $data['donation_type'] === 'material' ? $data['asset_origin'] : null,
                'valuation_document_reference' => $data['donation_type'] === 'material' ? ($data['valuation_document_reference'] ?? null) : null,
                'created_at' => now(),
            ]);
            DonationAudit::record($request->user(), 'donation_created', [
                'receipt_number' => $donation->receipt_number,
                'amount_cents' => $donation->amount_cents,
                'donation_type' => $donation->donation_type,
                'donated_at' => $donation->donated_at->format('Y-m-d'),
            ], $donation);

            return $donation;
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Spende '.$donation->receipt_number.' wurde angelegt.']);

        return to_route('donations');
    }

    public function issue(Request $request, Donation $donation, DonationCertificateGenerator $generator): RedirectResponse
    {
        // Keep the original request shape working for existing API consumers.
        if (! $request->has('signature_method') && $request->boolean('digitally_sign')) {
            $request->merge(['signature_method' => 'digital']);
        }
        $data = $request->validate([
            'signature_method' => ['required', Rule::in(['digital', 'profile', 'drawn'])],
            'signature_data' => [
                Rule::requiredIf(fn (): bool => $request->input('signature_method') === 'drawn'),
                'nullable',
                'string',
                'max:'.SignatureImage::MAX_DATA_URL_LENGTH,
            ],
        ]);

        $signatureImage = null;
        if ($data['signature_method'] === 'profile') {
            $signatureImage = $request->user()->profileSignature();
            if (! is_string($signatureImage)) {
                throw ValidationException::withMessages([
                    'signature_method' => FormOfAddress::choose('In deinem Profil ist noch keine Unterschrift hinterlegt.', 'In Ihrem Profil ist noch keine Unterschrift hinterlegt.'),
                ]);
            }
        } elseif ($data['signature_method'] === 'drawn') {
            try {
                $signatureImage = SignatureImage::fromDataUrl($data['signature_data']);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['signature_data' => $exception->getMessage()]);
            }
        }

        $certificate = $generator->issue($donation, $request->user(), $data['signature_method'], $signatureImage);
        $method = match ($data['signature_method']) {
            'profile' => 'mit der Profil-Unterschrift',
            'drawn' => 'mit der gezeichneten Unterschrift',
            default => 'digital',
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zuwendungsbestätigung '.$certificate->certificate_number.' wurde '.$method.' unterzeichnet und revisionssicher gespeichert.']);

        return back();
    }

    public function document(Request $request, DonationCertificate $certificate): HttpResponse
    {
        $pdf = $certificate->pdf();
        DB::transaction(fn () => DonationAudit::record($request->user(), 'certificate_downloaded', [
            'certificate_number' => $certificate->certificate_number,
            'pdf_sha256' => $certificate->pdf_sha256,
        ], $certificate->donation));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$certificate->certificate_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'ETag' => '"'.$certificate->pdf_sha256.'"',
        ]);
    }

    public function send(Request $request, DonationCertificate $certificate, MailConfigurator $mailConfigurator): RedirectResponse
    {
        $donation = $certificate->donation;
        if (! $donation->donor_email) {
            throw ValidationException::withMessages(['email' => 'Für diese Spende ist keine E-Mail-Adresse hinterlegt.']);
        }
        $mailConfigurator->applyStored();
        Mail::to($donation->donor_email)->send(new DonationCertificateMail($certificate));
        DB::transaction(function () use ($request, $certificate, $donation): void {
            $certificate->deliveries()->create([
                'recipient' => $donation->donor_email,
                'sent_by' => $request->user()->id,
                'sent_by_name' => $request->user()->name,
                'created_at' => now(),
            ]);
            DonationAudit::record($request->user(), 'certificate_emailed', [
                'certificate_number' => $certificate->certificate_number,
                'recipient' => $donation->donor_email,
            ], $donation);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Zuwendungsbestätigung wurde an '.$donation->donor_email.' versendet.']);

        return back();
    }

    /** @return array<string, mixed> */
    private function row(Donation $donation): array
    {
        $certificate = $donation->certificate;
        $delivery = $certificate?->deliveries->first();

        return [
            'id' => $donation->id,
            'receipt_number' => $donation->receipt_number,
            'donor_name' => $donation->donor_name,
            'donor_email' => $donation->donor_email,
            'donation_type' => $donation->donation_type,
            'amount_cents' => $donation->amount_cents,
            'donated_at' => $donation->donated_at->format('Y-m-d'),
            'purpose_label' => $donation->purpose_label,
            'description' => $donation->description,
            'certificate' => $certificate ? [
                'id' => $certificate->id,
                'number' => $certificate->certificate_number,
                'signed_at' => $certificate->signed_at->toIso8601String(),
                'signed_by' => $certificate->signed_by_name,
                'sent_at' => $delivery?->created_at->toIso8601String(),
                'sent_to' => $delivery?->recipient,
            ] : null,
        ];
    }
}
