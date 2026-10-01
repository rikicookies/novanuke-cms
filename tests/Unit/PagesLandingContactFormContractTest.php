<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PagesLandingContactFormContractTest extends TestCase
{
    private string $controller;

    protected function setUp(): void
    {
        $this->controller = (string) file_get_contents(__DIR__ . '/../../modules/Pages/src/PublicPagesController.php');
    }

    public function testLandingReceivesAThemeNeutralContactFormContract(): void
    {
        self::assertStringContainsString("'contact_form' => \$this->contactForm(\$request, \$page)", $this->controller);
        self::assertStringContainsString("'action' => '/forms/contact'", $this->controller);
        self::assertStringContainsString("'csrf_token' => \$this->csrf?->token()", $this->controller);
        self::assertStringContainsString("'return_to' => '/pages/' . (string) \$page['slug']", $this->controller);
    }

    public function testOnlyKnownFormResultsAreExposedToTwig(): void
    {
        self::assertStringContainsString("['sent', 'invalid', 'limited', 'unavailable']", $this->controller);
        self::assertStringContainsString("? \$result", $this->controller);
        self::assertStringContainsString(": null", $this->controller);
    }

    public function testContractDoesNotRequireTheFallbackLandingTemplateToRenderAForm(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../modules/Pages/views/landing.twig');
        self::assertStringNotContainsString('<form', $template);
        self::assertStringNotContainsString('contact_form.', $template);
    }
}
