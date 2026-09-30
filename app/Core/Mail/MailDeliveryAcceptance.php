<?php

declare(strict_types=1);

namespace NovaNuke\Core\Mail;

use NovaNuke\Core\Config\ConfigRepository;
use RuntimeException;

final class MailDeliveryAcceptance
{
    public const WORKFLOWS = [
        'registration-verification' => 'Registration verification delivery',
        'password-reset' => 'Password reset delivery',
        'email-change' => 'Email-change verification delivery',
    ];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly string $statePath,
        private readonly ?string $siteUrl = null,
        private readonly ?MailConfigurationCheck $configuration = null,
    ) {
    }

    /** @return list<array{name:string,passed:bool,required:bool,detail:string}> */
    public function run(): array
    {
        $state = $this->readState();
        $fingerprint = $this->configurationFingerprint();
        $matches = is_array($state) && hash_equals((string) ($state['configuration_fingerprint'] ?? ''), $fingerprint);
        $checks = [];

        foreach (self::WORKFLOWS as $workflow => $label) {
            $record = $matches && isset($state['workflows'][$workflow]) && is_array($state['workflows'][$workflow])
                ? $state['workflows'][$workflow]
                : null;
            $verifiedAt = is_array($record) ? (string) ($record['verified_at'] ?? '') : '';
            $passed = $verifiedAt !== '';
            $checks[] = [
                'name' => $label,
                'passed' => $passed,
                'required' => true,
                'detail' => $passed
                    ? 'Manual production-like delivery acceptance recorded at ' . $verifiedAt . '. Re-record after SMTP or APP_URL changes.'
                    : ($matches
                        ? 'MANUAL REQUIRED / NOT VERIFIED. Complete the real workflow and record acceptance.'
                        : 'MANUAL REQUIRED / NOT VERIFIED. No acceptance exists for the current SMTP + APP_URL configuration.'),
            ];
        }

        return $checks;
    }

    public function passed(): bool
    {
        foreach ($this->run() as $check) {
            if ($check['required'] && ! $check['passed']) {
                return false;
            }
        }
        return true;
    }

    public function record(string $workflow): void
    {
        if (! array_key_exists($workflow, self::WORKFLOWS)) {
            throw new RuntimeException('Unknown mail acceptance workflow: ' . $workflow);
        }
        if (strtolower((string) $this->config->get('mail.mailer', 'log')) !== 'smtp') {
            throw new RuntimeException('Mail delivery acceptance can only be recorded while MAIL_MAILER=smtp.');
        }
        $configuration = $this->configuration ?? new MailConfigurationCheck($this->config);
        if (! $configuration->passed()) {
            throw new RuntimeException('Mail delivery acceptance cannot be recorded until the SMTP configuration check passes.');
        }

        $fingerprint = $this->configurationFingerprint();
        $state = $this->readState();
        if (! is_array($state) || ! hash_equals((string) ($state['configuration_fingerprint'] ?? ''), $fingerprint)) {
            $state = [
                'format' => 1,
                'configuration_fingerprint' => $fingerprint,
                'site_url' => $this->effectiveSiteUrl(),
                'workflows' => [],
            ];
        }
        $state['workflows'][$workflow] = ['verified_at' => gmdate('c')];
        $this->writeState($state);
    }

    /** @return array<string,mixed>|null */
    private function readState(): ?array
    {
        if (! is_file($this->statePath) || is_link($this->statePath)) {
            return null;
        }
        $json = file_get_contents($this->statePath);
        if ($json === false) {
            return null;
        }
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $state */
    private function writeState(array $state): void
    {
        $directory = dirname($this->statePath);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create mail acceptance directory.');
        }
        $temporary = $this->statePath . '.tmp-' . bin2hex(random_bytes(6));
        $payload = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write mail acceptance state.');
        }
        @chmod($temporary, 0600);
        if (! rename($temporary, $this->statePath)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish mail acceptance state.');
        }
        @chmod($this->statePath, 0600);
    }

    private function configurationFingerprint(): string
    {
        $parts = [
            strtolower((string) $this->config->get('mail.mailer', 'log')),
            strtolower((string) $this->config->get('mail.host', '')),
            (string) $this->config->get('mail.port', ''),
            (string) $this->config->get('mail.username', ''),
            (string) $this->config->get('mail.password', ''),
            strtolower((string) $this->config->get('mail.encryption', '')),
            strtolower((string) $this->config->get('mail.from_address', '')),
            (string) $this->config->get('mail.from_name', ''),
            $this->effectiveSiteUrl(),
        ];
        return hash('sha256', implode("\n", $parts));
    }

    private function effectiveSiteUrl(): string
    {
        return rtrim($this->siteUrl ?? (string) $this->config->get('app.url', ''), '/');
    }
}
