<?php

namespace App\Http\Controllers;

use App\Communication\CommunicationRecipients;
use App\Communication\CommunicationTemplate;
use App\Communication\RichTextSanitizer;
use App\Communication\SerialLetterGenerator;
use App\Configuration\MailConfigurator;
use App\Http\Requests\CommunicationRequest;
use App\Mail\SerialMemberMail;
use App\Members\MemberFields;
use App\Models\CommunicationCampaign;
use App\Models\MailSetting;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class CommunicationController extends Controller
{
    public function index(CommunicationRequest $request, CommunicationTemplate $templates): Response
    {
        $tab = (string) $request->route('tab', 'mail');
        $tabs = [
            'mail' => ['Serien-E-Mails', route('communication.mail')],
            'letters' => ['Serienbriefe', route('communication.letters')],
            'history' => ['Verlauf', route('communication.history')],
        ];
        abort_unless(isset($tabs[$tab]), 404);
        $filters = $request->filters();
        $query = CommunicationRecipients::query($filters);
        $total = (clone $query)->count();
        $withEmail = $this->withValue(clone $query, 'email')->count();
        $completeAddress = clone $query;
        foreach (['street', 'postal_code', 'city'] as $column) {
            $this->withValue($completeAddress, $column);
        }
        $completeAddressCount = $completeAddress->count();
        $preview = (clone $query)->limit(50)->get([
            'member_number', 'first_name', 'middle_name', 'last_name', 'email',
            'street', 'postal_code', 'city', 'membership_type', 'joined_at', 'left_at', 'deceased_at',
        ])->map(fn (Member $member): array => [
            'member_number' => $member->member_number,
            'name' => collect([$member->first_name, $member->middle_name, $member->last_name])->filter()->join(' '),
            'email' => $member->email,
            'city' => $member->city,
            'membership_type' => $member->membership_type,
            'email_ready' => is_string($member->email) && filter_var($member->email, FILTER_VALIDATE_EMAIL) !== false,
            'address_ready' => collect([$member->street, $member->postal_code, $member->city])->every(fn (?string $value): bool => is_string($value) && trim($value) !== ''),
        ])->values();
        $customFilters = collect(MemberFields::directoryFields())
            ->filter(fn (array $field): bool => $field['custom'] && $field['filterable'])
            ->values();
        $settings = MailSetting::current();
        $selectedCampaign = $tab === 'history' && $request->integer('campaign') > 0
            ? CommunicationCampaign::query()->whereKey($request->integer('campaign'))->first()
            : null;

        return Inertia::render('Kommunikation', [
            'activeTab' => $tab,
            'navigationBreadcrumb' => ['title' => $tabs[$tab][0], 'href' => $tabs[$tab][1]],
            'filters' => $filters,
            'filterOptions' => [
                'memberships' => $this->options('membership_type'),
                'departmentRoles' => $this->options('department_role'),
                'clubRoles' => $this->options('club_role'),
                'paymentMethods' => $this->options('payment_method'),
                'cities' => $this->options('city'),
            ],
            'customFilters' => $customFilters,
            'summary' => [
                'total' => $total,
                'with_email' => $withEmail,
                'without_email' => $total - $withEmail,
                'complete_address' => $completeAddressCount,
                'incomplete_address' => $total - $completeAddressCount,
            ],
            'preview' => $preview,
            'placeholders' => $templates->placeholders(),
            'mailConfiguration' => [
                'driver' => $settings->driver,
                'from_address' => $settings->from_address,
                'from_name' => $settings->from_name,
            ],
            'defaults' => [
                'subject' => 'Information von {{verein.name}}',
                'body' => '<p>{{mitglied.briefanrede}},</p><p><br></p><p>Mit freundlichen Grüßen<br>{{verein.name}}</p>',
            ],
            'campaigns' => CommunicationCampaign::query()->latest('id')->limit(30)->get()->map(fn (CommunicationCampaign $campaign): array => [
                'id' => $campaign->id,
                'kind' => $campaign->kind,
                'format' => $campaign->format,
                'subject' => $campaign->subject,
                'recipient_count' => $campaign->recipient_count,
                'skipped_count' => $campaign->skipped_count,
                'success_count' => $campaign->success_count,
                'failure_count' => $campaign->failure_count,
                'created_by_name' => $campaign->created_by_name,
                'attachments' => $campaign->attachments ?? [],
                'created_at' => $campaign->created_at->setTimezone(config('app.display_timezone'))->format('Y-m-d H:i:s'),
            ])->values(),
            'selectedCampaign' => $selectedCampaign === null ? null : [
                'id' => $selectedCampaign->id,
                'kind' => $selectedCampaign->kind,
                'format' => $selectedCampaign->format,
                'subject' => $selectedCampaign->subject,
                'recipient_count' => $selectedCampaign->recipient_count,
                'skipped_count' => $selectedCampaign->skipped_count,
                'success_count' => $selectedCampaign->success_count,
                'failure_count' => $selectedCampaign->failure_count,
                'created_by_name' => $selectedCampaign->created_by_name,
                'attachments' => $selectedCampaign->attachments ?? [],
                'created_at' => $selectedCampaign->created_at->setTimezone(config('app.display_timezone'))->format('Y-m-d H:i:s'),
            ],
            'deliveries' => $selectedCampaign?->deliveries()->orderBy('recipient_name')->get()->map(fn ($delivery): array => [
                'id' => $delivery->id,
                'member_number' => $delivery->member_number,
                'recipient_name' => $delivery->recipient_name,
                'recipient_email' => $delivery->recipient_email,
                'status' => $delivery->status,
                'error' => $delivery->error,
            ])->values() ?? [],
            'csrfToken' => csrf_token(),
        ]);
    }

    public function sendMail(CommunicationRequest $request, CommunicationTemplate $templates, RichTextSanitizer $sanitizer, MailConfigurator $mailConfigurator): RedirectResponse
    {
        $data = $request->validated();
        $filters = $request->filters();
        $body = $sanitizer->sanitize($data['body']);
        $templates->validate($data['subject'], 'subject');
        $templates->validate($body, 'body');
        $filtered = CommunicationRecipients::query($filters);
        $filteredCount = (clone $filtered)->count();
        $withEmail = $this->withValue($filtered, 'email');
        if ((clone $withEmail)->count() > 500) {
            throw ValidationException::withMessages(['recipients' => 'Pro Versand sind höchstens 500 E-Mail-Empfänger möglich. Bitte schränke die Auswahl weiter ein.']);
        }
        $members = $withEmail->get();
        $members = $members->filter(fn (Member $member): bool => is_string($member->email) && filter_var($member->email, FILTER_VALIDATE_EMAIL) !== false)->values();
        if ($members->isEmpty()) {
            throw ValidationException::withMessages(['recipients' => 'Für die Auswahl ist keine gültige E-Mail-Adresse hinterlegt.']);
        }

        $attachments = $this->attachments($request);
        $attachmentMetadata = array_map(fn (array $file): array => [
            'name' => $file['name'],
            'mime' => $file['mime'],
            'size' => strlen($file['contents']),
        ], $attachments);
        $campaign = $this->campaign($request, 'mail', null, $data['subject'], $body, $attachmentMetadata, $filters, $members->count(), $filteredCount - $members->count());
        $mailConfigurator->applyStored();
        $sent = 0;
        $failed = 0;
        foreach ($members as $member) {
            $delivery = $campaign->deliveries()->create($this->delivery($member, 'pending'));
            try {
                Mail::to($member->email)->send(new SerialMemberMail(
                    $templates->render($data['subject'], $member),
                    $templates->renderHtml($body, $member),
                    $attachments,
                ));
                $delivery->update(['status' => 'sent']);
                $sent++;
            } catch (Throwable $exception) {
                report($exception);
                $delivery->update(['status' => 'failed', 'error' => 'Versand durch den Mailserver fehlgeschlagen.']);
                $failed++;
            }
        }
        $campaign->update(['success_count' => $sent, 'failure_count' => $failed]);
        Inertia::flash('toast', [
            'type' => $failed === 0 ? 'success' : 'error',
            'message' => $failed === 0
                ? $sent.' Serien-E-Mails wurden versendet.'
                : $sent.' E-Mails versendet, '.$failed.' fehlgeschlagen. Details stehen im Verlauf.',
        ]);

        return to_route('communication.mail', array_filter(
            $filters,
            fn (mixed $value): bool => $value !== '' && $value !== [],
        ));
    }

    public function generateLetters(CommunicationRequest $request, CommunicationTemplate $templates, RichTextSanitizer $sanitizer, SerialLetterGenerator $generator): HttpResponse|BinaryFileResponse
    {
        $data = $request->validated();
        $filters = $request->filters();
        $body = $sanitizer->sanitize($data['body']);
        $templates->validate($data['subject'], 'subject');
        $templates->validate($body, 'body');
        $query = CommunicationRecipients::query($filters);
        $recipientCount = (clone $query)->count();
        if ($recipientCount === 0) {
            throw ValidationException::withMessages(['recipients' => 'Für die Auswahl wurden keine Empfänger gefunden.']);
        }
        $limit = $data['format'] === 'zip' ? 250 : 500;
        if ($recipientCount > $limit) {
            throw ValidationException::withMessages(['recipients' => 'Für dieses Format sind höchstens '.$limit.' Briefe pro Export möglich. Bitte schränke die Auswahl weiter ein.']);
        }
        $members = $query->get();
        $campaign = $this->campaign($request, 'letter', $data['format'], $data['subject'], $body, [], $filters, $members->count(), 0);
        try {
            $result = $data['format'] === 'zip'
                ? $generator->zip($members, $data['subject'], $body)
                : $generator->combined($members, $data['subject'], $body);
            $now = now();
            foreach ($members->chunk(200) as $chunk) {
                DB::table('communication_deliveries')->insert($chunk->map(fn (Member $member): array => [
                    'campaign_id' => $campaign->id,
                    ...$this->delivery($member, 'generated'),
                    'created_at' => $now,
                ])->all());
            }
            $campaign->update(['success_count' => $members->count()]);
        } catch (Throwable $exception) {
            report($exception);
            $campaign->update(['failure_count' => $members->count()]);
            throw ValidationException::withMessages(['recipients' => 'Die Serienbriefe konnten nicht erzeugt werden.']);
        }
        $filename = 'Serienbriefe-'.now()->format('Y-m-d-His').'.'.$data['format'];
        if ($data['format'] === 'zip') {
            return response()->download($result, $filename, [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ])->deleteFileAfterSend(true);
        }

        return response($result, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, mixed> */
    private function delivery(Member $member, string $status): array
    {
        return [
            'member_id' => $member->id,
            'member_number' => $member->member_number,
            'recipient_name' => collect([$member->first_name, $member->middle_name, $member->last_name])->filter()->join(' '),
            'recipient_email' => $member->email,
            'status' => $status,
            'error' => null,
        ];
    }

    /** @param list<array{name: string, mime: string, size: int}> $attachments
     * @param  array<string, mixed>  $filters
     */
    private function campaign(CommunicationRequest $request, string $kind, ?string $format, string $subject, string $body, array $attachments, array $filters, int $recipients, int $skipped): CommunicationCampaign
    {
        return CommunicationCampaign::query()->create([
            'kind' => $kind,
            'format' => $format,
            'subject' => $subject,
            'body' => $body,
            'attachments' => $attachments,
            'filters' => $filters,
            'recipient_count' => $recipients,
            'skipped_count' => $skipped,
            'success_count' => 0,
            'failure_count' => 0,
            'created_by' => $request->user()->id,
            'created_by_name' => $request->user()->name,
            'created_at' => now(),
        ]);
    }

    /** @return list<array{name: string, mime: string, contents: string}> */
    private function attachments(CommunicationRequest $request): array
    {
        $files = $request->file('attachments', []);
        $attachments = [];
        foreach (is_array($files) ? $files : [] as $file) {
            $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', basename($file->getClientOriginalName())) ?: 'Anhang';
            $contents = $file->get();
            if (! is_string($contents)) {
                throw ValidationException::withMessages(['attachments' => 'Ein Anhang konnte nicht gelesen werden.']);
            }

            $attachments[] = [
                'name' => mb_substr($name, 0, 180),
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'contents' => $contents,
            ];
        }

        return $attachments;
    }

    /** @return list<string> */
    private function options(string $column): array
    {
        return array_values(Member::query()->whereNotNull($column)->where($column, '<>', '')
            ->distinct()->orderBy($column)->pluck($column)->map(fn ($value): string => (string) $value)->all());
    }

    /** @param Builder<Member> $query
     * @return Builder<Member>
     */
    private function withValue($query, string $column)
    {
        return $query->whereNotNull($column)->where($column, '<>', '');
    }
}
