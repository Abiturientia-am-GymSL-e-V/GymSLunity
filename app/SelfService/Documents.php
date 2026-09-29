<?php

declare(strict_types=1);

namespace App\SelfService;

use App\Configuration\ClubSettings;
use App\Members\MemberReportWriter;
use App\Models\Member;
use App\Security\MemberDocumentStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class Documents
{
    public function __construct(private readonly ClubSettings $clubSettings, private readonly MemberDocumentStore $documentStore) {}

    public function store(Member $member, string $kind, string $signature, ?string $guardian, ?string $guardianName, Request $request): string
    {
        $settings = $this->clubSettings;
        $pdf = MemberReportWriter::dompdf();
        $pdf->loadHtml(view('selfservice.document', [
            'application' => $kind === 'application' ? DB::table('membership_applications')->where('member_id', $member->id)->first(['membership_type']) : null,
            'member' => $member, 'kind' => $kind, 'signature' => $signature,
            'guardian' => $guardian, 'guardianName' => $guardianName,
            'club' => $settings->data(), 'logo' => $settings->logoDataUri(), 'texts' => FormTemplates::rendered(),
            'timestamp' => now()->setTimezone(config('app.display_timezone'))->format('d.m.Y H:i:s T'), 'ip' => $request->ip(),
        ])->render());
        $pdf->setPaper('A4');
        $pdf->render();
        $contents = $pdf->output();
        $this->documentStore->store($member->id, $kind, $contents, true, $kind === 'sepa' ? [
            'mandate_reference' => $member->mandate_reference,
            'mandate_signed_at' => $member->mandate_signed_at?->format('Y-m-d'),
        ] : []);

        return $contents;
    }
}
