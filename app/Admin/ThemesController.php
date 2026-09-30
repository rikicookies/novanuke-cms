<?php

declare(strict_types=1);

namespace NovaNuke\Admin;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\Themes\ThemeManager;
use NovaNuke\Core\View\ViewRenderer;
use RuntimeException;

final class ThemesController
{
    public function __construct(
        private readonly ThemeManager $themes,
        private readonly AuthManager $auth,
        private readonly AuthorizationService $authorization,
        private readonly ActivityLogger $activity,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
        private readonly ViewRenderer $views,
        private readonly ?Translator $translator = null,
    ) {
    }

    public function index(): Response
    {
        $guard = $this->guard();

        return $guard ?? $this->view(
            is_string($message = $this->session->pull('themes.message')) ? $message : null,
        );
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
        if (! in_array($action, ['install', 'update', 'activate', 'configure', 'uninstall'], true)) {
            return Response::html($this->translate('admin.themes.error.action', 'Unsupported theme action.'), 404);
        }

        try {
            switch ($action) {
                case 'install': $this->themes->install($slug); break;
                case 'update': $this->themes->update($slug); break;
                case 'activate': $this->themes->activate($slug); break;
                case 'configure': $this->themes->configure($slug, $request->allInput()); break;
                case 'uninstall':
                    if (! hash_equals($slug, (string) $request->input('confirm_slug'))) {
                        throw new RuntimeException("Type the theme slug ({$slug}) to confirm uninstallation.");
                    }
                    $this->themes->uninstall($slug);
                    break;
            }
            $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], "theme.{$action}", 'theme', $slug, [], $request->ip());
            $this->session->put('themes.message', $this->translate('admin.themes.message.' . $action, "Theme action completed: {$action}."));

            return Response::redirect('/admin/themes', 303);
        } catch (RuntimeException $error) {
            return $this->view(null, $this->error($error->getMessage()), 422);
        }
    }

    private function guard(): ?Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect('/login');
        }

        return $this->authorization->allows((int) $user['id'], 'themes.manage')
            ? null
            : Response::html($this->translate('admin.error.forbidden', 'Forbidden'), 403);
    }

    private function view(?string $message = null, ?string $error = null, int $status = 200): Response
    {
        return Response::html($this->views->render('admin/themes/index.twig', [
            'themes' => $this->themes->inventory(),
            'csrf_token' => $this->csrf->token(),
            'message' => $message,
            'error' => $error,
        ]), $status);
    }

    private function error(string $message): string
    {
        $keys = [
            'The theme is already installed.' => 'already_installed', 'The theme is not installed.' => 'not_installed',
            'No newer theme version is available.' => 'no_update', 'Install the theme before activating it.' => 'install_first',
            'Activate another theme before uninstalling this one.' => 'activate_other', 'Invalid theme slug.' => 'slug',
            'Theme files were not found.' => 'files_missing', 'Could not create the public theme asset root.' => 'asset_root',
            'Could not stage the current theme assets for atomic replacement.' => 'asset_stage_current',
            'Theme asset swap failed and the previous assets could not be restored automatically.' => 'asset_restore',
            'Could not atomically publish the staged theme assets.' => 'asset_publish',
            'Theme assets directory may not be a symbolic link.' => 'asset_symlink_root',
            'Theme assets may not contain symbolic links.' => 'asset_symlink',
            'Theme assets may contain only regular files and directories.' => 'asset_type',
            'Could not hash a theme asset before publication.' => 'asset_hash',
            'Theme screenshot is missing or unsupported.' => 'screenshot',
            'Could not hash the theme screenshot before publication.' => 'screenshot_hash',
            'Could not create the theme asset staging directory.' => 'staging_create',
            'Could not create a staged theme asset directory.' => 'staging_directory',
            'Could not copy a theme asset to staging.' => 'staging_copy',
            'A staged theme asset is missing or unsafe.' => 'staging_missing',
            'A staged theme asset failed integrity verification.' => 'staging_integrity',
            'Invalid theme asset destination.' => 'asset_destination',
            'Refusing to remove an unsafe theme asset path.' => 'asset_remove',
        ];
        if (isset($keys[$message])) return $this->translate('admin.themes.error.' . $keys[$message], $message);
        if (str_starts_with($message, 'Type the theme slug (')) return $this->translate('admin.themes.error.confirm_uninstall', 'Type the theme slug to confirm uninstallation.');
        if (str_starts_with($message, 'Requires NovaNuke ')) return $this->translate('admin.themes.error.cms_version', $message);
        if (str_starts_with($message, 'Invalid color setting: ')) return $this->translate('admin.themes.error.color_setting', $message);
        if (str_starts_with($message, 'Invalid text setting: ')) return $this->translate('admin.themes.error.text_setting', $message);
        if (str_starts_with($message, 'Unsupported theme setting: ')) return $this->translate('admin.themes.error.setting', $message);
        if (str_starts_with($message, 'Theme asset extension is not allowed: ')) return $this->translate('admin.themes.error.asset_extension', $message);
        return $message;
    }

    private function translate(string $key, string $fallback): string
    {
        return $this->translator?->translate($key) ?? $fallback;
    }
}
