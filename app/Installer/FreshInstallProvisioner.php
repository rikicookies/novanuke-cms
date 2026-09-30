<?php

declare(strict_types=1);

namespace NovaNuke\Installer;

use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Blocks\BlockRepository;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Http\Routing\Router;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Modules\ModuleCompatibilityChecker;
use NovaNuke\Core\Modules\ModuleDetector;
use NovaNuke\Core\Modules\ModuleManager;
use NovaNuke\Core\Modules\ModuleMigrator;
use NovaNuke\Core\Modules\ModuleRepository;
use NovaNuke\Core\Settings\SettingsRepository;
use NovaNuke\Core\Themes\ThemeAssetPublisher;
use NovaNuke\Core\Themes\ThemeDetector;
use NovaNuke\Core\Themes\ThemeManager;
use NovaNuke\Core\Themes\ThemeRepository;
use NovaNuke\Core\Version;
use NovaNuke\Core\View\ViewRenderer;
use PDO;
use RuntimeException;
use Throwable;

final class FreshInstallProvisioner
{
    public function __construct(
        private readonly string $rootPath,
        private readonly PDO $database,
    ) {
    }

    /** @return array{modules:list<string>,theme:string} */
    public function provision(): array
    {
        $modules = $this->moduleManager();
        foreach (DefaultBundledModules::SLUGS as $slug) {
            try {
                $modules->install($slug);
                $modules->enable($slug);
            } catch (Throwable $error) {
                throw new RuntimeException("Recommended bundled module '{$slug}' failed: {$error->getMessage()}", 0, $error);
            }
        }

        $themes = $this->themeManager();
        try {
            $themes->install('novamodern');
            $themes->activate('novamodern');
        } catch (Throwable $error) {
            throw new RuntimeException("Default theme 'novamodern' failed: {$error->getMessage()}", 0, $error);
        }

        $this->createModulesBlock();

        return ['modules' => DefaultBundledModules::SLUGS, 'theme' => 'novamodern'];
    }

    private function moduleManager(): ModuleManager
    {
        return new ModuleManager(
            $this->database,
            new ModuleDetector($this->rootPath . '/modules'),
            new ModuleRepository($this->database),
            new ModuleMigrator($this->database),
            new ModuleCompatibilityChecker(Version::CURRENT),
            new Container(),
            new Router(),
            new EventDispatcher(),
            new Translator('en', 'en', $this->rootPath . '/language'),
        );
    }

    private function themeManager(): ThemeManager
    {
        $translator = new Translator('en', 'en', $this->rootPath . '/language');
        return new ThemeManager(
            new ThemeDetector($this->rootPath . '/themes'),
            new ThemeRepository($this->database),
            new ThemeAssetPublisher($this->rootPath . '/public/assets/themes'),
            new SettingsRepository($this->database),
            new ViewRenderer(
                $this->rootPath . '/resources/views',
                $this->rootPath . '/storage/cache/twig',
                false,
                $translator,
            ),
            new EventDispatcher(),
            $translator,
            Version::CURRENT,
        );
    }

    private function createModulesBlock(): void
    {
        (new BlockRepository($this->database))->save(null, [
            'title' => 'Modules',
            'slug' => 'modules',
            'type' => 'modules-menu',
            'position' => 'left-sidebar',
            'content' => null,
            'configuration' => '{}',
            'visibility_mode' => 'all',
            'audience' => 'public',
            'page_patterns' => '[]',
            'module_slugs' => '[]',
            'enabled' => 1,
            'show_title' => 1,
            'sort_order' => 20,
            'starts_at' => null,
            'ends_at' => null,
            'created_by' => null,
        ], []);
    }
}
