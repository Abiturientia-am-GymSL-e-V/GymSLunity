<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Members\MemberAssignments;
use App\Members\MemberFields;
use App\Models\ClubSetting;
use App\Models\Member;
use App\Models\MemberAssignment;
use App\Models\MemberChange;
use App\Models\MemberFieldDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\WithSchoolMemberFields;
use Tests\TestCase;

class MemberConfigurationTest extends TestCase
{
    use RefreshDatabase;
    use WithSchoolMemberFields;

    private function admin(): User
    {
        $user = User::factory()->create(['roles' => ['admin']]);
        $this->actingAs($user);

        return $user;
    }

    private function fieldData(array $overrides = []): array
    {
        return [...[
            'version' => ClubSetting::current()->fields_version, 'label' => 'Trainingsgruppe', 'type' => 'select', 'section' => 'membership',
            'is_active' => true, 'required' => false, 'filterable' => true, 'show_in_table' => true,
            'selfservice_visible' => false,
            'selfservice_editable' => false,
            'options' => [['value' => 'a', 'label' => 'Gruppe A', 'active' => true], ['value' => 'b', 'label' => 'Gruppe B', 'active' => false]],
        ], ...$overrides];
    }

    private function createField(array $overrides = []): MemberFieldDefinition
    {
        $this->post(route('configuration.fields.store'), $this->fieldData($overrides))->assertSessionHasNoErrors()->assertRedirect();

        return MemberFieldDefinition::query()->latest('id')->firstOrFail();
    }

    public function test_configuration_requires_a_verified_active_administrator(): void
    {
        $this->get(route('configuration.club.edit'))->assertRedirect(route('login'));
        foreach (['mv', 'vereinsverwaltung', 'auditor', 'bh'] as $role) {
            $this->actingAs(User::factory()->create(['roles' => [$role]]));
            foreach (['club.edit', 'fields.index', 'users.index'] as $route) {
                $this->get(route('configuration.'.$route))->assertForbidden();
            }
            $this->post(route('configuration.fields.store'), $this->fieldData())->assertForbidden();
            $this->patch(route('configuration.club.update'), ['version' => 0, 'name' => 'Verboten'])->assertForbidden();
            $this->post(route('configuration.users.store'), [])->assertForbidden();
        }
        $this->actingAs(User::factory()->unverified()->create(['roles' => ['admin']]))->get(route('configuration.club.edit'))->assertRedirect(route('verification.notice'));
        $this->admin();
        $this->get(route('configuration.club.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.manageConfiguration', true));
    }

    public function test_club_data_is_validated_audited_and_protected_against_stale_updates(): void
    {
        $actor = $this->admin();
        $this->patch(route('configuration.club.update'), ['version' => 0, 'name' => 'Sportverein', 'country' => 'DE', 'iban' => 'DE89 3704 0044 0532 0130 00'])->assertSessionHasNoErrors();
        $this->assertSame('DE89370400440532013000', ClubSetting::current()->data['iban']);
        $this->assertSame(1, ClubSetting::current()->version);
        $this->assertDatabaseHas('configuration_changes', ['actor_id' => $actor->id, 'subject' => 'Vereinsdaten']);
        $this->patch(route('configuration.club.update'), ['version' => 0, 'name' => 'Überschreiben'])->assertSessionHasErrors('version');
        $this->patch(route('configuration.club.update'), ['version' => 1, 'name' => 'Sportverein', 'iban' => 'DE123456789'])->assertSessionHasErrors('iban');
        $this->assertSame('Sportverein', ClubSetting::current()->data['name']);
    }

    public function test_global_form_of_address_can_be_configured(): void
    {
        $this->admin();

        $this->get(route('configuration.club.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('formOfAddress', 'du')
            ->where('fields.2.key', 'form_of_address')
            ->where('fields.2.options', ['du' => 'Du', 'sie' => 'Sie'])
            ->where('fields.2.default', 'du'));

        $this->patch(route('configuration.club.update'), [
            'version' => 0,
            'name' => 'Sportverein',
            'form_of_address' => 'sie',
        ])->assertSessionHasNoErrors();

        $this->assertSame('sie', ClubSetting::current()->data['form_of_address']);
        $this->get(route('configuration.club.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('formOfAddress', 'sie'));

        $this->patch(route('configuration.club.update'), [
            'version' => 1,
            'name' => 'Sportverein',
            'form_of_address' => 'anders',
        ])->assertSessionHasErrors('form_of_address');
    }

    public function test_configuration_pages_render_with_bounded_preload_headers(): void
    {
        $this->admin();
        foreach (['club.edit', 'fields.index', 'users.index'] as $route) {
            $response = $this->get(route('configuration.'.$route))->assertOk();
            $header = $response->headers->get('Link', '');
            $this->assertLessThanOrEqual(6, substr_count($header, 'rel='));
            $this->assertLessThan(2500, strlen($header));
        }
    }

    public function test_custom_fields_are_editable_typed_and_audited_with_their_original_labels(): void
    {
        $this->admin();
        $field = $this->createField();
        $amount = $this->createField(['label' => 'Trainingsstunden', 'type' => 'decimal', 'options' => []]);
        $member = Member::factory()->create();
        $url = route('members.update', $member->member_number);
        $this->patch($url, ['lock_version' => 0, 'configuration_version' => 2, $field->key => 'a', $amount->key => '12,50'])->assertSessionHasNoErrors();
        $this->assertSame('12.50', $member->fresh()->custom_values[$amount->key]);
        $change = MemberChange::sole();
        $this->assertSame('Trainingsgruppe', $change->field_schema[$field->key]['label']);
        $this->assertSame('Gruppe A', $change->field_schema[$field->key]['options']['a']);
        $this->patch(route('configuration.fields.update', $field), $this->fieldData(['label' => 'Neue Bezeichnung']))->assertSessionHasNoErrors();
        $this->assertSame('Trainingsgruppe', $change->fresh()->field_schema[$field->key]['label']);
        $this->patch($url, ['lock_version' => 1, 'configuration_version' => 2, 'city' => 'Veraltet'])->assertSessionHasErrors('form');
        $this->patch($url, ['lock_version' => 1, $field->key => 'b'])->assertSessionHasErrors($field->key);
        $this->patch($url, ['lock_version' => 1, $amount->key => '12.999'])->assertSessionHasErrors($amount->key);
        $this->assertDatabaseCount('member_changes', 1);
    }

    public function test_retired_values_remain_visible_and_unchanged_savable_but_cannot_be_assigned_again(): void
    {
        $this->admin();
        $field = $this->createField();
        $member = Member::factory()->create(['custom_values' => [$field->key => 'b']]);
        $this->patch(route('members.update', $member->member_number), ['lock_version' => 0, $field->key => 'b', 'city' => 'Berlin'])->assertSessionHasNoErrors();
        $this->patch(route('configuration.fields.update', $field), $this->fieldData(['options' => [['value' => 'a', 'label' => 'Gruppe A', 'active' => true]]]))->assertSessionHasNoErrors();
        $this->assertSame(['value' => 'b', 'label' => 'Gruppe B', 'active' => false], $field->fresh()->options[1]);
        $this->patch(route('members.update', $member->member_number), ['lock_version' => 1, $field->key => 'a'])->assertSessionHasNoErrors();
        $this->patch(route('members.update', $member->member_number), ['lock_version' => 2, $field->key => 'b'])->assertSessionHasErrors($field->key);
    }

    public function test_archived_fields_cannot_be_written_and_their_data_survives_other_edits(): void
    {
        $this->admin();
        $field = $this->createField(['type' => 'text', 'options' => []]);
        $member = Member::factory()->create(['custom_values' => [$field->key => 'Alte Angabe']]);
        $this->patch(route('configuration.fields.update', $field), $this->fieldData(['type' => 'text', 'options' => [], 'is_active' => false]))->assertSessionHasNoErrors();
        $this->patch(route('members.update', $member->member_number), ['lock_version' => 0, 'city' => 'Bonn'])->assertSessionHasNoErrors();
        $this->assertSame('Alte Angabe', $member->fresh()->custom_values[$field->key]);
        $this->patch(route('members.update', $member->member_number), ['lock_version' => 1, $field->key => 'Überschreiben'])->assertSessionHasErrors('form');
        $this->get(route('members.show', $member->member_number))->assertInertia(fn (Assert $page) => $page->where('sections.5.title', 'Archivierte Angaben')->where('sections.5.fields.0.readOnly', true));
        $this->get(route('members.index'))->assertInertia(fn (Assert $page) => $page->missing('members.data.0.custom_values.'.$field->key));
    }

    public function test_schema_changes_guard_existing_data_required_core_fields_and_order(): void
    {
        $this->admin();
        $field = $this->createField(['type' => 'number', 'options' => []]);
        Member::factory()->create(['custom_values' => [$field->key => 0]]);
        $this->patch(route('configuration.fields.update', $field), $this->fieldData(['type' => 'date', 'options' => []]))->assertSessionHasErrors('type');
        $core = MemberFieldDefinition::query()->where('key', 'first_name')->sole();
        $this->patch(route('configuration.fields.update', $core), $this->fieldData(['type' => 'text', 'options' => [], 'is_active' => false]))->assertSessionHasErrors('is_active');
        $ids = MemberFieldDefinition::query()->orderBy('position')->pluck('id')->reverse()->values()->all();
        $this->patch(route('configuration.fields.reorder'), ['version' => 1, 'ids' => $ids])->assertSessionHasNoErrors();
        $this->assertSame($ids, MemberFieldDefinition::query()->orderBy('position')->pluck('id')->all());
        $this->patch(route('configuration.fields.reorder'), ['version' => 1, 'ids' => $ids])->assertSessionHasErrors('version');
        $this->patch(route('configuration.fields.reorder'), ['version' => 2, 'ids' => array_slice($ids, 1)])->assertSessionHasErrors('ids');
    }

    public function test_custom_number_filter_and_sort_are_numeric_and_boolean_false_survives(): void
    {
        $this->admin();
        $field = $this->createField(['type' => 'number', 'options' => []]);
        $flag = $this->createField(['type' => 'boolean', 'options' => []]);
        foreach ([100, 2, 20] as $number) {
            Member::factory()->create(['first_name' => 'Zahl'.$number, 'custom_values' => [$field->key => $number, $flag->key => false]]);
        }
        $this->get(route('members.index', ['sort' => $field->key]))->assertInertia(fn (Assert $page) => $page->where('members.data.0.first_name', 'Zahl2')->where('members.data.2.first_name', 'Zahl100'));
        $this->get(route('members.index', ['custom' => [$field->key => '20', $flag->key => '0']]))->assertInertia(fn (Assert $page) => $page->where('members.total', 1)->where('members.data.0.first_name', 'Zahl20'));
        $this->getJson(route('members.index', ['custom' => ['custom_unknown' => 'x']]))->assertUnprocessable()->assertJsonValidationErrors('custom');
    }

    public function test_defaults_are_generic_and_payment_options_are_configurable(): void
    {
        $sections = MemberFields::sections();
        $this->assertSame('Mitgliedschaft', $sections[2]['title']);
        $membership = MemberFieldDefinition::query()->where('key', 'membership_type')->sole();
        $this->assertContains('Aktiv/ordentliches Mitglied', array_column($membership->options, 'value'));
        $this->assertContains('Fördermitglied', array_column($membership->options, 'value'));
        $payment = MemberFieldDefinition::query()->where('key', 'payment_method')->sole();
        $this->assertSame(['SEPA-Lastschrift', 'Überweisung', 'Bar', 'Sonstiges'], array_column($payment->options, 'value'));
        $this->assertTrue($payment->selfservice_editable);
        $this->assertTrue($payment->selfservice_visible);
        $this->assertTrue(MemberFieldDefinition::query()->where('key', 'gender')->sole()->selfservice_editable);
        $this->assertTrue(MemberFieldDefinition::query()->where('key', 'gender')->sole()->selfservice_visible);
        $this->assertFalse(MemberFieldDefinition::query()->where('key', 'club_role')->sole()->selfservice_editable);
        $this->assertFalse(MemberFieldDefinition::query()->where('key', 'club_role')->sole()->selfservice_visible);
        $this->assertTrue(MemberFieldDefinition::query()->where('key', 'custom_graduation_year')->sole()->is_custom);
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('members', 'graduation_year'));
    }

    public function test_selfservice_visibility_and_editability_are_independent_but_protected_fields_remain_read_only(): void
    {
        $this->admin();
        $field = $this->createField(['selfservice_visible' => true]);
        $this->assertTrue($field->selfservice_visible);
        $this->assertFalse($field->selfservice_editable);

        $this->patch(route('configuration.fields.update', $field), $this->fieldData([
            'selfservice_visible' => false,
            'selfservice_editable' => true,
        ]))->assertSessionHasErrors('selfservice_editable');

        $this->patch(route('configuration.fields.update', $field), $this->fieldData([
            'selfservice_visible' => true,
            'selfservice_editable' => true,
        ]))->assertSessionHasNoErrors();
        $this->assertTrue($field->fresh()->selfservice_editable);

        $email = MemberFieldDefinition::query()->where('key', 'email')->sole();
        $this->patch(route('configuration.fields.update', $email), $this->fieldData([
            'label' => $email->label,
            'type' => $email->type,
            'section' => $email->section,
            'options' => [],
            'selfservice_visible' => true,
            'selfservice_editable' => false,
        ]))->assertSessionHasNoErrors();
        $this->assertTrue($email->fresh()->selfservice_visible);

        $this->patch(route('configuration.fields.update', $email), $this->fieldData([
            'label' => $email->label,
            'type' => $email->type,
            'section' => $email->section,
            'options' => [],
            'selfservice_visible' => true,
            'selfservice_editable' => true,
        ]))->assertSessionHasErrors('selfservice_editable');
        $this->assertFalse($email->fresh()->selfservice_editable);
    }

    public function test_temporal_fields_get_their_own_section_and_type_specific_option_attributes(): void
    {
        $this->admin();
        $office = $this->createField([
            'label' => 'Vorstand', 'type' => 'office', 'section' => 'membership', 'required' => true, 'filterable' => true,
            'show_in_table' => true, 'selfservice_visible' => true, 'selfservice_editable' => true, 'allow_multiple' => true,
            'options' => [
                ['value' => 'chair', 'label' => '1. Vorsitz', 'active' => true, 'board' => true, 'mandatory' => true, 'max_holders' => 1, 'repeatable' => true],
                ['value' => 'auditor', 'label' => 'Kassenprüfung', 'active' => true],
            ],
        ]);

        $this->assertSame('office', $office->type);
        $this->assertSame(MemberFieldDefinition::ASSIGNMENT_SECTION, $office->section);
        $this->assertTrue($office->allow_multiple);
        $this->assertFalse($office->required || $office->selfservice_editable);
        // Filters, table columns and the read-only portal display work with the current assignments.
        $this->assertTrue($office->filterable && $office->show_in_table && $office->selfservice_visible);
        $this->assertSame([
            ['value' => 'chair', 'label' => '1. Vorsitz', 'active' => true, 'board' => true, 'mandatory' => true, 'max_holders' => 1],
            ['value' => 'auditor', 'label' => 'Kassenprüfung', 'active' => true, 'board' => false, 'mandatory' => false, 'max_holders' => null],
        ], $office->options);

        $honor = $this->createField(['label' => 'Ehrungen', 'type' => 'honor', 'allow_multiple' => true, 'options' => [['value' => 'gold', 'label' => 'Ehrennadel Gold', 'active' => true, 'repeatable' => true, 'board' => true]]]);
        $this->assertFalse($honor->allow_multiple);
        $this->assertSame([['value' => 'gold', 'label' => 'Ehrennadel Gold', 'active' => true, 'repeatable' => true]], $honor->options);

        $select = $this->createField(['options' => [['value' => 'a', 'label' => 'Gruppe A', 'active' => true, 'board' => true]]]);
        $this->assertSame([['value' => 'a', 'label' => 'Gruppe A', 'active' => true]], $select->options);
        $this->post(route('configuration.fields.store'), $this->fieldData(['section' => MemberFieldDefinition::ASSIGNMENT_SECTION]))->assertSessionHasErrors('section');
        $this->post(route('configuration.fields.store'), $this->fieldData(['type' => 'office', 'options' => [['value' => 'x', 'label' => 'X', 'active' => true, 'max_holders' => 0]]]))->assertSessionHasErrors('options.0.max_holders');
        $this->assertNotContains($office->key, MemberFields::writable());
    }

    public function test_temporal_field_types_and_used_options_are_protected(): void
    {
        $actor = $this->admin();
        $field = $this->createField(['label' => 'Abteilungen', 'type' => 'department', 'options' => [
            ['value' => 'football', 'label' => 'Fußball', 'active' => true], ['value' => 'gym', 'label' => 'Turnen', 'active' => true],
            ['value' => 'chess', 'label' => 'Schach', 'active' => true],
        ]]);
        $member = Member::factory()->create();
        $assignments = app(MemberAssignments::class);
        $assignments->add($member, $actor, 0, $field->key, ['option' => 'football', 'starts_on' => '2020-01-01']);
        $assignments->add($member, $actor, 1, $field->key, ['option' => 'gym', 'starts_on' => '2020-01-01']);
        $assignments->delete($member, $actor, 2, MemberAssignment::query()->where('option_value', 'gym')->sole());
        $update = fn (array $overrides) => $this->patch(route('configuration.fields.update', $field), $this->fieldData([
            'label' => 'Abteilungen', 'type' => 'department', 'options' => [['value' => 'football', 'label' => 'Fußball', 'active' => true]], ...$overrides,
        ]));

        $update(['type' => 'office'])->assertSessionHasErrors('type');
        $update(['remove_options' => ['gym']])->assertSessionHasErrors('options');
        $update(['remove_options' => ['chess']])->assertSessionHasNoErrors();
        $this->assertSame(['football' => true, 'gym' => false], array_column($field->fresh()->options, 'active', 'value'));
        $update(['options' => [], 'remove_options' => ['gym']])->assertSessionHasErrors('options');
        $update(['options' => [], 'remove_options' => ['football']])->assertSessionHasErrors('options');
    }
}
