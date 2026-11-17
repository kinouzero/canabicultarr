<?php

namespace Tests\Feature;

use App\Mail\AccountActivationMail;
use App\Mail\EmailChangeMail;
use App\Mail\MonitoringAlertMail;
use App\Mail\PasswordRecoveryMail;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MailPresentationTest extends TestCase
{
    public static function accountEmails(): iterable
    {
        foreach (['en', 'fr'] as $locale) {
            foreach (['account-activation', 'email-change', 'password-recovery'] as $kind) {
                yield $kind.' '.$locale => [$kind, $locale];
            }
        }
    }

    #[DataProvider('accountEmails')]
    public function test_account_emails_keep_localized_html_plaintext_and_intact_action_links(string $kind, string $locale): void
    {
        $url = 'https://garden.example.test/#token='.str_repeat('a', 64).'&fixture=1';
        $mail = match ($kind) {
            'account-activation' => new AccountActivationMail($url, $locale),
            'email-change' => new EmailChangeMail($url, $locale),
            'password-recovery' => new PasswordRecoveryMail($url, 30, $locale),
        };
        $sent = Mail::mailer('array')->to('reader@example.test')->send($mail);
        $email = $sent->getSymfonySentMessage()->getOriginalMessage();
        $html = $email->getHtmlBody();
        $plain = $email->getTextBody();
        $this->assertSame(__($kind.'.subject', [], $locale), $email->getSubject());
        $this->assertStringContainsString('lang="'.$locale.'"', $html);
        $this->assertStringContainsString(e(__($kind.'.action', [], $locale)), $html);
        $this->assertStringContainsString('href="'.e($url).'"', $html);
        $this->assertStringContainsString($url, $plain);
        $this->assertStringContainsString(__($kind.'.expiry', ['minutes' => 30], $locale), $plain);
        $this->assertStringContainsString(e(__($kind.'.ignore', [], $locale)), $html);
        $this->assertStringContainsString(__($kind.'.ignore', [], $locale), $plain);
        $this->assertStringContainsString('src="cid:', $html);
        $this->assertCount(1, $email->getAttachments());
        $this->assertSame('inline', $email->getAttachments()[0]->getDisposition());
        $this->assertSame('en', app()->getLocale());
    }

    public function test_alert_html_escapes_device_content_and_retains_its_plaintext_and_delivery_identity(): void
    {
        $title = 'Alert <script>untrusted</script>';
        $body = "Sensor: <img src=x onerror=alert(1)>\nValue: 20 < 35";
        $mail = (new MonitoringAlertMail('fictional-delivery', $title, $body))->locale('fr');
        $sent = Mail::mailer('array')->to('reader@example.test')->send($mail);
        $email = $sent->getSymfonySentMessage()->getOriginalMessage();
        $html = $email->getHtmlBody();
        $this->assertStringContainsString(e($title), $html);
        $this->assertStringContainsString(e($body), str_replace(['<br />', '<br>'], '', $html));
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString(e(__('mail.alertHelp', [], 'fr')), $html);
        $this->assertSame(trim($body), trim($email->getTextBody()));
        $this->assertSame('fictional-delivery@canabicultarr.invalid', $email->getHeaders()->get('Message-ID')->getId());
    }
}
