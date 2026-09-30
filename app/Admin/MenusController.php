<?php

declare(strict_types=1);

namespace NovaNuke\Admin;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Http\Request;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Logging\ActivityLogger;
use NovaNuke\Core\Menus\MenuManager;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\Security\CsrfTokenManager;
use NovaNuke\Core\Security\SessionManager;
use NovaNuke\Core\View\ViewRenderer;
use NovaNuke\Core\Settings\SettingsRepository;
use RuntimeException;

final class MenusController
{
    public function __construct(
        private readonly MenuManager $menus,
        private readonly AuthManager $auth,
        private readonly AuthorizationService $authorization,
        private readonly ActivityLogger $activity,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
        private readonly ViewRenderer $views,
        private readonly SettingsRepository $settings,
        private readonly ?Translator $translator = null,
    ) {
    }

    public function index(): Response
    {
        $guard = $this->guard();
        if ($guard !== null) {
            return $guard;
        }
        $message = $this->session->pull('menus.message');
        return $this->view(is_string($message) ? $message : null);
    }

    public function saveMenu(Request $request): Response
    {
        return $this->mutate($request, function () use ($request): array {
            $id = $this->menus->saveMenu($request->allInput());
            return ['menu.saved', 'menu', $id, $this->translate('admin.menus.message.saved', 'Menu saved successfully.')];
        });
    }

    public function saveItem(Request $request): Response
    {
        return $this->mutate($request, function () use ($request): array {
            $id = $this->menus->saveItem($request->allInput());
            return ['menu_item.saved', 'menu_item', $id, $this->translate('admin.menus.message.item_saved', 'Menu item saved successfully.')];
        });
    }



    public function savePublicMenuOrder(Request $request): Response
    {
        $guard = $this->guard();
        if ($guard !== null) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) {
            return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        }

        $menuId = $this->routeId($request);
        $raw = (string) $request->input('item_order', '');
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || count($decoded) > 500) {
            return $this->view(null, $this->translate('admin.menus.error.item_order', 'Invalid menu item order.'), 422);
        }

        try {
            $this->menus->reorderItems($menuId, $decoded);
            $actor = $this->auth->user();
            $this->activity->log(
                (int) $actor['id'],
                'menu_items.reordered',
                'menu',
                $menuId,
                ['top_level_count' => count($decoded)],
                $request->ip(),
            );
            $this->session->put('menus.message', $this->translate('admin.menus.message.public_order', 'Public menu order saved.'));
            return Response::redirect('/admin/menus', 303);
        } catch (RuntimeException $error) {
            return $this->view(null, $this->error($error->getMessage()), 422);
        }
    }

    public function saveAdminNavigationOrder(Request $request): Response
    {
        $guard = $this->guard();
        if ($guard !== null) return $guard;
        if (! $this->csrf->validate($request->input('_token'))) {
            return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        }

        $raw = (string) $request->input('navigation_order', '');
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || count($decoded) > 20) {
            return $this->view(null, $this->translate('admin.menus.error.navigation_order', 'Invalid navigation order.'), 422);
        }

        $clean = [];
        $seenGroups = [];
        $seenUrls = [];
        foreach ($decoded as $entry) {
            if (! is_array($entry)) continue;
            $slug = strtolower(trim((string) ($entry['slug'] ?? '')));
            if (! preg_match('/^[a-z][a-z0-9-]{0,49}$/', $slug) || isset($seenGroups[$slug])) continue;
            $seenGroups[$slug] = true;

            $urls = [];
            foreach ((array) ($entry['items'] ?? []) as $url) {
                $url = trim((string) $url);
                if (! str_starts_with($url, '/admin') || strlen($url) > 255 || isset($seenUrls[$url])) continue;
                $seenUrls[$url] = true;
                $urls[] = $url;
            }
            $clean[] = ['slug' => $slug, 'items' => $urls];
        }

        if ($clean === []) return $this->view(null, $this->translate('admin.menus.error.navigation_empty', 'Navigation order cannot be empty.'), 422);

        $this->settings->setString(
            'admin.navigation.order',
            json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'admin',
        );
        $actor = $this->auth->user();
        $this->activity->log((int) $actor['id'], 'admin_navigation.reordered', 'settings', null, [], $request->ip());
        $this->session->put('menus.message', $this->translate('admin.menus.message.admin_order', 'Administration navigation order saved.'));
        return Response::redirect('/admin/menus', 303);
    }

    public function deleteMenu(Request $request): Response
    {
        return $this->mutate($request, function () use ($request): array {
            if ($request->input('confirm_delete') !== '1') {
                throw new RuntimeException('Confirm menu deletion before continuing.');
            }
            $id = $this->routeId($request);
            $this->menus->deleteMenu($id);
            return ['menu.deleted', 'menu', $id, $this->translate('admin.menus.message.deleted', 'Menu deleted.')];
        });
    }

    public function deleteItem(Request $request): Response
    {
        return $this->mutate($request, function () use ($request): array {
            if ($request->input('confirm_delete') !== '1') {
                throw new RuntimeException('Confirm item deletion before continuing.');
            }
            $id = $this->routeId($request);
            $this->menus->deleteItem($id);
            return ['menu_item.deleted', 'menu_item', $id, $this->translate('admin.menus.message.item_deleted', 'Menu item and its children were deleted.')];
        });
    }

    /** @param callable(): array{string,string,int,string} $operation */
    private function mutate(Request $request, callable $operation): Response
    {
        $guard = $this->guard();
        if ($guard !== null) {
            return $guard;
        }
        if (! $this->csrf->validate($request->input('_token'))) {
            return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        }
        try {
            [$action, $type, $id, $message] = $operation();
            $actor = $this->auth->user();
            $this->activity->log((int) $actor['id'], $action, $type, $id, [], $request->ip());
            $this->session->put('menus.message', $message);
            return Response::redirect('/admin/menus', 303);
        } catch (RuntimeException $error) {
            return $this->view(null, $this->error($error->getMessage()), 422);
        }
    }

    private function routeId(Request $request): int
    {
        $id = filter_var($request->attribute('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new RuntimeException('Invalid identifier.');
        }
        return (int) $id;
    }

    private function guard(): ?Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect('/login');
        }
        return $this->authorization->allows((int) $user['id'], 'menus.manage')
            ? null : Response::html($this->translate('admin.error.forbidden', 'Forbidden'), 403);
    }

    private function view(?string $message = null, ?string $error = null, int $status = 200): Response
    {
        return Response::html($this->views->render('admin/menus/index.twig', [
            'menu_list' => $this->menus->all(),
            'roles' => $this->menus->roles(),
            'csrf_token' => $this->csrf->token(),
            'message' => $message,
            'error' => $error,
        ]), $status);
    }

    private function error(string $message): string
    {
        $keys = [
            'Confirm menu deletion before continuing.' => 'confirm_delete',
            'Confirm item deletion before continuing.' => 'confirm_item_delete',
            'Invalid identifier.' => 'invalid_identifier',
            'Menu name is required and must not exceed 120 characters.' => 'name',
            'Menu slug must use lowercase letters, numbers and hyphens.' => 'slug',
            'Menu description must not exceed 255 characters.' => 'description',
            'Item title is required and must not exceed 120 characters.' => 'item_title',
            'One or more selected roles are invalid.' => 'roles',
            'Select a valid menu.' => 'select_menu',
            'Enter a valid menu destination.' => 'destination',
            'Unsupported menu link type.' => 'link_type',
            'Internal links must begin with one slash.' => 'internal_link',
            'Enter a valid external URL.' => 'external_url',
            'External menu links support HTTP and HTTPS only.' => 'external_scheme',
            'Enter a valid module slug.' => 'module_slug',
            'The menu slug is already in use.' => 'slug_used',
            'Menu item not found.' => 'item_not_found',
            'The parent item must belong to the same menu.' => 'parent_menu',
            'A menu item cannot be placed below itself or one of its children.' => 'parent_cycle',
            'Invalid menu order payload.' => 'item_order',
            'Menu items can only be reordered within their existing parent level.' => 'parent_level',
            'The submitted menu order does not match the menu items.' => 'order_mismatch',
            'Menu not found.' => 'not_found',
        ];
        $key = $keys[$message] ?? null;
        return $key === null ? $message : $this->translate('admin.menus.error.' . $key, $message);
    }

    private function translate(string $key, string $fallback): string
    {
        return $this->translator?->translate($key) ?? $fallback;
    }
}
