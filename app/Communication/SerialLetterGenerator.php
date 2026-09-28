<?php

declare(strict_types=1);

namespace App\Communication;

use App\Members\MemberReportWriter;
use App\Models\ClubSetting;
use App\Models\Member;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

final class SerialLetterGenerator
{
    public function __construct(private readonly CommunicationTemplate $templates) {}

    /** @param Collection<int, Member> $members */
    public function combined(Collection $members, string $subject, string $body): string
    {
        return MemberReportWriter::pdf($this->html($members, $subject, $body), false);
    }

    /** @param Collection<int, Member> $members */
    public function zip(Collection $members, string $subject, string $body): string
    {
        $directory = storage_path('framework/cache/communication');
        File::ensureDirectoryExists($directory, 0770);
        $path = tempnam($directory, 'letters-');
        if ($path === false) {
            throw new RuntimeException('Das ZIP-Archiv konnte nicht vorbereitet werden.');
        }
        $archive = new ZipArchive;
        if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            File::delete($path);
            throw new RuntimeException('Das ZIP-Archiv konnte nicht geöffnet werden.');
        }
        try {
            foreach ($members as $member) {
                $pdf = MemberReportWriter::pdf($this->html(new Collection([$member]), $subject, $body), false);
                $name = Str::slug($member->last_name.'-'.$member->first_name) ?: 'mitglied';
                if (! $archive->addFromString('Serienbrief-'.$member->member_number.'-'.$name.'.pdf', $pdf)) {
                    throw new RuntimeException('Ein Serienbrief konnte nicht in das ZIP-Archiv geschrieben werden.');
                }
            }
            if (! $archive->close()) {
                throw new RuntimeException('Das ZIP-Archiv konnte nicht abgeschlossen werden.');
            }
        } catch (\Throwable $exception) {
            $archive->close();
            File::delete($path);

            throw $exception;
        }

        return $path;
    }

    /** @param Collection<int, Member> $members */
    private function html(Collection $members, string $subject, string $body): string
    {
        $settings = ClubSetting::current();
        $letters = $members->map(fn (Member $member): array => [
            'member_number' => $member->member_number,
            'address' => $this->templates->render('{{mitglied.adresse}}', $member),
            'subject' => $this->templates->render($subject, $member),
            'body' => $this->templates->renderHtml($body, $member),
        ])->all();

        return view('communication.letters', [
            'letters' => $letters,
            'club' => $settings->data,
            'logo' => $settings->logoDataUri(),
        ])->render();
    }
}
