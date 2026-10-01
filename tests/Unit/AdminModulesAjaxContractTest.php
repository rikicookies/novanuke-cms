<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminModulesAjaxContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testAdminModulesLoadsProgressiveActionsOnlyForThisPage(): void
    {
        $template = file_get_contents($this->root . '/resources/views/admin/modules/index.twig');

        self::assertStringContainsString('/assets/js/progressive-actions.js?v=1', $template);
        self::assertStringContainsString('/assets/admin/limitless/js/modules-admin.js?v=2', $template);
    }

    public function testAllModuleManagementFormsUseTheExistingAjaxContract(): void
    {
        $template = file_get_contents($this->root . '/resources/views/admin/modules/index.twig');

        foreach (['install', 'update', 'enable', 'disable', 'audience', 'uninstall', 'forget-missing'] as $action) {
            self::assertStringContainsString('/' . $action . '"', $template);
        }
        self::assertSame(7, substr_count($template, 'data-ajax-action'));
        self::assertSame(8, substr_count($template, 'name="_token"'));
    }

    public function testForgetRegistrationOptsIntoAbsentCardRemovalWithoutChangingOtherReplacementActions(): void
    {
        $template = file_get_contents($this->root . '/resources/views/admin/modules/index.twig');
        $progressive = file_get_contents($this->root . '/public/assets/js/progressive-actions.js');

        self::assertStringContainsString(
            'data-ajax-remove-if-absent="true"',
            $template
        );
        self::assertSame(1, substr_count($template, 'data-ajax-remove-if-absent="true"'));
        self::assertStringContainsString('current.replaceWith(incoming);', $progressive);
        self::assertStringContainsString(
            "const removed = !replaced && response.ok && removeWhenAbsent(doc, form, selector);",
            $progressive
        );
        self::assertStringContainsString(
            "const replaced = (!dialogForm || response.ok) && replaceFromDocument(doc, selector);",
            $progressive
        );
        self::assertStringContainsString("form.dataset.ajaxRemoveIfAbsent !== 'true'", $progressive);
        self::assertStringContainsString('current.remove();', $progressive);
        self::assertStringContainsString('closeDialogFor(form);', $progressive);
        self::assertStringContainsString("if (!replaced && !removed)", $progressive);
        self::assertStringContainsString("document.dispatchEvent(new CustomEvent('ajax:replaced'", $progressive);

        self::assertSame(7, substr_count($template, 'data-ajax-replace='));
        self::assertStringContainsString('data-ajax-replace="#module-{{ slug }}"', $template);
    }

    public function testForgetRegistrationErrorsRemainOnTheExistingFailurePath(): void
    {
        $progressive = file_get_contents($this->root . '/public/assets/js/progressive-actions.js');

        self::assertStringContainsString('response.ok && removeWhenAbsent', $progressive);
        self::assertStringContainsString(
            "const replaced = (!dialogForm || response.ok) && replaceFromDocument(doc, selector);",
            $progressive
        );
        self::assertStringContainsString('if (!replaced && !removed)', $progressive);
        self::assertStringContainsString('feedback?.message || textFrom(doc) || messages.ajaxRefreshFailed', $progressive);
        self::assertStringContainsString('busy(form, false);', $progressive);
    }

    public function testMissingModuleConfirmationUsesTopLayerDialogAndKeepsTypedSlugContract(): void
    {
        $template = file_get_contents($this->root . '/resources/views/admin/modules/index.twig');
        $javascript = file_get_contents($this->root . '/public/assets/admin/limitless/js/modules-admin.js');
        $stylesheet = file_get_contents($this->root . '/public/assets/admin/limitless/css/novanuke-admin.css');

        self::assertStringContainsString('<dialog id="module-confirm-{{ slug }}"', $template);
        self::assertStringContainsString('data-module-confirm-open', $template);
        self::assertStringContainsString('data-module-confirm-cancel', $template);
        self::assertStringContainsString('action="/admin/modules/{{ slug }}/forget-missing"', $template);
        self::assertStringContainsString('name="confirm_slug"', $template);
        self::assertStringContainsString('name="_token"', $template);
        self::assertStringContainsString('admin.modules.cancel', $template);
        self::assertStringContainsString('admin.modules.missing', $template);
        self::assertStringContainsString('showModal', $javascript);
        self::assertStringContainsString('ajax:replaced', $javascript);
        self::assertStringContainsString('.nn-module-confirm-dialog::backdrop', $stylesheet);
        foreach (['nn-module-confirm-heading', 'nn-module-confirm-callout', 'nn-module-confirm-instruction', 'nn-module-confirm-actions'] as $class) {
            self::assertStringContainsString($class, $stylesheet);
        }
    }

    public function testAdminLayoutLoadsTheModalStylesheetAndItsSelectorsMatchTheRenderedDialog(): void
    {
        $layout = file_get_contents($this->root . '/resources/views/layouts/admin.twig');
        $template = file_get_contents($this->root . '/resources/views/admin/modules/index.twig');
        $stylesheet = file_get_contents($this->root . '/public/assets/admin/limitless/css/novanuke-admin.css');

        self::assertStringContainsString(
            '<link rel="stylesheet" href="/assets/admin/limitless/css/novanuke-admin.css?v=31">',
            $layout
        );
        self::assertStringContainsString('<body class="nn-admin nn-admin-v2">', $layout);

        foreach ([
            'nn-module-confirm-dialog',
            'nn-module-confirm-panel',
            'nn-module-confirm-heading',
            'nn-module-confirm-eyebrow',
            'nn-module-confirm-icon',
            'nn-module-confirm-identity',
            'nn-module-confirm-badge',
            'nn-module-confirm-close',
            'nn-module-confirm-body',
            'nn-module-confirm-help',
            'nn-module-confirm-callout',
            'nn-module-confirm-instruction',
            'nn-module-confirm-actions',
        ] as $class) {
            self::assertStringContainsString('class="' . $class, $template);
            self::assertStringContainsString('.nn-admin-v2 .' . $class, $stylesheet);
        }

        self::assertStringContainsString(
            '.nn-admin-v2 .nn-module-confirm-dialog::backdrop',
            $stylesheet
        );
    }

    public function testTwoMissingModuleCardsHaveIndependentDialogTargetsAndDelegatedRuntimeBinding(): void
    {
        $template = file_get_contents($this->root . '/resources/views/admin/modules/index.twig');
        $javascript = file_get_contents($this->root . '/public/assets/admin/limitless/js/modules-admin.js');

        self::assertSame(1, preg_match('/<button class="nn-module-danger-trigger".*?<\/dialog>/s', $template, $matches));
        $card = str_replace('{{ slug }}', 'landing', $matches[0]);
        $secondCard = str_replace('{{ slug }}', 'demo-content', $matches[0]);
        self::assertStringContainsString('data-module-confirm-target="module-confirm-landing"', $card);
        self::assertStringContainsString('id="module-confirm-landing"', $card);
        self::assertStringContainsString('action="/admin/modules/landing/forget-missing"', $card);
        self::assertStringContainsString('name="confirm_slug"', $card);
        self::assertStringContainsString('data-module-confirm-target="module-confirm-demo-content"', $secondCard);
        self::assertStringContainsString('id="module-confirm-demo-content"', $secondCard);
        self::assertStringContainsString('action="/admin/modules/demo-content/forget-missing"', $secondCard);
        self::assertNotSame('module-confirm-landing', 'module-confirm-demo-content');
        self::assertStringContainsString("root.addEventListener('click'", $javascript);
        self::assertStringContainsString("document.getElementById(opener.dataset.moduleConfirmTarget || '')", $javascript);
        self::assertStringNotContainsString("querySelector('[data-module-confirm-open]')", $javascript);
    }

    public function testServerActionStillEnforcesAuthorizationCsrfAndBusinessActions(): void
    {
        $controller = file_get_contents($this->root . '/app/Admin/ModulesController.php');

        self::assertStringContainsString('$guard = $this->guard();', $controller);
        self::assertStringContainsString('$this->csrf->validate($request->input(\'_token\'))', $controller);
        self::assertStringContainsString("['install', 'update', 'enable', 'disable', 'uninstall', 'forget-missing']", $controller);
        foreach (['install', 'update', 'enable', 'disable', 'uninstall', 'forget-missing'] as $action) {
            self::assertStringContainsString("case '{$action}':", $controller);
        }
    }
}
