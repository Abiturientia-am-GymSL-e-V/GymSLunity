<?php

namespace Tests\Feature\Members;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        Member::factory()->create(['first_name' => 'Formel', 'last_name' => '=HYPERLINK("x") <Test>', 'postal_code' => '01234']);

        $pdf = $this->post(route('members.export'), $this->data(['format' => 'pdf']));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $html = $this->post(route('members.export'), $this->data(['format' => 'print']))->assertOk()->getContent();
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
            } finally {
                $archive->close();
                unlink($temp);
            }
        }
    }
}
