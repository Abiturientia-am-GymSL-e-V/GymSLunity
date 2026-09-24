<?php

namespace Tests\Feature\Members;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MemberImportTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(array $roles = ['mv']): void
    {
        $this->actingAs(User::factory()->create(['roles' => $roles]));
    }

    public function test_editor_can_download_current_csv_template(): void
    {
        $this->signIn();
        $response = $this->get(route('members.import.template'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBFmember_number;", $csv);
        $this->assertStringContainsString(';first_name;', $csv);
        $this->assertStringContainsString(';custom_graduation_year;', $csv);
    }

    public function test_valid_csv_is_previewed_before_atomic_import(): void
    {
        $this->signIn();
        $csv = implode("\r\n", [
            'member_number;first_name;last_name;membership_type;is_honorary',
            '8101;Ada;Lovelace;Fördermitglied;nein',
            '8102;Grace;Hopper;Aktiv/ordentliches Mitglied;ja',
        ]);

        $preview = $this->post(route('members.import.preview'), [
            'csv' => UploadedFile::fake()->createWithContent('mitglieder.csv', $csv),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('members', 0);
        parse_str(parse_url($preview->headers->get('Location'), PHP_URL_QUERY) ?: '', $query);
        $token = $query['token'];

        $this->get(route('members.import.index', ['token' => $token]))->assertInertia(fn (Assert $page) => $page
            ->component('members/Import')->where('preview.errorCount', 0)->has('preview.rows', 2)
            ->where('preview.rows.0.member_number', 8101)->where('preview.rows.0.name', 'Ada Lovelace'));

        $this->post(route('members.import.store'), ['token' => $token])
            ->assertSessionHasNoErrors()->assertRedirect(route('members.index'));
        $this->assertDatabaseCount('members', 2);
        $this->assertFalse(Member::query()->where('member_number', 8101)->sole()->is_honorary);
        $this->assertTrue(Member::query()->where('member_number', 8102)->sole()->is_honorary);
    }

    public function test_preview_reports_row_and_header_errors_without_importing(): void
    {
        $this->signIn();
        Member::factory()->create(['member_number' => 8101]);
        $csv = implode("\n", [
            'member_number;first_name;last_name;membership_type;is_honorary;birth_date',
            '8101;Ada;Lovelace;Fördermitglied;nein;1990-01-01',
            '8102;Grace;;Unbekannt;vielleicht;2099-01-01',
            '8102;Alan;Turing;Fördermitglied;nein;1912-06-23',
        ]);
        $response = $this->post(route('members.import.preview'), [
            'csv' => UploadedFile::fake()->createWithContent('fehler.csv', $csv),
        ])->assertSessionHasNoErrors()->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY) ?: '', $query);

        $this->get(route('members.import.index', ['token' => $query['token']]))->assertInertia(fn (Assert $page) => $page
            ->where('preview.errorCount', 3)->has('preview.rows.0.errors')->has('preview.rows.1.errors')->has('preview.rows.2.errors'));
        $this->post(route('members.import.store'), ['token' => $query['token']])->assertSessionHasErrors('form');
        $this->assertDatabaseCount('members', 1);

        $duplicateHeader = 'member_number;first_name;first_name;last_name;membership_type;is_honorary'."\n".'9;A;A;B;Fördermitglied;nein';
        $this->post(route('members.import.preview'), ['csv' => UploadedFile::fake()->createWithContent('falsch.csv', $duplicateHeader)])
            ->assertSessionHasErrors('csv');
    }

    public function test_foreign_csv_columns_can_be_mapped_before_preview_and_import(): void
    {
        $this->signIn();
        $csv = implode("\r\n", [
            'Interne Nr.,Vorname,Nachname,Mitgliedsart,Ehrenmitglied,Abschlussjahr,Fremdes Feld',
            '9201,Jörg,Müller,Fördermitglied,nein,2012,wird ignoriert',
        ]);
        $windowsCsv = mb_convert_encoding($csv, 'Windows-1252', 'UTF-8');

        $response = $this->post(route('members.import.preview'), [
            'csv' => UploadedFile::fake()->createWithContent('fremdsystem.csv', $windowsCsv),
        ])->assertSessionHasNoErrors()->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY) ?: '', $query);
        $token = $query['token'];

        $this->get(route('members.import.index', ['token' => $token]))->assertInertia(fn (Assert $page) => $page
            ->where('preview', null)
            ->where('mapping.delimiter', 'Komma')
            ->where('mapping.headers.0', 'Interne Nr.')
            ->where('mapping.suggested.1.target', 'first_name')
            ->where('mapping.suggested.2.target', 'last_name')
            ->where('mapping.suggested.3.target', 'membership_type')
            ->where('mapping.suggested.4.target', 'is_honorary')
            ->where('mapping.samples.0.values.Vorname', 'Jörg'));

        $mapping = [
            ['source' => 'Interne Nr.', 'target' => 'member_number'],
            ['source' => 'Vorname', 'target' => 'first_name'],
            ['source' => 'Nachname', 'target' => 'last_name'],
            ['source' => 'Mitgliedsart', 'target' => 'membership_type'],
            ['source' => 'Ehrenmitglied', 'target' => 'is_honorary'],
            ['source' => 'Abschlussjahr', 'target' => 'custom_graduation_year'],
            ['source' => 'Fremdes Feld', 'target' => null],
        ];
        $this->post(route('members.import.map'), ['token' => $token, 'mapping' => $mapping])
            ->assertSessionHasNoErrors()->assertRedirect(route('members.import.index', ['token' => $token]));

        $this->get(route('members.import.index', ['token' => $token]))->assertInertia(fn (Assert $page) => $page
            ->where('mapping', null)
            ->where('preview.mapped', true)
            ->where('preview.errorCount', 0)
            ->where('preview.rows.0.member_number', 9201)
            ->where('preview.rows.0.name', 'Jörg Müller'));
        $this->post(route('members.import.store'), ['token' => $token])->assertRedirect(route('members.index'));

        $member = Member::query()->where('member_number', 9201)->sole();
        $this->assertSame('Jörg', $member->first_name);
        $this->assertSame('Müller', $member->last_name);
        $this->assertSame(2012, $member->custom_values['custom_graduation_year']);
    }

    public function test_mapping_rejects_duplicate_targets_and_missing_required_fields(): void
    {
        $this->signIn();
        $csv = "Nummer;Vorname;Nachname\n9301;Ada;Lovelace";
        $response = $this->post(route('members.import.preview'), [
            'csv' => UploadedFile::fake()->createWithContent('mapping.csv', $csv),
        ])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY) ?: '', $query);

        $this->from(route('members.import.index', ['token' => $query['token']]))
            ->post(route('members.import.map'), [
                'token' => $query['token'],
                'mapping' => [
                    ['source' => 'Nummer', 'target' => 'member_number'],
                    ['source' => 'Vorname', 'target' => 'first_name'],
                    ['source' => 'Nachname', 'target' => 'first_name'],
                ],
            ])->assertSessionHasErrors('mapping');

        $this->assertDatabaseCount('members', 0);
    }

    public function test_import_requires_member_creation_permission(): void
    {
        $this->signIn(['auditor']);
        $this->get(route('members.import.index'))->assertForbidden();
        $this->get(route('members.import.template'))->assertForbidden();
        $this->post(route('members.import.preview'))->assertForbidden();
        $this->post(route('members.import.map'))->assertForbidden();
        $this->post(route('members.import.store'), ['token' => str_repeat('a', 48)])->assertForbidden();
    }
}
