<?php

declare(strict_types=1);

namespace NovaNuke\Admin;

use InvalidArgumentException;
use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\Modules\ModuleManager;
use NovaNuke\Core\Modules\ModuleManifest;
use NovaNuke\Core\Modules\ModulePackageInstaller;
use NovaNuke\Core\Modules\ModulePackageUploadValidator;
use NovaNuke\Core\Modules\ModuleRepository;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\View\ViewRenderer;
use RuntimeException;

final class ModulesController
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly AuthManager $auth,
        private readonly AuthorizationService $authorization,
        private readonly ActivityLogger $activity,
        private readonly CsrfTokenManager $csrf,
        private readonly ViewRenderer $views,
        private readonly ?Translator $translator = null,
        private readonly ?ModulePackageInstaller $packages = null,
        private readonly ?ModulePackageUploadValidator $uploads = null,
        private readonly ?ModuleRepository $moduleRepository = null,
    ) {
    }

    public function upload(Request $request): Response
    {
        $guard = $this->guard();
        if ($guard !== null) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) {
            return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        }
        if ($this->packages === null || $this->uploads === null || $this->moduleRepository === null) {
            return $this->view(null, $this->translate('admin.modules.error.package_unavailable', 'Module package installation is unavailable.'), 503);
        }

        try {
            $path = $this->uploads->validate($request->file('module_package'));
            $installed = $this->moduleRepository->all();
            $package = $this->packages->inspect($path, $installed);
            if (isset($installed[$package->manifest->slug])) {
                $manifest = $this->packages->upgrade($path, $installed, function (ModuleManifest $manifest): void {
                    $this->modules->update($manifest->slug);
                });
                $message = 'admin.modules.message.package_updated';
            } else {
                $manifest = $this->packages->install($path, $installed);
                $message = 'admin.modules.message.package_uploaded';
            }
            if (isset($installed[$manifest->slug])) {
                $this->moduleRepository->clearError($manifest->slug);
            }
            $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], 'module.package.uploaded', 'module', $manifest->slug, [
                'version' => $manifest->version,
            ], $request->ip());
            return $this->view($this->translate($message, $message === 'admin.modules.message.package_updated'
                ? 'Module package updated. Database migrations and permissions were processed; review the module state before enabling it.'
                : 'Module package uploaded. Review it below, then install and enable it.'));
        } catch (RuntimeException|InvalidArgumentException $error) {
            return $this->view(null, $this->packageError($error->getMessage()), 422);
        }
    }

    public function index(): Response
    {
        $guard = $this->guard();
        if ($guard !== null) {
            return $guard;
        }

        return $this->view();
    }

    public function action(Request $request): Response
    {
        $guard = $this->guard();
        if ($guard !== null) {
            return $guard;
        }
        if (! $this->csrf->validate($request->input('_token'))) {
            return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        }

        $slug = (string) $request->attribute('slug');
        $action = (string) $request->attribute('action');
        $allowed = ['install', 'update', 'enable', 'disable', 'uninstall', 'forget-missing'];
        if (! in_array($action, $allowed, true)) {
            return Response::html($this->translate('admin.modules.error.action', 'Unsupported module action.'), 404);
        }

        try {
            if ($action === 'uninstall' && ! hash_equals($slug, (string) $request->input('confirm_slug'))) {
                throw new RuntimeException("Type the module slug ({$slug}) to confirm uninstallation.");
            }
            if ($action === 'forget-missing' && ! hash_equals($slug, (string) $request->input('confirm_slug'))) {
                throw new RuntimeException("Type the module slug ({$slug}) to confirm registry cleanup.");
            }
            switch ($action) {
                case 'install': $this->modules->install($slug); break;
                case 'update': $this->modules->update($slug); break;
                case 'enable': $this->modules->enable($slug); break;
                case 'disable': $this->modules->disable($slug); break;
                case 'uninstall':
                    $this->modules->uninstall($slug, $request->input('delete_data') === '1');
                    break;
                case 'forget-missing':
                    $this->modules->forgetMissing($slug);
                    break;
            }
            $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], "module.{$action}", 'module', $slug, [
                'delete_data' => $action === 'uninstall' && $request->input('delete_data') === '1',
            ], $request->ip());
            return $this->view($this->translate('admin.modules.message.' . $action, "Module action completed: {$action}."));
        } catch (RuntimeException $error) {
            return $this->view(null, $this->error($error->getMessage()), 422);
        }
    }

    public function audience(Request $request): Response
    {
        if (($guard=$this->guard())!==null)return$guard;
        if(!$this->csrf->validate($request->input('_token')))return Response::html($this->translate('admin.error.csrf','Invalid or expired CSRF token.'),419);
        $slug=(string)$request->attribute('slug');
        try{$this->modules->setAudience($slug,(string)$request->input('audience'));$actor=$this->auth->user();$this->activity->log((int)$actor['id'],'module.audience.updated','module',$slug,['audience'=>(string)$request->input('audience')],$request->ip());return$this->view($this->translate('admin.modules.message.audience','Module audience updated.'));}
        catch(RuntimeException$error){return$this->view(null,$this->error($error->getMessage()),422);}
    }

    private function guard(): ?Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect('/login');
        }

        return $this->authorization->allows((int) $user['id'], 'modules.manage')
            ? null
            : Response::html($this->translate('admin.error.forbidden', 'Forbidden'), 403);
    }

    private function view(?string $message = null, ?string $error = null, int $status = 200): Response
    {
        return Response::html($this->views->render('admin/modules/index.twig', [
            'modules' => $this->modules->inventory(),
            'csrf_token' => $this->csrf->token(),
            'message' => $message,
            'error' => $error,
        ]), $status);
    }

    private function error(string $message): string
    {
        $keys = [
            'The module is already installed.' => 'already_installed', 'The module is not installed.' => 'not_installed',
            'No newer module version is available.' => 'no_update', 'Install the module before enabling it.' => 'install_first',
            'Invalid module audience.' => 'audience', 'Install the module before setting its audience.' => 'audience_install_first',
            'Invalid module slug.' => 'slug', 'Module files were not found.' => 'files_missing',
            'Module provider must implement ModuleInterface.' => 'provider_interface',
            'Module source is available; use normal uninstall.' => 'missing_source_available',
        ];
        if (isset($keys[$message])) return $this->translate('admin.modules.error.' . $keys[$message], $message);
        if (str_contains($message, 'confirm registry cleanup')) return $this->translate('admin.modules.error.confirm_missing', 'Type the module slug to confirm registry cleanup.');
        if (str_starts_with($message, 'Type the module slug (')) return $this->translate('admin.modules.error.confirm_uninstall', 'Type the module slug to confirm uninstallation.');
        if (str_starts_with($message, 'Enable dependency first: ')) return $this->translate('admin.modules.error.enable_dependency', $message);
        if (str_starts_with($message, 'Disable dependent module first: ')) return $this->translate('admin.modules.error.disable_dependent', $message);
        if (str_starts_with($message, 'Module provider class was not found: ')) return $this->translate('admin.modules.error.provider_missing', $message);
        if (str_starts_with($message, 'Invalid module permission slug: ')) return $this->translate('admin.modules.error.permission_slug', $message);
        return $message;
    }

    private function packageError(string $message): string
    {
        $keys = [
            'Select a NovaNuke module ZIP package.' => 'package_select',
            'The module package upload did not complete successfully.' => 'package_upload',
            'Module packages must use the .zip extension.' => 'package_extension',
            'The uploaded module package is not a valid temporary file.' => 'package_temporary',
            'Module package must be a non-empty ZIP no larger than 50 MB.' => 'package_size',
            'The uploaded file is not a ZIP package.' => 'package_content',
            'Module package version must be newer than the installed module.' => 'package_version',
            'The installed module source does not match the package identity.' => 'package_identity',
            'An update for this module is already in progress.' => 'package_update_in_progress',
            'Module source publication failed; previous source restored.' => 'package_source_swap',
            'Module source publication failed and previous source could not be restored.' => 'package_source_recovery',
        ];
        if (isset($keys[$message])) return $this->translate('admin.modules.error.' . $keys[$message], $message);
        if (str_starts_with($message, 'Module source was published, but its database update did not complete.')) {
            return $this->translate('admin.modules.error.package_upgrade_failed', 'The module source was updated, but its database update did not complete. Review migration status and recovery before retrying.');
        }
        return $message;
    }

    private function translate(string $key, string $fallback): string
    {
        return $this->translator?->translate($key) ?? $fallback;
    }
}
