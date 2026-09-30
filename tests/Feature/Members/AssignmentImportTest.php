<?php

declare(strict_types=1);

namespace Tests\Feature\Members;

use App\Models\Member;
use App\Models\MemberAssignment;
use App\Models\MemberChange;
use App\Models\MemberFieldDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssignmentImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-15 12:00:00');
        MemberFieldDefinition::query()->create([
            'key' => 'custom_honor', 'label' => 'Ehrungen', 'type' => 'honor', 'section' => MemberFieldDefinition::ASSIGNMENT_SECTION,
            'position' => 1000, 'is_active' => true, 'is_custom' => true, 'required' => false, 'filterable' => false, 'show_in_table' => false,
            'selfservice_visible' => false, 'selfservice_editable' => false, 'allow_multiple' => false, 'max_length' => 255,
            'options' => [['value' => 'gold', 'label' => 'Ehrennadel Gold', 'active' => true]],
        ]);
        Member::factory()->create(['member_number' => 1, 'first_name' => 'Ada', 'middle_name' => null, 'last_name' => 'Lovelace', 'joined_at' => '1990-01-01']);
        Member::factory()->create(['member_number' => 2, 'first_name' => 'Alan', 'middle_name' => null, 'last_name' => 'Turing', 'joined_at' => '1990-01-01']);
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('zuordnungen.csv', $content);
    }

    private function preview(string $content): string
    {
        $response = $this->post(route('members.assignment-import.preview'), ['csv' => $this->csv($content)])->assertSessionHasNoErrors()->assertRedirect();
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return (string) $query['token'];
    }

    public function test_the_preview_applies_all_rules_without_storing_anything(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $token = $this->preview(implode("\n", [
            'Mitgliedsnummer;Feld;Auswahl;Von;Bis;Notiz',
            '1;Funktion im Hauptverein;1. Vorsitzender;01.01.2010;31.12.2019;Wahl 2010',
            '2;club_role;1. vorsitzender;2020-01-01;;',
            '1;Funktion im Hauptverein;1. Vorsitzender;01.01.2020;;',
            '99;club_role;Kassierer;;;',
            '1;Unbekannt;x;;;',
            '1;club_role;Präsident;;;',
            '1;club_role;Kassierer;31.02.2020;;',
            '2;Ehrungen;Ehrennadel Gold;2025-05-01;2025-06-01;',
            '2;Ehrungen;Ehrennadel Gold;2025-05-01;;',
        ]));

        $this->get(route('members.assignment-import.index', ['token' => $token]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('members/ImportAssignments')
            ->has('preview.rows', 9)
            ->where('preview.errorCount', 6)
            ->where('preview.rows.0.errors', [])
            ->where('preview.rows.0.name', 'Ada Lovelace')
            ->where('preview.rows.0.option_label', '1. Vorsitzender')
            ->where('preview.rows.0.starts_on', '2010-01-01')
            ->where('preview.rows.1.errors', [])
            // Adjacent to the row above: the rules of the member record apply within the file.
            ->where('preview.rows.2.errors.0', fn (string $error): bool => str_contains($error, 'direkt angrenzenden Zeitraum'))
            ->where('preview.rows.3.errors', ['Kein Mitglied mit der Nummer „99“.'])
            ->where('preview.rows.4.errors.0', fn (string $error): bool => str_starts_with($error, 'Unbekanntes Feld'))
            ->where('preview.rows.5.errors', ['„Präsident“ ist keine aktive Auswahl von „Funktion im Hauptverein“.'])
            ->where('preview.rows.6.errors', ['„Von“ ist kein gültiges Datum (TT.MM.JJJJ oder JJJJ-MM-TT).'])
            ->where('preview.rows.7.errors', ['Ereignisse und Ehrungen haben kein Ende.'])
            ->where('preview.rows.8.errors', []));
        $this->assertSame(0, MemberAssignment::query()->count());
        $this->assertSame(0, MemberChange::query()->count());
        $this->assertSame(0, Member::query()->sum('lock_version'));

        $this->post(route('members.assignment-import.store'), ['token' => $token])->assertSessionHasErrors('form');
        $this->assertSame(0, MemberAssignment::query()->count());
    }

    public function test_a_clean_file_is_imported_completely_with_history(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['vereinsverwaltung']]));
        $token = $this->preview("\xEF\xBB\xBFnr,feld,amt,beginn,ende,bemerkung\n1,club_role,Kassierer,01.01.2000,31.12.2009,\n1,club_role,1. Vorsitzender,2010-01-01,,Wahl\n2,custom_honor,gold,2024-03-01,,\n");

        $this->post(route('members.assignment-import.store'), ['token' => $token])->assertRedirect(route('members.assignment-import.index'))
            ->assertInertiaFlash('toast.message', '3 Zuordnungen wurden importiert.');
        $this->assertSame(3, MemberAssignment::query()->where('source', 'import')->count());
        $this->assertDatabaseHas('member_assignments', ['field_key' => 'club_role', 'option_value' => '1. Vorsitzender', 'starts_on' => '2010-01-01', 'ends_on' => null, 'note' => 'Wahl']);
        $this->assertSame(2, MemberChange::query()->where('member_id', Member::query()->where('member_number', 1)->value('id'))->count());
        // The preview is used up.
        $this->post(route('members.assignment-import.store'), ['token' => $token])->assertSessionHasErrors('form');

        // Meanwhile recorded conflicts stop the whole import.
        $token = $this->preview("Mitgliedsnummer;Feld;Auswahl;Von\n2;club_role;Kassierer;2026-01-01\n2;department_role;Delegierte;2026-01-01\n");
        MemberAssignment::query()->create(['member_id' => Member::query()->where('member_number', 2)->value('id'), 'field_key' => 'department_role', 'option_value' => 'Delegierte', 'starts_on' => '2025-01-01']);
        $this->post(route('members.assignment-import.store'), ['token' => $token])->assertSessionHasErrors('form');
        $this->assertStringStartsWith('Zeile 3: ', session('errors')->first('form'));
        $this->assertSame(0, MemberAssignment::query()->where('option_value', 'Kassierer')->where('starts_on', '2026-01-01')->count());
    }

    public function test_import_requires_write_access_and_known_columns(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['auditor']]));
        $this->get(route('members.assignment-import.index'))->assertForbidden();
        $this->post(route('members.assignment-import.preview'), ['csv' => $this->csv("Mitgliedsnummer;Feld;Auswahl\n1;club_role;Kassierer\n")])->assertForbidden();

        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $this->get(route('members.assignment-import.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('columns', ['Mitgliedsnummer', 'Feld', 'Auswahl', 'Von', 'Bis', 'Notiz'])
            ->where('preview', null)
            ->where('can.manageAssignments', true));
        $this->assertStringContainsString('Mitgliedsnummer;Feld;Auswahl;Von;Bis;Notiz', $this->get(route('members.assignment-import.template'))->assertOk()->streamedContent());
        $this->post(route('members.assignment-import.preview'), ['csv' => $this->csv("Nummer;Feld\n1;club_role\n")])->assertSessionHasErrors(['csv' => 'Die Spalte „Auswahl“ fehlt. Erwartet werden die Spalten Mitgliedsnummer, Feld, Auswahl, Von, Bis, Notiz.']);
    }
}
