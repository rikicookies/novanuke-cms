<?php

declare(strict_types=1);

namespace NovaNuke\Tests\Unit;

use NovaNuke\Core\Home\HomeContentResolver;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Settings\GeneralSettingsInput;
use NovaNuke\Core\Settings\SettingsRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class HomeEditableContentTest extends TestCase
{
    private HomeTestDatabase $database;
    private HomeContentResolver $resolver;

    protected function setUp(): void
    {
        $this->database = new HomeTestDatabase();
        $this->resolver = new HomeContentResolver(
            new SettingsRepository($this->database),
            new Translator('en', 'en', dirname(__DIR__, 2) . '/language'),
            dirname(__DIR__, 2) . '/language',
        );
    }

    public function testEnglishAndSpanishDefaultsComeFromExistingCatalogues(): void
    {
        $english = $this->resolver->resolveFor('en');
        $spanish = $this->resolver->resolveFor('es');

        self::assertSame('A modern modular CMS with an old-school spirit.', $english['headline']);
        self::assertSame('NovaNuke is running with modular content, themes, blocks, menus and secure user accounts.', $english['description']);
        self::assertSame('Un CMS modular moderno con espíritu de la vieja escuela.', $spanish['headline']);
        self::assertSame('NovaNuke funciona con contenido modular, themes, bloques, menús y cuentas de usuario seguras.', $spanish['description']);
        self::assertFalse($english['description_custom']);
        self::assertFalse($spanish['description_custom']);
    }

    public function testCustomValuesAreLocaleSpecific(): void
    {
        $settings = new SettingsRepository($this->database);
        $settings->setString('home.content.en.headline', 'English headline', 'home');
        $settings->setString('home.content.en.description', 'English description', 'home');
        $settings->setString('home.content.es.headline', 'Titular español', 'home');
        $settings->setString('home.content.es.description', 'Descripción española', 'home');

        self::assertSame('English headline', $this->resolver->resolveFor('en')['headline']);
        self::assertSame('English description', $this->resolver->resolveFor('en')['description']);
        self::assertSame('Titular español', $this->resolver->resolveFor('es')['headline']);
        self::assertSame('Descripción española', $this->resolver->resolveFor('es')['description']);
        self::assertTrue($this->resolver->resolveFor('en')['description_custom']);
        self::assertTrue($this->resolver->resolveFor('es')['description_custom']);
    }

    public function testEmptyCustomValuesUseTheLocaleDefaultWithoutCrossLocaleLeakage(): void
    {
        $settings = new SettingsRepository($this->database);
        $settings->setString('home.content.en.headline', '   ', 'home');
        $settings->setString('home.content.en.description', '', 'home');
        $settings->setString('home.content.es.headline', 'Titular propio', 'home');

        self::assertSame('A modern modular CMS with an old-school spirit.', $this->resolver->resolveFor('en')['headline']);
        self::assertSame('NovaNuke is running with modular content, themes, blocks, menus and secure user accounts.', $this->resolver->resolveFor('en')['description']);
        self::assertSame('Titular propio', $this->resolver->resolveFor('es')['headline']);
        self::assertSame('NovaNuke funciona con contenido modular, themes, bloques, menús y cuentas de usuario seguras.', $this->resolver->resolveFor('es')['description']);
    }

    public function testHomeCardDefaultsAndLocaleSpecificCustomValues(): void
    {
        $english = $this->resolver->resolveFor('en', 'Reeke');
        $spanish = $this->resolver->resolveFor('es', 'Reeke');

        self::assertSame('Explore the site', $english['cards']['explore']['title']);
        self::assertSame('Use the navigation to jump into articles, resources, downloads and community features.', $english['cards']['explore']['description']);
        self::assertSame('Signed in as Reeke.', $english['cards']['account']['title']);
        self::assertSame('Explora el sitio', $spanish['cards']['explore']['title']);
        self::assertSame('Sesión iniciada como Reeke.', $spanish['cards']['account']['title']);

        $settings = new SettingsRepository($this->database);
        $settings->setString('home.content.en.card.explore.title', 'Custom explore', 'home');
        $settings->setString('home.content.es.card.account.description', 'Descripción de cuenta', 'home');
        $this->resolver = new HomeContentResolver(
            $settings,
            new Translator('en', 'en', dirname(__DIR__, 2) . '/language'),
            dirname(__DIR__, 2) . '/language',
        );

        self::assertSame('Custom explore', $this->resolver->resolveFor('en')['cards']['explore']['title']);
        self::assertSame('Descripción de cuenta', $this->resolver->resolveFor('es')['cards']['account']['description']);
        self::assertSame('Explora el sitio', $this->resolver->resolveFor('es')['cards']['explore']['title']);
    }

    public function testEmptyCardValuesFallbackAndUsernameRemainsDynamicAndEscapedByTwig(): void
    {
        $settings = new SettingsRepository($this->database);
        $settings->setString('home.content.en.card.account.title', 'Member {username}', 'home');
        $settings->setString('home.content.en.card.explore.title', '   ', 'home');
        $this->resolver = new HomeContentResolver(
            $settings,
            new Translator('en', 'en', dirname(__DIR__, 2) . '/language'),
            dirname(__DIR__, 2) . '/language',
        );

        self::assertSame('Member Ada <script>', $this->resolver->resolveFor('en', 'Ada <script>')['cards']['account']['title']);
        self::assertSame('Explore the site', $this->resolver->resolveFor('en')['cards']['explore']['title']);

        $learn = (string) file_get_contents(dirname(__DIR__, 2) . '/themes/novalearn/templates/home.twig');
        self::assertStringContainsString('home_content.cards.account.title', $learn);
        self::assertStringNotContainsString('|raw', $learn);
        self::assertStringNotContainsString('str_replace', $learn);
    }

    public function testGeneralSettingsInputAcceptsHomeContentAndPreservesOtherData(): void
    {
        $input = new GeneralSettingsInput();
        $result = $input->validate([
            'name' => 'NovaNuke', 'description' => '', 'url' => 'https://example.test',
            'admin_email' => 'admin@example.test', 'timezone' => 'UTC', 'locale' => 'en',
            'date_format' => 'F j, Y', 'per_page' => '10', 'homepage' => 'home', 'maintenance' => '0',
            'home_en_headline' => 'Custom English', 'home_en_description' => 'Custom English description',
            'home_es_headline' => 'Titular personalizado', 'home_es_description' => 'Descripción personalizada',
        ], ['home' => 'settings.homepage_default']);

        self::assertSame([], $result['errors']);
        self::assertSame('Custom English', $result['data']['home_en_headline']);
        self::assertSame('Descripción personalizada', $result['data']['home_es_description']);
        self::assertSame('https://example.test', $result['data']['url']);
        self::assertSame('home', $result['data']['homepage']);
    }

    public function testGeneralSettingsInputRejectsOversizedOrControlCharacterHomeContent(): void
    {
        $input = new GeneralSettingsInput();
        $base = [
            'name' => 'NovaNuke', 'description' => '', 'url' => 'https://example.test',
            'admin_email' => 'admin@example.test', 'timezone' => 'UTC', 'locale' => 'en',
            'date_format' => 'F j, Y', 'per_page' => '10', 'homepage' => 'home', 'maintenance' => '0',
            'home_en_headline' => str_repeat('x', 201), 'home_en_description' => "ok\x07text",
            'home_es_headline' => 'ok', 'home_es_description' => str_repeat('x', 501),
        ];

        $result = $input->validate($base, ['home' => 'settings.homepage_default']);

        self::assertArrayHasKey('home_en_headline', $result['errors']);
        self::assertArrayHasKey('home_en_description', $result['errors']);
        self::assertArrayHasKey('home_es_description', $result['errors']);
    }

    public function testHomeContractsPreserveMarkupAuthenticationAndHomepageRedirectBehavior(): void
    {
        $core = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/home.twig');
        $learn = (string) file_get_contents(dirname(__DIR__, 2) . '/themes/novalearn/templates/home.twig');
        $web = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/web.php');
        $settings = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Admin/GeneralSettingsController.php');

        foreach (['welcome-card', 'home_content.headline', 'home_content.description', 'action="/logout"', '/login', '/register'] as $needle) {
            self::assertStringContainsString($needle, $core);
        }
        foreach (['nl-core-home', 'home_content.headline', 'home_content.description_custom', 'site_tagline', 'action="/logout"', '/login', '/register'] as $needle) {
            self::assertStringContainsString($needle, $learn);
        }
        foreach (['home_content.cards.explore.title', 'home_content.cards.explore.description', 'home_content.cards.account.title', 'home_content.cards.guest.title'] as $needle) {
            self::assertStringContainsString($needle, $learn);
        }
        self::assertStringContainsString("\$homepage = \$container->get(SettingsRepository::class)->string('site.homepage', 'home')", $web);
        self::assertStringContainsString("if (\$target !== null) return Response::redirect(\$target)", $web);
        self::assertStringContainsString("'home.content.en.headline'", $settings);
        self::assertStringContainsString("'home.content.es.description'", $settings);
        self::assertStringContainsString("'settings.manage'", $settings);
        self::assertStringContainsString('csrf->validate', $settings);
    }
}

final class HomeTestDatabase extends PDO
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
