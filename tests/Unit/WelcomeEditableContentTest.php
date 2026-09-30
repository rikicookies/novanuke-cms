<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Unit;

use Modules\Welcome\src\WelcomeContentResolver;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Settings\SettingsRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class WelcomeEditableContentTest extends TestCase
{
    private WelcomeContentResolver $resolver;
    private WelcomeTestDatabase $database;

    protected function setUp(): void
    {
        $this->database = new WelcomeTestDatabase();
        $this->resolver = new WelcomeContentResolver(
            new SettingsRepository($this->database),
            new Translator('en', 'en', dirname(__DIR__, 2) . '/modules/Welcome/language'),
            dirname(__DIR__, 2) . '/modules/Welcome/language',
        );
    }

    public function testEveryEditableFieldHasEnglishAndSpanishFallbacks(): void
    {
        $english = $this->resolver->resolveFor('en');
        $spanish = $this->resolver->resolveFor('es');

        foreach (array_keys($this->resolver->fields()) as $field) {
            self::assertNotSame('', $this->flatten($english, $field), $field . ' English fallback');
            self::assertNotSame('', $this->flatten($spanish, $field), $field . ' Spanish fallback');
        }
    }

    public function testCustomValuesAreLocaleSpecificAndEmptyValuesFallback(): void
    {
        $settings = new SettingsRepository($this->database);
        $settings->setString('welcome.content.en.hero.title', 'English Welcome', 'welcome');
        $settings->setString('welcome.content.es.hero.title', 'Bienvenida', 'welcome');
        $settings->setString('welcome.content.en.hero.description', '   ', 'welcome');

        self::assertSame('English Welcome', $this->resolver->resolveFor('en')['hero']['title']);
        self::assertSame('Bienvenida', $this->resolver->resolveFor('es')['hero']['title']);
        self::assertNotSame('', $this->resolver->resolveFor('en')['hero']['description']);
    }

    public function testWelcomeLinkDestinationsDefaultCustomAndEmptyFallback(): void
    {
        self::assertSame([
            'news' => '/news',
            'downloads' => '/downloads',
            'docs' => '/wiki',
            'resources' => '/links',
        ], $this->resolver->urlDefaults());
        self::assertSame('/news', $this->resolver->resolveFor('en')['links']['news']['url']);

        $settings = new SettingsRepository($this->database);
        $settings->setString('welcome.link.news.url', '/pages/tutorial', 'welcome');
        $settings->setString('welcome.link.downloads.url', 'https://example.com/releases', 'welcome');
        $settings->setString('welcome.link.docs.url', '   ', 'welcome');
        $this->resolver = new WelcomeContentResolver(
            $settings,
            new Translator('en', 'en', dirname(__DIR__, 2) . '/modules/Welcome/language'),
            dirname(__DIR__, 2) . '/modules/Welcome/language',
        );

        self::assertSame('/pages/tutorial', $this->resolver->resolveFor('en')['links']['news']['url']);
        self::assertSame('https://example.com/releases', $this->resolver->resolveFor('es')['links']['downloads']['url']);
        self::assertSame('/wiki', $this->resolver->resolveFor('es')['links']['docs']['url']);
    }

    public function testWelcomeLinkDestinationValidationRejectsUnsafeAndMalformedValues(): void
    {
        $result = $this->resolver->validate([
            'welcome_link_news_url' => 'javascript:alert(1)',
            'welcome_link_downloads_url' => 'not a url',
            'welcome_link_docs_url' => '/wiki',
            'welcome_link_resources_url' => '',
        ]);

        self::assertArrayHasKey('welcome_link_news_url', $result['errors']);
        self::assertArrayHasKey('welcome_link_downloads_url', $result['errors']);
        self::assertArrayNotHasKey('welcome_link_docs_url', $result['errors']);
        self::assertArrayNotHasKey('welcome_link_resources_url', $result['errors']);
    }

    public function testValidationBoundsFieldsAndRejectsControlCharacters(): void
    {
        $input = [
            'welcome_en_hero_title' => str_repeat('x', 201),
            'welcome_es_hero_description' => "safe\x07text",
        ];
        $result = $this->resolver->validate($input);

        self::assertArrayHasKey('welcome_en_hero_title', $result['errors']);
        self::assertArrayHasKey('welcome_es_hero_description', $result['errors']);
        self::assertSame([], array_diff_key($result['data'], $this->resolver->formValues()));
    }

    public function testSettingsUseWelcomeGroupAndPreserveUnrelatedSettings(): void
    {
        $settings = new SettingsRepository($this->database);
        $settings->setString('site.name', 'NovaNuke', 'general');
        $settings->setString('welcome.content.en.hero.title', 'Edited', 'welcome');

        self::assertSame('NovaNuke', $settings->string('site.name'));
        self::assertSame('welcome', $this->database->query("SELECT group_name FROM settings WHERE key = 'welcome.content.en.hero.title'")->fetchColumn());
    }

    public function testTemplatesExposeResolvedContentWithoutUnsafeRawOutput(): void
    {
        $root = dirname(__DIR__, 2);
        $base = (string) file_get_contents($root . '/modules/Welcome/views/index.twig');
        $learn = (string) file_get_contents($root . '/themes/novalearn/module-templates/welcome/index.twig');

        foreach ([$base, $learn] as $template) {
            self::assertStringContainsString('content.hero.title', $template);
            self::assertStringContainsString('content.closing.welcome', $template);
            self::assertStringNotContainsString("trans('welcome::", $template);
            self::assertStringNotContainsString('|raw', $template);
            self::assertStringContainsString('link.url', $template);
        }
        self::assertStringContainsString('href="{{ link.url }}"', $base);
        self::assertStringContainsString('href="{{ link.url }}"', $learn);
    }

    public function testAdminAndPublicWelcomeContractsRemainProtected(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root . '/modules/Welcome/src/WelcomeModule.php');
        $admin = (string) file_get_contents($root . '/modules/Welcome/src/AdminWelcomeController.php');

        foreach (["get('/welcome'", "get('/admin/welcome'", "post('/admin/welcome/save'", 'welcome.page.rendering', 'ModuleManager::class'] as $needle) {
            self::assertStringContainsString($needle, $module);
        }
        foreach (["'welcome.manage'", 'csrf->validate', 'authorization->allows', 'settings->setMany', 'changed_fields'] as $needle) {
            self::assertStringContainsString($needle, $admin);
        }
        self::assertStringContainsString("'url' =>", $module);
    }

    private function flatten(array $content, string $field): string
    {
        if (str_starts_with($field, 'link.')) $field = 'links.' . substr($field, 5);
        $value = $content;
        foreach (explode('.', $field) as $part) $value = $value[$part];
        return (string) $value;
    }
}

final class WelcomeTestDatabase extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT, type TEXT, group_name TEXT, created_at TEXT, updated_at TEXT)');
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $query = str_replace(
            'INSERT INTO settings (`key`, `value`, `type`, group_name, created_at, updated_at) VALUES (:key, :value, :type, :group_name, UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`), group_name = VALUES(group_name), updated_at = UTC_TIMESTAMP()',
            'INSERT INTO settings (key, value, type, group_name) VALUES (:key, :value, :type, :group_name) ON CONFLICT(key) DO UPDATE SET value = excluded.value, type = excluded.type, group_name = excluded.group_name',
            $query,
        );
        return parent::prepare($query, $options);
    }
}
