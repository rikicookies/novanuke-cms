<?php

declare(strict_types=1);

namespace NovaNuke\Core\Modules;

use NovaNuke\Core\Container\Container;
use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Http\Routing\Router;
use PDO;
use RuntimeException;
use Throwable;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\View\ViewRenderer;

final class ModuleManager
{
    public function __construct(
        private readonly PDO $database,
        private readonly ModuleDetector $detector,
        private readonly ModuleRepository $repository,
        private readonly ModuleMigrator $migrator,
        private readonly ModuleCompatibilityChecker $compatibility,
        private readonly Container $container,
        private readonly Router $router,
        private readonly EventDispatcher $events,
        private readonly Translator $translator,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public function inventory(): array
    {
        $detected = $this->detector->detect();
        $installed = $this->repository->all();
        $inventory = [];

        foreach ($detected as $slug => $manifest) {
            $compatibility = $this->compatibility->check($manifest, $installed);
            $record = $installed[$slug] ?? null;
            $inventory[$slug] = [
                'manifest' => $manifest,
                'installed' => $record !== null,
                'enabled' => (bool) ($record['enabled'] ?? false),
                'audience' => (string) ($record['audience'] ?? 'public'),
                'installed_version' => $record['installed_version'] ?? null,
                'update_available' => $record !== null
                    && version_compare($manifest->version, (string) $record['installed_version'], '>'),
                'compatible' => $compatibility->compatible,
                'compatibility_reason' => $compatibility->reason,
                'last_error' => $record['last_error'] ?? null,
                'missing_files' => false,
            ];
        }

        foreach ($installed as $slug => $record) {
            if (isset($inventory[$slug])) {
                continue;
            }
            $inventory[$slug] = [
                'manifest' => null,
                'installed' => true,
                'enabled' => (bool) $record['enabled'],
                'audience' => (string) ($record['audience'] ?? 'public'),
                'installed_version' => $record['installed_version'],
                'update_available' => false,
                'compatible' => false,
                'compatibility_reason' => 'Module files are missing from disk.',
                'last_error' => $record['last_error'],
                'missing_files' => true,
                'name' => $record['name'],
            ];
        }

        ksort($inventory);
        return $inventory;
    }

    public function install(string $slug): void
    {
        $manifest = $this->manifest($slug);
        $installed = $this->repository->all();
        if (isset($installed[$slug])) {
            throw new RuntimeException('The module is already installed.');
        }
        $check = $this->compatibility->check($manifest, $installed);
        if (! $check->compatible) {
            throw new RuntimeException((string) $check->reason);
        }

        $this->migrator->run($manifest);
        $this->registerPermissions($manifest);
        $this->repository->install($manifest);
    }

    public function update(string $slug): void
    {
        $manifest = $this->manifest($slug);
        $installed = $this->repository->all();
        $record = $installed[$slug] ?? null;
        if ($record === null) {
            throw new RuntimeException('The module is not installed.');
        }
        if (version_compare($manifest->version, (string) $record['installed_version'], '<=')) {
            throw new RuntimeException('No newer module version is available.');
        }
        $check = $this->compatibility->check($manifest, $installed);
        if (! $check->compatible) {
            throw new RuntimeException((string) $check->reason);
        }

        $this->migrator->run($manifest);
        $this->registerPermissions($manifest);
        $this->repository->install($manifest);
    }

    /** @return list<string> */
    public function recover(string $slug): array
    {
        return $this->migrator->recover($this->manifest($slug));
    }

    public function enable(string $slug): void
    {
        $manifest = $this->manifest($slug);
        $installed = $this->repository->all();
        if (! isset($installed[$slug])) {
            throw new RuntimeException('Install the module before enabling it.');
        }
        $check = $this->compatibility->check($manifest, $installed);
        if (! $check->compatible) {
            throw new RuntimeException((string) $check->reason);
        }
        foreach (array_keys($manifest->dependencies) as $dependency) {
            if (! ($installed[$dependency]['enabled'] ?? false)) {
                throw new RuntimeException("Enable dependency first: {$dependency}");
            }
        }
        $this->provider($manifest);
        $this->registerPermissions($manifest);
        $this->repository->setEnabled($slug, true);
    }

    public function disable(string $slug): void
    {
        $installed = $this->repository->all();
        if (! isset($installed[$slug])) {
            throw new RuntimeException('The module is not installed.');
        }
        foreach ($this->detector->detect() as $other) {
            if (($installed[$other->slug]['enabled'] ?? false) && isset($other->dependencies[$slug])) {
                throw new RuntimeException("Disable dependent module first: {$other->slug}");
            }
        }
        $this->repository->setEnabled($slug, false);
    }

    public function setAudience(string $slug, string $audience): void
    {
        $this->manifest($slug);
        if (! in_array($audience, \NovaNuke\Core\Access\AccessAudience::VALUES, true)) throw new RuntimeException('Invalid module audience.');
        if (! isset($this->repository->all()[$slug])) throw new RuntimeException('Install the module before setting its audience.');
        $this->repository->setAudience($slug, $audience);
    }

    public function uninstall(string $slug, bool $deleteData): void
    {
        $manifest = $this->manifest($slug);
        $this->disable($slug);
        if ($deleteData) {
            $this->migrator->rollbackAll($manifest);
        }
        $permissions = $this->database->prepare('DELETE FROM permissions WHERE module_slug = :module_slug');
        $permissions->execute(['module_slug' => $slug]);
        $this->repository->remove($slug);
    }

    /**
     * Remove only NovaNuke registry metadata for a module whose source is absent.
     *
     * This intentionally does not run providers, migration rollbacks, or any
     * module-owned cleanup because the source needed to prove that behavior is
     * unavailable.
     */
    public function forgetMissing(string $slug): void
    {
        if (! preg_match('/^[a-z][a-z0-9-]{0,99}$/', $slug)) {
            throw new RuntimeException('Invalid module slug.');
        }
        if (! isset($this->repository->all()[$slug])) {
            throw new RuntimeException('The module is not installed.');
        }
        if (isset($this->detector->detect()[$slug])) {
            throw new RuntimeException('Module source is available; use normal uninstall.');
        }

        $startedTransaction = ! $this->database->inTransaction();
        if ($startedTransaction) $this->database->beginTransaction();
        try {
            $migrations = $this->database->prepare('DELETE FROM module_migrations WHERE module_slug = :module_slug');
            $migrations->execute(['module_slug' => $slug]);
            $permissions = $this->database->prepare('DELETE FROM permissions WHERE module_slug = :module_slug');
            $permissions->execute(['module_slug' => $slug]);
            $this->repository->remove($slug);
            if ($startedTransaction) $this->database->commit();
        } catch (Throwable $error) {
            if ($startedTransaction && $this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    public function bootEnabled(): void
    {
        if (! $this->repository->available()) {
            return;
        }
        $detected = $this->detector->detect();
        $installed = $this->repository->all();
        $enabled = array_filter($installed, static fn (array $module): bool => $module['enabled']);
        $pending = $enabled;
        $registered = [];
        $lifecycles = [];

        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $slug => $record) {
                $manifest = $detected[$slug] ?? null;
                if ($manifest === null) {
                    // A missing source is an unavailable module, not a failed
                    // boot. Keep its registry row and metadata untouched so
                    // discovery/boot remains read-only for orphaned modules.
                    unset($pending[$slug]);
                    $progress = true;
                    continue;
                }
                if ($manifest !== null) {
                    $compatibility=$this->compatibility->check($manifest,$installed);
                    if(!$compatibility->compatible){$this->repository->setError($slug,(string)$compatibility->reason);unset($pending[$slug]);$progress=true;continue;}
                    $unresolved = array_diff(array_keys($manifest->dependencies), array_keys($registered));
                    if ($unresolved !== []) {
                        continue;
                    }
                    $this->registerPermissions($manifest);
                }
                $lifecycle=$this->registerOne($slug,$manifest);
                if($lifecycle!==null){$registered[$slug]=true;$lifecycles[$slug]=$lifecycle;}
                unset($pending[$slug]);
                $progress = true;
            }
            if (! $progress) {
                foreach (array_keys($pending) as $slug) {
                    $this->repository->setError($slug, 'Module dependency cycle or inactive dependency detected.');
                }
                break;
            }
        }
        foreach($lifecycles as$slug=>$lifecycle){$this->beginOwner($slug);try{$lifecycle['provider']->boot($lifecycle['context']);$this->endOwner();$this->commitOwner($slug);}catch(Throwable$error){$this->endOwner();$this->removeOwner($slug);$this->repository->setError($slug,$error->getMessage());error_log("Module {$slug} failed to boot: {$error->getMessage()}");}}
    }

    /** @return array{provider:ModuleInterface,context:ModuleContext}|null */
    private function registerOne(string $slug, ?ModuleManifest $manifest): ?array
    {
        if ($manifest === null) {
            $this->repository->setError($slug, 'Module files are missing from disk.');
            return null;
        }
        $this->beginOwner($slug);
        try {
            $this->translator->addNamespace($slug, $manifest->path . '/language');
            $provider = $this->provider($manifest);
            $context = new ModuleContext(
                $manifest,
                $this->container,
                $this->router,
                $this->events,
                $manifest->path,
            );
            $provider->register($context);
            $this->endOwner();
            return ['provider'=>$provider,'context'=>$context];
        } catch (Throwable $error) {
            $this->endOwner();
            $this->removeOwner($slug);
            $this->repository->setError($slug, $error->getMessage());
            error_log("Module {$slug} failed to register: {$error->getMessage()}");
            return null;
        }
    }

    private function beginOwner(string$s):void{$this->views()?->beginOwner($s);$this->container->beginOwner($s);$this->router->beginOwner($s);ModuleMutationScope::begin($s);$this->translator->beginOwner($s);}private function endOwner():void{$this->container->endOwner();$this->router->endOwner();ModuleMutationScope::end();$this->translator->endOwner();$this->views()?->endOwner();}private function commitOwner(string$s):void{ModuleMutationScope::commit($s);$this->container->commitOwner($s);$this->translator->commitOwner($s);$this->views()?->commitOwner($s);}private function removeOwner(string$s):void{$this->container->removeOwner($s);$this->router->removeOwner($s);ModuleMutationScope::rollback($s);$this->translator->removeOwner($s);$this->views()?->removeOwner($s);}private function views():?ViewRenderer{return$this->container->has(ViewRenderer::class)?$this->container->get(ViewRenderer::class):null;}

    private function manifest(string $slug): ModuleManifest
    {
        if (! preg_match('/^[a-z][a-z0-9-]{0,99}$/', $slug)) {
            throw new RuntimeException('Invalid module slug.');
        }
        $manifest = $this->detector->detect()[$slug] ?? null;
        if ($manifest === null) {
            throw new RuntimeException('Module files were not found.');
        }

        return $manifest;
    }

    private function provider(ModuleManifest $manifest): ModuleInterface
    {
        if (! class_exists($manifest->provider)) {
            throw new RuntimeException("Module provider class was not found: {$manifest->provider}");
        }
        $provider = new ($manifest->provider)();
        if (! $provider instanceof ModuleInterface) {
            throw new RuntimeException('Module provider must implement ModuleInterface.');
        }

        return $provider;
    }

    private function registerPermissions(ModuleManifest $manifest): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO permissions (name, slug, description, module_slug, created_at, updated_at) '
            . 'VALUES (:name, :slug, :description, :module_slug, UTC_TIMESTAMP(), UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), '
            . 'module_slug = VALUES(module_slug), updated_at = UTC_TIMESTAMP()'
        );
        foreach ($manifest->permissions as $permission) {
            if (! preg_match('/^' . preg_quote($manifest->slug, '/') . '\.[a-z][a-z0-9_.-]*$/', $permission)) {
                throw new RuntimeException("Invalid module permission slug: {$permission}");
            }
            $name = ucwords(str_replace(['.', '-', '_'], ' ', $permission));
            $statement->execute([
                'name' => $name,
                'slug' => $permission,
                'description' => "Permission provided by {$manifest->name}.",
                'module_slug' => $manifest->slug,
            ]);
        }
    }
}
