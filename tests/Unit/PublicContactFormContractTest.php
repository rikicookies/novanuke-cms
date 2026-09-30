<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class PublicContactFormContractTest extends TestCase
{
    private string $root;
    protected function setUp(): void { $this->root=dirname(__DIR__,2); }
    public function testPublicContactRouteIsRegistered(): void { $s=file_get_contents($this->root.'/routes/forms.php'); self::assertStringContainsString("post('/forms/contact'",$s); self::assertStringContainsString("'forms.contact'",$s); }
    public function testApplicationLoadsFormsAndExposesActionToThemes(): void { $s=file_get_contents($this->root.'/app/Core/Application.php'); self::assertStringContainsString("'/routes/forms.php'",$s); self::assertStringContainsString("'contact_form_action', '/forms/contact'",$s); }
    public function testControllerOwnsCsrfHoneypotThrottleAndAdminRecipient(): void { $s=file_get_contents($this->root.'/app/Core/Forms/ContactFormController.php'); self::assertStringContainsString("csrf->validate",$s); self::assertStringContainsString("input('website'",$s); self::assertStringContainsString("'public-contact-form'",$s); self::assertStringContainsString("'site.admin_email'",$s); }
    public function testMailerContractSupportsGenericSafeMessages(): void { $s=file_get_contents($this->root.'/app/Core/Mail/Mailer.php'); self::assertStringContainsString('sendMessage(string $recipient, string $subject, string $body, ?string $replyTo = null)', $s); }
    public function testReturnTargetRejectsExternalAndQueryBearingValuesByContract(): void { $s=file_get_contents($this->root.'/app/Core/Forms/ContactFormController.php'); self::assertStringContainsString("str_starts_with($".'v'.",'//')",str_replace(' ', '', $s)); self::assertStringContainsString('?#', $s); }
}
