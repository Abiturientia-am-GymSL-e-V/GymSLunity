<?php

namespace App\SelfService;

use App\Models\ClubSetting;
use App\Models\Member;
use App\Security\MemberDocumentStore;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class Documents
{
    public function __construct(private readonly MemberDocumentStore $documentStore) {}

    public function store(Member $member, string $kind, string $signature, ?string $guardian, ?string $guardianName, Request $request): void
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('selfservice.document', [
            'application' => $kind === 'application' ? DB::table('membership_applications')->where('member_id', $member->id)->first(['membership_type']) : null,
            'member' => $member, 'kind' => $kind, 'signature' => $signature,
            'guardian' => $guardian, 'guardianName' => $guardianName,
            'club' => ClubSetting::current()->data, 'texts' => FormTemplates::rendered(),
            'timestamp' => now()->format('d.m.Y H:i:s T'), 'ip' => $request->ip(),
        ])->render());
        $pdf->setPaper('A4');
        $pdf->render();
        $this->documentStore->store($member->id, $kind, $pdf->output(), true);
    }
}
