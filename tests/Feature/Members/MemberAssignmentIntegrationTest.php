<?php

declare(strict_types=1);

namespace Tests\Feature\Members;

use App\Communication\CommunicationTemplate;
use App\Members\CurrentAssignments;
use App\Members\MemberAssignments;
use App\Members\MemberFieldFilter;
use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\MemberAssignment;
use App\Models\MemberFieldDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\WithSchoolMemberFields;
use Tests\TestCase;

class MemberAssignmentIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use WithSchoolMemberFields;

    private MemberFieldDefinition $board;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-15 12:00:00');
        $this->board = MemberFieldDefinition::query()->create([
            'key' => 'custom_board', 'label' => 'Vorstand', 'type' => 'office',
            'section' => MemberFieldDefinition::ASSIGNMENT_SECTION, 'position' => 1000, 'is_active' => true, 'is_custom' => true,
            'required' => false, 'filterable' => true, 'show_in_table' => true, 'selfservice_visible' => true,
            'selfservice_editable' => false, 'allow_multiple' => true, 'max_length' => 255,
            'options' => [
                ['value' => 'chair', 'label' => '1. Vorsitz', 'active' => true, 'board' => true],
                ['value' => 'deputy', 'label' => '2. Vorsitz', 'active' => true, 'board' => true],
                ['value' => 'treasurer', 'label' => 'Kasse', 'active' => true, 'board' => true],
            ],
        ]);
    }

    private function assign(Member $member, string $option, ?string $from, ?string $to = null): void
    {
        MemberAssignment::query()->create([
            'member_id' => $member->id, 'field_key' => 'custom_board', 'option_value' => $option,
            'starts_on' => $from, 'ends_on' => $to, 'source' => 'manual',
        ]);
    }

    /** @return array{current: Member, former: Member, future: Member, none: Member} */
    private function members(): array
    {
        $members = [
            'current' => Member::factory()->create(['member_number' => 101, 'last_name' => 'Aktuell']),
            'former' => Member::factory()->create(['member_number' => 102, 'last_name' => 'Früher']),
            'future' => Member::factory()->create(['member_number' => 103, 'last_name' => 'Künftig']),
            'none' => Member::factory()->create(['member_number' => 104, 'last_name' => 'Ohne']),
        ];
        $this->assign($members['current'], 'treasurer', '2020-01-01');
        $this->assign($members['current'], 'chair', null);
        $this->assign($members['current'], 'deputy', '2010-01-01', '2019-12-31');
        $this->assign($members['former'], 'chair', '2015-01-01', '2026-06-14');
        $this->assign($members['future'], 'chair', '2026-06-16');

        return $members;
    }

    public function test_member_directory_filters_by_current_any_none_and_ever_and_lists_current_options(): void
    {
        $this->members();
        $this->actingAs(User::factory()->create(['roles' => ['auditor']]));
        $numbers = fn (string $value): array => collect($this->get(route('members.index', ['custom' => ['custom_board' => $value], 'sort' => 'member_number']))
            ->assertOk()->viewData('page')['props']['members']['data'])->pluck('member_number')->all();

        $this->assertSame([101], $numbers('chair'));
        $this->assertSame([101], $numbers(CurrentAssignments::ANY));
        $this->assertSame([102, 103, 104], $numbers(CurrentAssignments::NONE));
        $this->assertSame([101, 102, 103], $numbers(CurrentAssignments::EVER));
        $this->assertSame([101, 102, 103], $numbers(CurrentAssignments::EVER_PREFIX.'chair'));
        $this->assertSame([101], $numbers(CurrentAssignments::EVER_PREFIX.'deputy'));

        $this->get(route('members.index', ['sort' => 'member_number']))->assertInertia(fn (Assert $page) => $page
            ->where('members.data.0.assignments.custom_board', ['treasurer', 'chair'])
            ->missing('members.data.3.assignments.custom_board')
            ->where('fieldDefinitions', fn ($fields): bool => $fields->contains(fn (array $field): bool => $field['key'] === 'custom_board' && $field['showInTable'] && $field['filterable'])));

        foreach (['chair' => [101], CurrentAssignments::NONE => [102, 103, 104], CurrentAssignments::EVER_PREFIX.'chair' => [101, 102, 103]] as $value => $expected) {
            $query = Member::query()->orderBy('member_number');
            MemberFieldFilter::apply($query, [['key' => 'custom_board', 'value' => $value, 'value_to' => '']]);
            $this->assertSame($expected, $query->pluck('member_number')->all());
        }
    }

    public function test_placeholders_exports_and_signature_lists_use_the_current_options_in_rank_order(): void
    {
        $members = $this->members();
        $templates = app(CommunicationTemplate::class);
        $this->assertContains('{{mitglied.custom_board}}', array_column($templates->placeholders(), 'token'));
        $this->assertSame('Vorstand: 1. Vorsitz, Kasse', $templates->render('Vorstand: {{mitglied.custom_board}}', $members['current']));
        $this->assertSame('Vorstand: ', $templates->render('Vorstand: {{mitglied.custom_board}}', $members['former']));

        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $csv = $this->post(route('members.export'), ['format' => 'csv', 'scope' => 'filtered', 'sort' => 'member_number', 'columns' => ['member_number', 'custom_board']])
            ->assertOk()->streamedContent();
        $this->assertStringContainsString("Mitgliedsnummer;Vorstand\r\n101;\"1. Vorsitz, Kasse\"\r\n102;\r\n", $csv);

        $this->get(route('forms.signature-lists.index'))->assertInertia(fn (Assert $page) => $page
            ->where('members', function ($rows): bool {
                $tokens = $rows->keyBy('member_number')->map(fn (array $row) => $row['filter_values']['custom_board'] ?? null);

                return in_array('chair', $tokens[101], true) && in_array(CurrentAssignments::ANY, $tokens[101], true)
                    && in_array(CurrentAssignments::NONE, $tokens[102], true) && in_array(CurrentAssignments::EVER_PREFIX.'chair', $tokens[102], true)
                    && ! in_array('chair', $tokens[103], true) && $tokens[104] === null;
            })
            ->where('filterFields', fn ($fields): bool => $fields->contains('key', 'custom_board')));
    }

    public function test_member_card_lists_assignments_and_their_history(): void
    {
        $member = Member::factory()->create();
        $actor = User::factory()->create(['roles' => ['mv']]);
        app(MemberAssignments::class)->add($member, $actor, $member->fresh()->lock_version, 'custom_board', ['option' => 'chair', 'starts_on' => '2020-01-01', 'note' => 'Wahl JHV']);

        $card = $this->actingAs($actor)->get(route('members.card', $member->member_number))->assertOk()->getContent();
        $this->assertStringContainsString('Abteilungen, Ämter &amp; Ehrungen', $card);
        $this->assertStringContainsString('1. Vorsitz (seit 01.01.2020) – Wahl JHV', $card);
    }

    public function test_csv_import_creates_assignments_from_the_import_date(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        $csv = implode("\r\n", [
            'member_number;first_name;last_name;membership_type;is_honorary;custom_board',
            '8101;Ada;Lovelace;Fördermitglied;nein;1. Vorsitz, treasurer',
            '8102;Grace;Hopper;Fördermitglied;nein;',
        ]);
        $token = $this->previewToken($csv);
        $this->get(route('members.import.index', ['token' => $token]))->assertInertia(fn (Assert $page) => $page->where('preview.errorCount', 0));
        $this->post(route('members.import.store'), ['token' => $token])->assertSessionHasNoErrors();

        $ada = Member::query()->where('member_number', 8101)->sole();
        $this->assertSame([['chair', '2026-06-15', null, 'import'], ['treasurer', '2026-06-15', null, 'import']], $ada->assignments()->orderBy('id')->get()
            ->map(fn (MemberAssignment $assignment): array => [$assignment->option_value, $assignment->startsOn(), $assignment->endsOn(), $assignment->source])->all());
        $this->assertNull($ada->custom_values['custom_board'] ?? null);
        $this->assertSame(2, $ada->lock_version);
        $this->assertSame(0, Member::query()->where('member_number', 8102)->sole()->assignments()->count());

        $this->board->update(['allow_multiple' => false]);
        $token = $this->previewToken(implode("\r\n", [
            'member_number;first_name;last_name;membership_type;is_honorary;custom_board',
            '8103;Alan;Turing;Fördermitglied;nein;Präsidium',
            '8104;Hedy;Lamarr;Fördermitglied;nein;1. Vorsitz, Kasse',
        ]));
        $this->get(route('members.import.index', ['token' => $token]))->assertInertia(fn (Assert $page) => $page
            ->where('preview.errorCount', 2)
            ->where('preview.rows.0.errors', ['Vorstand: „Präsidium“ ist keine aktive Auswahl.'])
            ->where('preview.rows.1.errors', ['Vorstand: Es ist nur ein Amt gleichzeitig erlaubt.']));
    }

    private function previewToken(string $csv): string
    {
        $preview = $this->post(route('members.import.preview'), ['csv' => UploadedFile::fake()->createWithContent('mitglieder.csv', $csv)])
            ->assertSessionHasNoErrors()->assertRedirect();
        parse_str(parse_url($preview->headers->get('Location'), PHP_URL_QUERY) ?: '', $query);

        return $query['token'];
    }

    public function test_portal_shows_current_assignments_read_only(): void
    {
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'selfservice_enabled' => true, 'name' => 'Testverein']]);
        $member = $this->members()['current'];
        $session = ['selfservice' => ['email' => strtolower((string) $member->email), 'member_id' => $member->id, 'until' => time() + 1800]];

        $this->withSession($session)->get('/selfservice')->assertInertia(fn (Assert $page) => $page
            ->component('selfservice/Portal')
            ->where('member.custom_board', '1. Vorsitz, Kasse')
            ->where('profileSections', fn ($sections): bool => $sections->contains(fn (array $section): bool => $section['key'] === MemberFieldDefinition::ASSIGNMENT_SECTION
                && $section['fields'][0]['key'] === 'custom_board' && $section['fields'][0]['readOnly'] === true)));

        $this->withSession($session)->patch('/selfservice/profil', ['lock_version' => $member->lock_version, 'custom_board' => 'deputy']);
        $this->assertSame(3, $member->assignments()->count());
        $this->assertNull($member->fresh()->custom_values['custom_board'] ?? null);

        $this->board->update(['selfservice_visible' => false]);
        $this->withSession($session)->get('/selfservice')->assertInertia(fn (Assert $page) => $page->missing('member.custom_board'));
    }
}
