<?php

declare(strict_types=1);

namespace App\Http\Controllers\SelfService;

use App\Http\Controllers\Controller;
use App\Security\MemberDocumentStore;
use App\SelfService\Access;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Members download their own signed application or current SEPA mandate. */
class DocumentController extends Controller
{
    public function __invoke(Request $request, string $kind, MemberDocumentStore $documents): Response
    {
        $member = Access::member($request);
        abort_unless(in_array($kind, ['application', 'sepa'], true), 404);
        if ($kind === 'sepa') {
            abort_unless($member->payment_method === 'SEPA-Lastschrift', 404);
        }
        $document = DB::table('member_documents')
            ->where('member_id', $member->id)
            ->where('kind', $kind)
            ->when($kind === 'sepa', fn ($query) => $query
                ->whereNull('revoked_at')
                ->where('mandate_reference', $member->mandate_reference))
            ->latest('id')
            ->first(['contents', 'encrypted', 'content_sha256']);
        abort_unless($document !== null, 404);
        try {
            $record = (array) $document;
            $contents = $documents->read(
                $record['contents'] ?? null,
                (bool) ($record['encrypted'] ?? false),
                is_string($record['content_sha256'] ?? null) ? $record['content_sha256'] : null,
            );
        } catch (\Throwable) {
            abort(422, 'Das hinterlegte Dokument ist beschädigt oder nicht lesbar.');
        }

        return response($contents, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$kind.'-'.$member->member_number.'.pdf"', 'Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }
}
