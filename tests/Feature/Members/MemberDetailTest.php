<?php

declare(strict_types=1);

namespace Tests\Feature\Members;

use App\Members\MemberFields;
use App\Members\MemberNavigation;
use App\Members\UpdateMember;
use App\Models\Member;
use App\Models\MemberChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class MemberDetailTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(array $roles = ['mv']): User
    {
        $user = User::factory()->create(['roles' => $roles]);
        $this->actingAs($user);

        return $user;
    }

    private function url(Member $member, string $action = 'show'): string
    {
        return route('members.'.$action, ['member' => $member->member_number]);
    }

    private function document(Member $member, string $kind, string $contents): void
    {
        DB::table('member_documents')->insert([
            'member_id' => $member->id, 'kind' => $kind, 'contents' => $contents,
            'submitted_online' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_detail_exposes_all_fields_but_never_document_bytes(): void
    {
        $this->signIn();
        $member = Member::factory()->create(['birth_date' => '1990-01-03', 'gender' => 'w', 'iban' => 'DE89370400440532013000', 'sponsor_contribution' => '42.50']);
        $this->document($member, 'application', "%PDF-1.4\nprivate-bytes\xFF");
        $this->get($this->url($member))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('members/Show')->where('canEdit', true)
            ->has('member', count(MemberFields::writable()) + 5)
            ->where('member.birth_date', '1990-01-03')->where('member.gender', 'w')
            ->where('member.iban', 'DE89370400440532013000')->where('member.sponsor_contribution', '42.50')
            ->where('member.lock_version', 0)->where('history.total', 0)
            ->has('sections', 6)->has('documents', 1)->where('documents.0.kind', 'application')
            ->missing('documents.0.contents')->where('returnTo', '/mitglieder'));
    }

    public function test_permissions_apply_to_details_updates_and_document_downloads(): void
    {
        $member = Member::factory()->create();
        $docUrl = route('members.document', ['member' => $member->member_number, 'kind' => 'application']);
        $this->document($member, 'application', '%PDF-1.4 test');
        $this->get($this->url($member))->assertRedirect(route('login'));
        $this->patch($this->url($member, 'update'), ['lock_version' => 0])->assertRedirect(route('login'));
        $this->get($docUrl)->assertRedirect(route('login'));
        foreach ([[], ['bh'], ['bm'], ['kp']] as $roles) {
            $this->signIn($roles);
            $this->get($this->url($member))->assertForbidden();
            $this->patch($this->url($member, 'update'), ['lock_version' => 0])->assertForbidden();
            $this->get($docUrl)->assertForbidden();
        }
        $this->signIn(['auditor']);
        $this->get($this->url($member))->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
        $this->get($docUrl)->assertOk();
        $this->patch($this->url($member, 'update'), ['lock_version' => 0, 'city' => 'Verboten'])->assertForbidden();
        $this->assertDatabaseCount('member_changes', 0);
        foreach (['admin', 'vereinsverwaltung', 'mv'] as $role) {
            $this->signIn([$role]);
            $this->get($this->url($member))->assertInertia(fn (Assert $page) => $page->where('canEdit', true));
        }
        $this->actingAs(User::factory()->unverified()->create(['roles' => ['admin']]));
        $this->patch($this->url($member, 'update'), ['lock_version' => 0])->assertRedirect(route('verification.notice'));
        $this->get($docUrl)->assertRedirect(route('verification.notice'));
    }

    public function test_update_records_actor_snapshots_and_only_changed_fields(): void
    {
        $actor = $this->signIn();
        $member = Member::factory()->create(['city' => 'Alter Ort', 'street' => 'Alte Straße 1', 'postal_code' => '01234']);
        $this->patch($this->url($member, 'update'), ['lock_version' => 0, 'city' => 'Neuer Ort'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $current = $member->fresh();
        $this->assertSame('Neuer Ort', $current->city);
        $this->assertSame('Alte Straße 1', $current->street);
        $this->assertSame('01234', $current->postal_code);
        $this->assertSame(1, $current->lock_version);
        $change = MemberChange::sole();
        $this->assertSame($actor->id, $change->actor_id);
        $this->assertSame($actor->name, $change->actor_name);
        $this->assertSame(['city'], $change->changed_fields);
        $this->assertSame('Alter Ort', $change->before['city']);
        $this->assertSame('Neuer Ort', $change->after['city']);
        $this->assertSame('01234', $change->before['postal_code']);
        $actor->update(['name' => 'Neuer Benutzername']);
        $this->assertSame($change->actor_name, $change->fresh()->actor_name);
    }

    public function test_administration_payment_change_revokes_active_sepa_mandate(): void
    {
        $this->signIn();
        $member = Member::factory()->create([
            'payment_method' => 'SEPA-Lastschrift',
            'iban' => 'DE89370400440532013000',
            'mandate_reference' => 'M-ADMIN-OLD',
            'mandate_signed_at' => '2026-01-10',
            'account_holder_first_name' => 'Ada',
            'account_holder_last_name' => 'Test',
        ]);
        $this->document($member, 'sepa', '%PDF-1.4 mandate');
        DB::table('member_documents')->where('member_id', $member->id)->where('kind', 'sepa')->update([
            'mandate_reference' => 'M-ADMIN-OLD',
            'mandate_signed_at' => '2026-01-10',
        ]);

        $this->patch($this->url($member, 'update'), [
            'lock_version' => 0,
            'payment_method' => 'Überweisung',
        ])->assertSessionHasNoErrors();

        $member->refresh();
        $this->assertSame('Überweisung', $member->payment_method);
        $this->assertNull($member->iban);
        $this->assertNull($member->mandate_reference);
        $this->assertNull($member->mandate_signed_at);
        $this->assertNotNull(DB::table('member_documents')->where('member_id', $member->id)->where('kind', 'sepa')->value('revoked_at'));
        $this->get($this->url($member))->assertInertia(fn (Assert $page) => $page
            ->has('mandates', 1)
            ->where('mandates.0.active', false)
            ->where('mandates.0.mandate_reference', 'M-ADMIN-OLD'));
    }

    public function test_historical_mandate_download_is_scoped_to_its_member(): void
    {
        $this->signIn();
        $member = Member::factory()->create();
        $other = Member::factory()->create();
        $this->document($member, 'sepa', '%PDF-1.4 mandate history');
        $documentId = DB::table('member_documents')
            ->where('member_id', $member->id)
            ->where('kind', 'sepa')
            ->value('id');

        $this->get(route('members.mandates.document', [
            'member' => $member->member_number,
            'document' => $documentId,
        ]))->assertOk()->assertContent('%PDF-1.4 mandate history');
        $this->get(route('members.mandates.document', [
            'member' => $other->member_number,
            'document' => $documentId,
        ]))->assertNotFound();
    }

    public function test_both_pdf_blobs_remain_byte_identical_after_normal_member_update(): void
    {
        $this->signIn();
        $member = Member::factory()->create();
        $pdf = "%PDF-1.4\n".str_repeat("binary\0\xFF\xFE", 20000);
        foreach (['application', 'sepa'] as $kind) {
            $this->document($member, $kind, $pdf.$kind);
        }
        $before = DB::table('member_documents')->where('member_id', $member->id)->get();
        $this->patch($this->url($member, 'update'), ['lock_version' => 0, 'street' => 'Neue Straße 2'])->assertSessionHasNoErrors();
        $after = DB::table('member_documents')->where('member_id', $member->id)->get();
        $this->assertEquals($before, $after);
        foreach (['application', 'sepa'] as $kind) {
            $this->get(route('members.document', ['member' => $member->member_number, 'kind' => $kind]))
                ->assertOk()->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('X-Content-Type-Options', 'nosniff')->assertContent($pdf.$kind);
        }
        $this->assertStringNotContainsString('binary', json_encode(MemberChange::sole()->toArray()));
    }

    public function test_editor_can_upload_and_replace_member_documents(): void
    {
        $this->signIn();
        $member = Member::factory()->create();
        $url = route('members.documents.store', ['member' => $member->member_number, 'kind' => 'application']);

        $first = "%PDF-1.4\nfirst\n%%EOF";
        $this->post($url, ['document' => UploadedFile::fake()->createWithContent('antrag.pdf', $first)])
            ->assertSessionHasNoErrors()->assertRedirect();
        $stored = DB::table('member_documents')->where('member_id', $member->id)->where('kind', 'application')->first();
        $this->assertTrue((bool) $stored->encrypted);
        $this->assertSame(hash('sha256', $first), $stored->content_sha256);
        $this->assertNotSame($first, $stored->contents);
        $this->get(route('members.document', ['member' => $member->member_number, 'kind' => 'application']))->assertOk()->assertContent($first);

        $replacement = "%PDF-1.4\nreplacement\n%%EOF";
        $this->post($url, ['document' => UploadedFile::fake()->createWithContent('neu.pdf', $replacement)])
            ->assertSessionHasNoErrors();
        $this->assertNotSame($replacement, DB::table('member_documents')->where('member_id', $member->id)->where('kind', 'application')->value('contents'));
        $this->get(route('members.document', ['member' => $member->member_number, 'kind' => 'application']))->assertOk()->assertContent($replacement);
        $this->assertDatabaseCount('member_documents', 1);

        $this->postJson($url, ['document' => UploadedFile::fake()->createWithContent('notiz.txt', 'not a pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->post(route('members.documents.store', ['member' => $member->member_number, 'kind' => 'unknown']), [
            'document' => UploadedFile::fake()->createWithContent('datei.pdf', "%PDF-1.4\ninvalid kind"),
        ])->assertNotFound();
    }

    public function test_rejects_manipulated_protected_fields_and_missing_version(): void
    {
        $this->signIn();
        $member = Member::factory()->create();
        foreach (['id' => 42, 'member_number' => 42, 'contents' => 'broken', 'beitritt_pdf' => 'broken', 'mandat_pdf' => 'broken', 'actor_id' => 42, 'updated_at' => '2000-01-01'] as $key => $value) {
            $this->patchJson($this->url($member, 'update'), ['lock_version' => 0, 'city' => 'Verboten', $key => $value])->assertUnprocessable()->assertJsonValidationErrors('form');
        }
        $this->patchJson($this->url($member, 'update'), ['city' => 'Verboten'])->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        $this->assertSame(0, $member->fresh()->lock_version);
        $this->assertDatabaseCount('member_changes', 0);
    }

    public function test_no_op_does_not_create_history_or_increment_version(): void
    {
        $this->signIn();
        $member = Member::factory()->create(['sponsor_contribution' => '12.50', 'birth_date' => '1990-01-01', 'is_honorary' => true]);
        $values = array_intersect_key(MemberFields::snapshot($member->fresh()), array_flip(MemberFields::writable()));
        $values['sponsor_contribution'] = '12,50';
        $this->patch($this->url($member, 'update'), [...$values, 'lock_version' => 0])->assertSessionHasNoErrors();
        $this->assertSame(0, $member->fresh()->lock_version);
        $this->assertDatabaseCount('member_changes', 0);
        $this->assertEquals($member->updated_at, $member->fresh()->updated_at);
    }

    public function test_stale_edit_cannot_overwrite_another_users_changes(): void
    {
        $this->signIn();
        $member = Member::factory()->create(['city' => 'Alt']);
        $this->patch($this->url($member, 'update'), ['lock_version' => 0, 'city' => 'Erste Änderung'])->assertSessionHasNoErrors();
        $this->signIn();
        $this->patchJson($this->url($member, 'update'), ['lock_version' => 0, 'city' => 'Veraltete Änderung'])
            ->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        $this->assertSame('Erste Änderung', $member->fresh()->city);
        $this->assertDatabaseCount('member_changes', 1);
    }

    public function test_history_failure_rolls_back_member_and_version(): void
    {
        $actor = $this->signIn();
        $member = Member::factory()->create(['city' => 'Alt']);
        $event = 'eloquent.creating: '.MemberChange::class;
        Event::listen($event, fn () => throw new RuntimeException('Simulated audit failure'));
        try {
            app(UpdateMember::class)->handle($member, $actor, 0, ['city' => 'Neu']);
            $this->fail('An audit failure must stop the update.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated audit failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame('Alt', $member->fresh()->city);
        $this->assertSame(0, $member->fresh()->lock_version);
        $this->assertDatabaseCount('member_changes', 0);
    }

    public function test_validation_of_fields_dates_and_iban(): void
    {
        $this->signIn();
        $member = Member::factory()->create(['birth_date' => '1990-01-01', 'joined_at' => '2020-01-01']);
        foreach ([
            ['first_name' => ''], ['last_name' => null], ['email' => 'invalid'],
            ['gender' => 'x'], ['custom_graduation_year' => 'invalid'], ['is_honorary' => 'maybe'],
            ['sponsor_contribution' => '12.999'], ['sponsor_contribution' => '-1'],
            ['birth_date' => '2025-02-30'], ['birth_date' => '2099-01-01'],
            ['joined_at' => '1980-01-01'], ['left_at' => '2019-12-31'],
            ['deceased_at' => '1980-01-01'], ['iban' => 'DE89370400440532013001'],
            ['iban' => ['unexpected']], ['iban' => 12345],
            ['city' => ['unexpected']], ['membership_type' => 'unknown'],
        ] as $values) {
            $this->patchJson($this->url($member, 'update'), ['lock_version' => 0, ...$values])
                ->assertUnprocessable()->assertJsonValidationErrors(array_keys($values));
        }
        $this->assertDatabaseCount('member_changes', 0);
        $this->patch($this->url($member, 'update'), [
            'lock_version' => 0, 'iban' => 'de89 3704 0044 0532 0130 00', 'sponsor_contribution' => '42,50',
            'postal_code' => '01234', 'is_honorary' => true, 'middle_name' => '',
        ])->assertSessionHasNoErrors();
        $this->assertSame('DE89370400440532013000', $member->fresh()->iban);
        $this->assertSame('42.50', $member->fresh()->sponsor_contribution);
        $this->assertNull($member->fresh()->middle_name);
    }

    public function test_save_and_close_preserves_valid_list_query_and_blocks_external_redirects(): void
    {
        $this->signIn();
        $member = Member::factory()->create();
        $returnTo = '/mitglieder?q=Anna&per_page=10&page=2&sort=city&direction=desc';
        $this->patch($this->url($member, 'update'), ['lock_version' => 0, 'close' => true, 'return_to' => $returnTo])->assertRedirect('/mitglieder?q=Anna&sort=city&direction=desc&per_page=10&page=2');
        foreach (['https://evil.example/mitglieder', '//evil.example/mitglieder', '/mitglieder/1', '/mitglieder?per_page=9999', '/mitglieder?q[]=x', '/mitglieder/../logout', "/mitglieder\n"] as $unsafe) {
            $this->assertSame('/mitglieder', MemberNavigation::returnUrl($unsafe));
        }
        $this->patch($this->url($member, 'update'), ['lock_version' => 0, 'close' => true, 'return_to' => '//evil.example/mitglieder'])->assertRedirect('/mitglieder');
        $this->assertSame('/mitglieder?q=Anna', MemberNavigation::returnUrl('/mitglieder?q=Anna&redirect=https://evil.example'));
    }

    public function test_history_is_paginated_newest_first_and_scoped_to_member(): void
    {
        $actor = $this->signIn();
        $member = Member::factory()->create();
        $other = Member::factory()->create();
        app(UpdateMember::class)->handle($other, $actor, 0, ['city' => 'Other member']);
        for ($i = 0; $i < 12; $i++) {
            app(UpdateMember::class)->handle($member, $actor, $i, ['city' => 'Version '.($i + 1)]);
        }
        $this->get($this->url($member))->assertInertia(fn (Assert $page) => $page
            ->where('history.total', 12)->has('history.data', 10)->where('history.data.0.version', 12)
            ->where('history.data.0.before.city', 'Version 11')->where('history.data.0.after.city', 'Version 12'));
        $this->get($this->url($member).'?history_page=2')->assertInertia(fn (Assert $page) => $page
            ->has('history.data', 2)->where('history.data.0.version', 2)->where('history.data.1.version', 1));
        $this->getJson($this->url($member).'?history_page=-1')->assertUnprocessable();
    }

    public function test_application_does_not_allow_history_rewrites_or_deletion(): void
    {
        $actor = $this->signIn();
        $member = Member::factory()->create();
        app(UpdateMember::class)->handle($member, $actor, 0, ['city' => 'Neu']);
        foreach (['update', 'delete'] as $action) {
            try {
                $change = MemberChange::sole();
                $action === 'update' ? $change->update(['actor_name' => 'Manipuliert']) : $change->delete();
                $this->fail('Audit entries must be immutable through Eloquent.');
            } catch (LogicException) {
                $this->assertSame($actor->name, MemberChange::sole()->actor_name);
            }
        }
    }

    public function test_missing_members_documents_and_invalid_document_data_are_handled(): void
    {
        $this->signIn();
        $member = Member::factory()->create();
        $this->get(route('members.show', ['member' => 999999999]))->assertNotFound();
        $this->patch(route('members.update', ['member' => 999999999]), ['lock_version' => 0])->assertNotFound();
        $this->get(route('members.document', ['member' => $member->member_number, 'kind' => 'sepa']))->assertNotFound();
        $this->get(route('members.document', ['member' => $member->member_number, 'kind' => 'unknown']))->assertNotFound();
        $this->document($member, 'sepa', 'invalid PDF');
        $this->get(route('members.document', ['member' => $member->member_number, 'kind' => 'sepa']))->assertUnprocessable();
    }
}
