<?php

declare(strict_types=1);

namespace NovaNuke\Admin;

use NovaNuke\Auth\AuthManager;
use NovaNuke\Core\Http\Response;
use NovaNuke\Core\I18n\Translator;
use NovaNuke\Core\Security\AuthorizationService;
use NovaNuke\Core\View\ViewRenderer;
use PDO;

final class ActivityLogsController
{
    public function __construct(
        private readonly PDO $database,
        private readonly AuthManager $auth,
        private readonly AuthorizationService $authorization,
        private readonly ViewRenderer $views,
        private readonly ?Translator $translator = null,
    ) {
    }

    public function index(): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect('/login');
        }
        if (! $this->authorization->allows((int) $user['id'], 'logs.view')) {
            return Response::html($this->translator?->translate('admin.error.forbidden') ?? 'Forbidden', 403);
        }

        $logs = $this->database->query(
            'SELECT al.id, al.action, al.subject_type, al.subject_id, al.context, al.ip_address, al.created_at, '
            . 'u.username AS actor_username FROM activity_logs al '
            . 'LEFT JOIN users u ON u.id = al.actor_user_id ORDER BY al.id DESC LIMIT 200'
        )->fetchAll();

        return Response::html($this->views->render('admin/logs/index.twig', ['logs' => $logs]));
    }
}
