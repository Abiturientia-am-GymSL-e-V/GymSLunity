<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Configuration\MailConfigurator;
use App\Demo\DemoAccounts;
use App\Demo\DemoMailTransport;
use App\Mail\ConfigurationTestMail;
use App\Models\ClubSetting;
use App\Models\DemoMail;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DemoMailboxTest extends TestCase
{
    use RefreshDatabase;

    private function enableDemo(): void
    {
        config(['demo.enabled' => true]);
        app(MailConfigurator::class)->applyStored();
    }

    public function test_mailbox_does_not_exist_outside_the_demo(): void
    {
        $mail = DemoMail::query()->create(['subject' => 'Test', 'recipients' => ['to' => [], 'cc' => [], 'bcc' => []], 'html' => '<p>x</p>', 'attachments' => []]);

        $this->get(route('demo.mailbox.index'))->assertNotFound();
        $this->get(route('demo.mailbox.html', $mail))->assertNotFound();
    }

    public function test_demo_mode_stores_mails_instead_of_delivering_them_even_with_smtp_configured(): void
    {
        DB::table('mail_settings')->update(['driver' => 'smtp', 'smtp_host' => 'smtp.example.org', 'smtp_port' => 587]);
        $this->enableDemo();

        Mail::to('ada@example.org')->send(new ConfigurationTestMail('SMTP', 'ada@example.org'));

        $mail = DemoMail::query()->sole();
        $this->assertSame('GymSLunity – E-Mail-Konfiguration erfolgreich', $mail->subject);
        $this->assertSame(['ada@example.org'], $mail->recipients['to']);
        $this->assertNotNull($mail->html);
        $this->assertSame(['transport' => 'demo'], app(MailConfigurator::class)->transientConfig(['driver' => 'smtp'], 'secret'));
    }

    public function test_inline_images_are_embedded_and_attachments_kept(): void
    {
        $this->enableDemo();

        Mail::send([], [], function (Message $message): void {
            $cid = $message->embedData('PNGDATA', 'logo.png', 'image/png');
            $message->to('ada@example.org')->subject('Rechnung')
                ->html('<img src="'.$cid.'"><p>Hallo</p>')
                ->attachData('%PDF-1.4', 'Rechnung 2026.pdf', ['mime' => 'application/pdf']);
        });

        $mail = DemoMail::query()->sole();
        $this->assertStringContainsString('src="data:image/png;base64,'.base64_encode('PNGDATA').'"', (string) $mail->html);
        $this->assertCount(1, $mail->attachments);
        $this->assertSame('Rechnung 2026.pdf', $mail->attachments[0]['name']);
    }

    public function test_only_the_newest_mails_are_kept(): void
    {
        $this->enableDemo();
        DB::table('demo_mails')->insert(array_fill(0, DemoMailTransport::KEEP, [
            'subject' => 'Alt', 'sender' => '', 'recipients' => '{"to":[],"cc":[],"bcc":[]}', 'attachments' => '[]',
        ]));

        Mail::raw('Neu', fn (Message $message) => $message->to('ada@example.org')->subject('Neu'));

        $this->assertSame(DemoMailTransport::KEEP, DemoMail::query()->count());
        $this->assertTrue(DemoMail::query()->where('subject', 'Neu')->exists());
    }

    public function test_mailbox_lists_filters_and_shows_mails(): void
    {
        $this->enableDemo();
        Mail::raw('Für Ada', fn (Message $message) => $message->to('ada@example.org')->subject('An Ada'));
        Mail::raw('Für Bob', fn (Message $message) => $message->to('bob@example.org')->subject('An Bob'));
        $ada = DemoMail::query()->where('subject', 'An Ada')->sole();

        $this->get(route('demo.mailbox.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('public/DemoMailbox')->has('mails', 2)->where('selected', null));
        $this->get(route('demo.mailbox.index', ['empfaenger' => 'ada@', 'mail' => $ada->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('mails', 1)
                ->where('mails.0.subject', 'An Ada')
                ->where('selected.to', 'ada@example.org')
                ->where('selected.text', 'Für Ada'));
    }

    public function test_html_is_served_sandboxed_and_attachments_only_as_download(): void
    {
        $this->enableDemo();
        $mail = DemoMail::query()->create([
            'subject' => 'Test', 'recipients' => ['to' => [], 'cc' => [], 'bcc' => []],
            'html' => '<p>Hallo</p><script>alert(1)</script>',
            'attachments' => [['name' => 'x.html', 'mime' => 'text/html', 'size' => 4, 'content' => base64_encode('<h1>')]],
        ]);

        $html = $this->get(route('demo.mailbox.html', $mail))->assertOk();
        $this->assertStringStartsWith('sandbox ', (string) $html->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("default-src 'none'", (string) $html->headers->get('Content-Security-Policy'));

        $download = $this->get(route('demo.mailbox.attachment', [$mail, 0]))->assertOk();
        $this->assertSame('application/octet-stream', $download->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', (string) $download->headers->get('Content-Disposition'));
        $this->get(route('demo.mailbox.attachment', [$mail, 1]))->assertNotFound();
    }

    public function test_visitors_can_request_login_links_for_the_shared_demo_member_repeatedly(): void
    {
        $this->enableDemo();
        $settings = ClubSetting::current();
        $settings->update(['data' => [...$settings->data, 'selfservice_enabled' => true]]);
        Member::factory()->create(['email' => DemoAccounts::MEMBER_EMAIL]);
        Member::factory()->create(['email' => 'ada@example.org']);

        foreach (range(1, 4) as $attempt) {
            $this->post('/selfservice/zugang/anfordern', ['email' => DemoAccounts::MEMBER_EMAIL, 'purpose' => 'login'])->assertSessionHasNoErrors();
            $this->post('/selfservice/zugang/anfordern', ['email' => 'ada@example.org', 'purpose' => 'login'])->assertSessionHasNoErrors();
        }

        $this->assertSame(4, DB::table('selfservice_tokens')->where('email', DemoAccounts::MEMBER_EMAIL)->count());
        $this->assertSame(3, DB::table('selfservice_tokens')->where('email', 'ada@example.org')->count());
    }
}
