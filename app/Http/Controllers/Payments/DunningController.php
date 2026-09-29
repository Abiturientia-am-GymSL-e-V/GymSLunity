<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Configuration\ClubSettings;
use App\Configuration\MailConfigurator;
use App\Http\Controllers\Controller;
use App\Mail\DunningNoticeMail;
use App\Models\Member;
use App\Payments\DunningNotices;
use App\Security\SafeCsv;
use App\Support\Clock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DunningController extends Controller
{
    public function __construct(private readonly ClubSettings $clubSettings) {}

    public function export(DunningNotices $notices): StreamedResponse
    {
        $members = $notices->members();

        return response()->streamDownload(function () use ($members): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Mitgliedsnummer', 'Nachname', 'Vorname', 'E-Mail', 'Offene Posten', 'Offener Betrag', 'Älteste Fälligkeit'], ';', '"', '', "\r\n");
            foreach ($members as $member) {
                $contributions = $member->contributionAccount->contributions;
                $openCents = $contributions->sum(fn ($item): int => $item->remainingCents());
                fputcsv($output, array_map([SafeCsv::class, 'value'], [
                    $member->member_number,
                    $member->last_name,
                    $member->first_name,
                    $member->email,
                    $contributions->count(),
                    number_format($openCents / 100, 2, ',', ''),
                    $contributions->first()?->due_date->format('d.m.Y'),
                ]), ';', '"', '', "\r\n");
            }
            fclose($output);
        }, 'offene-beitraege-'.Clock::localNow()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function letters(Request $request, DunningNotices $notices): Response
    {
        $data = $this->selection($request);
        $members = $notices->members($data['member_numbers']);

        return response($notices->pdf($members), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="zahlungserinnerungen-'.Clock::localNow()->format('Y-m-d-His').'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function document(Request $request, Member $member, DunningNotices $notices): Response
    {
        $format = $request->validate([
            'format' => ['nullable', 'in:pdf,print'],
        ])['format'] ?? 'pdf';
        $documentMember = $notices->member($member->member_number);
        abort_unless($documentMember instanceof Member, 404);
        $members = new Collection([$documentMember]);

        if ($format === 'print') {
            return response($notices->html($members, true), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'",
            ]);
        }

        return response($notices->pdf($members), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="zahlungserinnerung-'.$documentMember->member_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function table(Request $request, DunningNotices $notices): Response
    {
        $search = trim((string) ($request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ])['q'] ?? ''));
        $rows = collect($notices->rows())
            ->when($search !== '', fn ($rows) => $rows->filter(fn (array $row): bool => Str::contains(
                Str::lower($row['member_name'].' '.$row['member_number'].' '.($row['email'] ?? '')),
                Str::lower($search),
            )))
            ->values()
            ->all();
        $settings = $this->clubSettings;
        $club = $settings->data();
        $logo = $settings->logoDataUri();
        $printedAt = now()->setTimezone(config('app.display_timezone'));

        return response(view('payments.open-contributions-print', compact('rows', 'club', 'logo', 'printedAt'))->render(), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'",
        ]);
    }

    public function send(Request $request, DunningNotices $notices, MailConfigurator $mailConfigurator): RedirectResponse
    {
        $data = $this->selection($request);
        $members = $notices->members($data['member_numbers']);
        $missingEmails = $members->filter(fn ($member): bool => blank($member->email))->count();
        if ($missingEmails > 0) {
            throw ValidationException::withMessages([
                'member_numbers' => $missingEmails.' ausgewählte Mitglieder haben keine E-Mail-Adresse.',
            ]);
        }

        $mailConfigurator->applyStored();
        foreach ($members as $member) {
            Mail::to($member->email)->send(new DunningNoticeMail($member));
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $members->count().' Zahlungserinnerungen versendet.',
        ]);

        return back();
    }

    /** @return array{member_numbers: list<int>} */
    private function selection(Request $request): array
    {
        $data = $request->validate([
            'member_numbers' => ['required', 'array', 'min:1', 'max:100'],
            'member_numbers.*' => ['integer', 'distinct', 'exists:members,member_number'],
        ]);

        return [
            'member_numbers' => array_values(array_map(
                static fn (int|string $memberNumber): int => (int) $memberNumber,
                $data['member_numbers'],
            )),
        ];
    }
}
