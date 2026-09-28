<?php

declare(strict_types=1);

namespace App\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class MemberDocumentStore
{
    public function uploadedPdf(UploadedFile $file, string $field = 'document'): string
    {
        if (! $file->isValid() || $file->getSize() === false || $file->getSize() > 10 * 1024 * 1024) {
            throw ValidationException::withMessages([$field => 'Die PDF-Datei ist beschädigt oder größer als 10 MB.']);
        }
        $contents = $file->getContent();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if ($mime !== 'application/pdf' || ! str_starts_with($contents, '%PDF-') || ! str_contains(substr($contents, -2048), '%%EOF')) {
            throw ValidationException::withMessages([$field => 'Die Datei ist keine vollständig lesbare PDF-Datei.']);
        }
        if (preg_match('/\/(JavaScript|JS|Launch|EmbeddedFile|RichMedia|OpenAction|AA)\b/i', $contents) === 1) {
            throw ValidationException::withMessages([$field => 'PDFs mit aktiven Inhalten oder eingebetteten Dateien sind nicht zulässig.']);
        }

        return $contents;
    }

    /** @param array{mandate_reference?: string|null, mandate_signed_at?: string|null} $metadata */
    public function store(int $memberId, string $kind, string $contents, bool $submittedOnline, array $metadata = []): int
    {
        $sealed = Crypt::encryptString($contents);
        $sequence = $kind === 'sepa'
            ? ((int) DB::table('member_documents')->where('member_id', $memberId)->where('kind', $kind)->max('sequence')) + 1
            : 1;
        $values = [
            'member_id' => $memberId,
            'kind' => $kind,
            'sequence' => $sequence,
            'mandate_reference' => $metadata['mandate_reference'] ?? null,
            'mandate_signed_at' => $metadata['mandate_signed_at'] ?? null,
            'revoked_at' => null,
            'revocation_reason' => null,
            'submitted_online' => $submittedOnline,
            'encrypted' => true,
            'content_sha256' => hash('sha256', $contents),
            'contents' => $sealed,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if ($kind === 'sepa') {
            return DB::table('member_documents')->insertGetId($values);
        }
        DB::table('member_documents')->updateOrInsert(
            ['member_id' => $memberId, 'kind' => $kind, 'sequence' => 1],
            $values,
        );

        return (int) DB::table('member_documents')
            ->where('member_id', $memberId)
            ->where('kind', $kind)
            ->value('id');
    }

    public function read(mixed $stored, bool $encrypted = false, ?string $checksum = null): string
    {
        $stored = is_resource($stored) ? stream_get_contents($stored) : $stored;
        if (! is_string($stored)) {
            throw new RuntimeException('Document contents cannot be read.');
        }
        $contents = $encrypted ? Crypt::decryptString($stored) : $stored;
        if (is_string($checksum) && $checksum !== '' && ! hash_equals($checksum, hash('sha256', $contents))) {
            throw new RuntimeException('Document integrity check failed.');
        }
        if (! str_starts_with($contents, '%PDF-')) {
            throw new RuntimeException('Stored document is not a PDF.');
        }

        return $contents;
    }
}
