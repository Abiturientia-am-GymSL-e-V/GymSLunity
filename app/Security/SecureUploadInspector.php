<?php

declare(strict_types=1);

namespace App\Security;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use ZipArchive;

final class SecureUploadInspector
{
    public function __construct(private readonly MemberDocumentStore $documents) {}

    public function inspectAttachment(UploadedFile $file): void
    {
        $mime = $file->getMimeType();
        if ($mime === 'application/pdf') {
            $this->documents->uploadedPdf($file, 'attachments');

            return;
        }
        if (in_array($mime, [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ], true)) {
            $this->inspectOfficeArchive($file);
        }
    }

    private function inspectOfficeArchive(UploadedFile $file): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Office-Anhänge können auf diesem Server nicht sicher geprüft werden.');
        }
        $archive = new ZipArchive;
        if ($archive->open($file->getPathname(), ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Der Office-Anhang ist beschädigt.');
        }
        try {
            if ($archive->numFiles > 1000) {
                throw new RuntimeException('Der Office-Anhang enthält zu viele Dateien.');
            }
            $expanded = 0;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $stat = $archive->statIndex($index);
                $name = is_array($stat) ? $stat['name'] : '';
                $expanded += is_array($stat) ? $stat['size'] : 0;
                if ($expanded > 25 * 1024 * 1024 || str_contains($name, '../') || str_starts_with($name, '/') || preg_match('/vbaProject\.bin$/i', $name)) {
                    throw new RuntimeException('Der Office-Anhang enthält nicht zulässige oder übermäßig große Inhalte.');
                }
            }
        } finally {
            $archive->close();
        }
    }
}
