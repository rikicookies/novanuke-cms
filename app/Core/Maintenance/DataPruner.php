<?php

declare(strict_types=1);

namespace NovaNuke\Core\Maintenance;

use NovaNuke\Core\Events\EventDispatcher;
use NovaNuke\Core\Membership\MembershipExpirationProcessor;
use NovaNuke\Core\Membership\MembershipActivationProcessor;
use PDO;

final class DataPruner
{
    /** @var array<string,string> */
    private const CORE_RULES = [
        'core.rate_limits' => 'window_ends_at<=UTC_TIMESTAMP()',
        'core.password_reset_tokens' => 'expires_at<=UTC_TIMESTAMP()',
        'core.email_verification_tokens' => 'expires_at<=UTC_TIMESTAMP()',
        'core.email_change_tokens' => 'expires_at<=UTC_TIMESTAMP()',
        'core.login_history' => 'logged_in_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 DAY)',
        'core.activity_logs' => 'created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY)',
    ];

    /** @var array<string,string> */
    private const TABLES = [
        'core.rate_limits' => 'rate_limits',
        'core.password_reset_tokens' => 'password_reset_tokens',
        'core.email_verification_tokens' => 'email_verification_tokens',
        'core.email_change_tokens' => 'email_change_tokens',
        'core.login_history' => 'user_login_history',
        'core.activity_logs' => 'activity_logs',
    ];

    public function __construct(private readonly PDO $database, private readonly EventDispatcher $events, private readonly MembershipActivationProcessor $activations, private readonly MembershipExpirationProcessor $memberships)
    {
    }

    /** @return array<string,int> */
    public function run(bool $dryRun): array
    {
        $event = new MaintenancePruning($dryRun);
        $event->add('core.membership_activations', $this->activations->process($dryRun));
        $event->add('core.membership_expirations', $this->memberships->process($dryRun));
        if (! $dryRun) $this->database->beginTransaction();
        try {
            foreach (self::CORE_RULES as $name => $where) {
                $table = self::TABLES[$name];
                $records = $dryRun
                    ? (int) $this->database->query("SELECT COUNT(*) FROM `{$table}` WHERE {$where}")->fetchColumn()
                    : $this->database->exec("DELETE FROM `{$table}` WHERE {$where}");
                $event->add($name, (int) $records);
            }
            $this->events->dispatch(\NovaNuke\Core\Events\EventName::MAINTENANCE_PRUNING, $event);
            if (! $dryRun) $this->database->commit();
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }

        return $event->results();
    }
}
