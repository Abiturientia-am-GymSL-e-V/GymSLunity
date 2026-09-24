<?php

namespace Tests\Feature\Members;

use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MemberExportTest extends TestCase
{
    use RefreshDatabase;

    private function data(array $overrides = []): array
    {
        return ['format' => 'csv', 'scope' => 'filtered', 'columns' => ['member_number', 'last_name', 'postal_code'], ...$overrides];
    }

    public function test_exports_require_the_same_permissions_as_the_directory(): void
    {
        $this->post(route('members.export'), $this->data())->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['roles' => ['bh']]))->post(route('members.export'), $this->data())->assertForbidden();
        $this->actingAs(User::factory()->create(['roles' => ['auditor']]))->post(route('members.export'), $this->data())->assertOk();
    }

    public function test_export_includes_all_filtered_pages_with_stable_sort_and_only_requested_columns(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        Member::factory()->count(12)->sequence(fn ($sequence) => ['member_number' => 9900001 + $sequence->index, 'last_name' => 'Exportprobe', 'postal_code' => '01234'])->create();
        Member::factory()->create(['last_name' => 'Nicht enthalten']);
        $response = $this->post(route('members.export'), $this->data(['format' => 'json', 'q' => 'Exportprobe', 'per_page' => 10, 'sort' => 'member_number', 'direction' => 'desc']));
        $response->assertOk()->assertHeader('content-type', 'application/json; charset=UTF-8');
        $rows = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(12, $rows);
        $this->assertSame(9900012, $rows[0]['member_number']);
        $this->assertSame('01234', $rows[0]['postal_code']);
        $this->assertSame(['member_number', 'last_name', 'postal_code'], array_keys($rows[0]));
        $this->assertSame(9900001, $rows[11]['member_number']);
    }

    public function test_selection_is_limited_to_requested_members_and_filters(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $a = Member::factory()->create(['last_name' => 'Auswahl']);
        $b = Member::factory()->create(['last_name' => 'Außerhalb']);
        Member::factory()->create(['last_name' => 'Auswahl']);
        $response = $this->post(route('members.export'), $this->data(['format' => 'json', 'scope' => 'selected', 'selected' => [$a->member_number, $b->member_number], 'q' => 'Auswahl']));
        $rows = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $rows);
        $this->assertSame($a->member_number, $rows[0]['member_number']);
        $this->postJson(route('members.export'), $this->data(['scope' => 'selected', 'selected' => []]))->assertUnprocessable()->assertJsonValidationErrors('selected');
    }

    public function test_csv_escapes_quotes_separators_newlines_and_spreadsheet_formulas(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        Member::factory()->create(['last_name' => "=HYPERLINK(\"test\");\nNeue Zeile", 'postal_code' => '01234']);
        $response = $this->post(route('members.export'), $this->data());
        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $this->assertSame(['Mitgliedsnummer', 'Nachname', 'Postleitzahl'], fgetcsv($stream, escape: '', separator: ';'));
        $row = fgetcsv($stream, escape: '', separator: ';');
        $this->assertSame("'=HYPERLINK(\"test\");\nNeue Zeile", $row[1]);
        $this->assertSame('01234', $row[2]);
        $this->assertFalse(fgetcsv($stream, escape: '', separator: ';'));
        fclose($stream);
    }

    public function test_export_rejects_sensitive_columns_and_unknown_fields(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['admin']]));
        foreach (['iban', 'contents', 'custom_unconfigured', 'password'] as $column) {
            $this->postJson(route('members.export'), $this->data(['columns' => [$column]]))->assertUnprocessable()->assertJsonValidationErrors('columns.0');
        }
    }

    public function test_office_pdf_and_print_exports_preserve_visible_column_scope_and_escape_values(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-22 20:15:00 UTC'));
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        Storage::fake('local');
        $logoPath = 'branding/logo-11111111-1111-1111-1111-111111111111.png';
        Storage::disk('local')->put($logoPath, UploadedFile::fake()->image('logo.png', 120, 60)->get());
        ClubSetting::current()->update(['data' => ['name' => 'Turnverein Musterstadt', 'logo_path' => $logoPath]]);
        Member::factory()->create(['first_name' => 'Formel', 'last_name' => '=HYPERLINK("x") <Test>', 'postal_code' => '01234']);

        $pdf = $this->post(route('members.export'), $this->data(['format' => 'pdf']));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $html = $this->post(route('members.export'), $this->data(['format' => 'print']))->assertOk()->getContent();
        $this->assertStringContainsString('<title>Turnverein Musterstadt · Mitgliederliste</title>', $html);
        $this->assertStringContainsString('<h1>Turnverein Musterstadt · Mitgliederliste</h1>', $html);
        $this->assertStringContainsString('<img class="report-logo" src="data:image/png;base64,', $html);
        $this->assertStringContainsString('Stand 22.09.2026 22:15 CEST', $html);
        $this->assertStringContainsString('Mitgliedsnummer', $html);
        $this->assertStringContainsString('01234', $html);
        $this->assertStringContainsString('&lt;Test&gt;', $html);
        $this->assertStringNotContainsString('>password<', $html);

        foreach (['xlsx' => 'xl/sharedStrings.xml', 'docx' => 'word/document.xml'] as $format => $path) {
            $response = $this->post(route('members.export'), $this->data(['format' => $format]));
            $response->assertOk();
            $stream = $response->streamedContent();
            $this->assertStringStartsWith('PK', $stream);
            $archive = new \ZipArchive;
            $temp = tempnam(sys_get_temp_dir(), 'member-export-test-');
            try {
                file_put_contents($temp, $stream);
                $this->assertSame(true, $archive->open($temp));
                $xml = $archive->getFromName($path);
                $this->assertIsString($xml);
                $this->assertStringContainsString('01234', $xml);
                $this->assertStringContainsString('HYPERLINK', $xml);
                $this->assertStringNotContainsString('<Test>', $xml);
                if ($format === 'docx') {
                    $image = false;
                    for ($index = 0; $index < $archive->numFiles; $index++) {
                        $entry = $archive->getNameIndex($index);
                        if (is_string($entry) && str_starts_with($entry, 'word/media/')) {
                            $image = $archive->getFromIndex($index);
                            break;
                        }
                    }
                    $this->assertIsString($image);
                    $this->assertStringStartsWith("\x89PNG", $image);
                }
            } finally {
                $archive->close();
                unlink($temp);
            }
        }
    }
}
