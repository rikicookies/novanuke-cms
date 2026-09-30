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
use NovaNuke\Core\View\ViewRenderer;
use PDO;

final class RolesController
{
    public function __construct(
        private readonly PDO $database,
        private readonly AuthManager $auth,
        private readonly AuthorizationService $authorization,
        private readonly ActivityLogger $activity,
        private readonly CsrfTokenManager $csrf,
        private readonly ViewRenderer $views,
        private readonly ?Translator $translator = null,
    ) {
    }

    public function index(): Response
    {
        $guard = $this->guard('roles.view');
        if ($guard !== null) {
            return $guard;
        }
        $roles = $this->database->query(
            'SELECT r.id, r.name, r.slug, r.description, r.is_system, COUNT(rp.permission_id) AS permission_count '
            . 'FROM roles r LEFT JOIN role_permissions rp ON rp.role_id = r.id '
            . 'GROUP BY r.id, r.name, r.slug, r.description, r.is_system ORDER BY r.id'
        )->fetchAll();

        return Response::html($this->views->render('admin/roles/index.twig', ['roles' => $roles]));
    }

    public function edit(Request $request): Response
    {
        $guard = $this->guard('roles.view');
        if ($guard !== null) {
            return $guard;
        }
        $role = $this->role((int) $request->attribute('id'));
        if ($role === null) {
            return Response::html($this->translate('admin.roles.error.not_found', 'Role not found.'), 404);
        }

        return $this->editView($role);
    }

    public function update(Request $request): Response
    {
        $guard = $this->guard('roles.manage');
        if ($guard !== null) {
            return $guard;
        }
        if (! $this->csrf->validate($request->input('_token'))) {
            return Response::html($this->translate('admin.error.csrf', 'Invalid or expired CSRF token.'), 419);
        }
        $role = $this->role((int) $request->attribute('id'));
        if ($role === null) {
            return Response::html($this->translate('admin.roles.error.not_found', 'Role not found.'), 404);
        }
        if ($role['slug'] === 'super-administrator') {
            return Response::html($this->translate('admin.roles.error.protected', 'The Super Administrator role cannot be modified.'), 403);
        }

        $selected = $request->input('permissions', []);
        $selected = is_array($selected) ? array_values(array_unique(array_map('intval', $selected))) : [];
        $valid = $this->validPermissionIds($selected);

        $this->database->beginTransaction();
        try {
            $delete = $this->database->prepare('DELETE FROM role_permissions WHERE role_id = :role_id');
            $delete->execute(['role_id' => $role['id']]);
            $insert = $this->database->prepare(
                'INSERT INTO role_permissions (role_id, permission_id, created_at) '
                . 'VALUES (:role_id, :permission_id, UTC_TIMESTAMP())'
            );
            foreach ($valid as $permissionId) {
                $insert->execute(['role_id' => $role['id'], 'permission_id' => $permissionId]);
            }
            $this->database->commit();
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }

        $actor = $this->auth->user();
        $this->activity->log((int) $actor['id'], 'role.permissions.updated', 'role', $role['id'], [
            'permission_ids' => implode(',', $valid),
        ], $request->ip());
        return $this->editView($role, true);
    }

    private function guard(string $permission): ?Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect('/login');
        }

        return $this->authorization->allows((int) $user['id'], $permission)
            ? null
            : Response::html($this->translate('admin.error.forbidden', 'Forbidden'), 403);
    }

    /** @return array<string, mixed>|null */
    private function role(int $id): ?array
    {
        $statement = $this->database->prepare('SELECT id, name, slug, description, is_system FROM roles WHERE id = :id');
        $statement->execute(['id' => $id]);
        $role = $statement->fetch();

        return is_array($role) ? $role : null;
    }

    /** @param array<string, mixed> $role */
    private function editView(array $role, bool $saved = false): Response
    {
        $statement = $this->database->prepare(
            'SELECT p.id, p.name, p.slug, p.description, p.module_slug, '
            . 'CASE WHEN rp.permission_id IS NULL THEN 0 ELSE 1 END AS selected '
            . 'FROM permissions p LEFT JOIN role_permissions rp ON rp.permission_id = p.id AND rp.role_id = :role_id '
            . 'ORDER BY p.module_slug, p.name'
        );
        $statement->execute(['role_id' => $role['id']]);

        return Response::html($this->views->render('admin/roles/edit.twig', [
            'role' => $role,
            'permissions' => $statement->fetchAll(),
            'csrf_token' => $this->csrf->token(),
            'saved' => $saved,
        ]));
    }

    /** @param list<int> $ids
     *  @return list<int>
     */
    private function validPermissionIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->database->prepare("SELECT id FROM permissions WHERE id IN ({$placeholders})");
        $statement->execute($ids);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function translate(string $key, string $fallback): string
    {
        return $this->translator?->translate($key) ?? $fallback;
    }
}
