<?php

declare(strict_types=1);

namespace NovaNuke\Core\Mail;

use NovaNuke\Core\Config\ConfigRepository;

final class MailProductionReadiness
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly MailConfigurationCheck $configuration,
    ) {
    }

    /** @return list<array{name:string,passed:bool,required:bool,detail:string}> */
    public function run(): array
    {
        $checks = [];
        $mailer = strtolower((string) $this->config->get('mail.mailer', 'log'));
        $smtpSelected = $mailer === 'smtp';
        $checks[] = [
            'name' => 'Production mail transport',
            'passed' => $smtpSelected,
            'required' => true,
            'detail' => $smtpSelected
                ? 'SMTP transport is selected for production email workflows.'
                : 'MAIL_MAILER=log is development/test only; production registration verification, password reset and email-change verification require SMTP.',
        ];

        $structural = $this->configuration->run();
        $structuralPassed = true;
        foreach ($structural as $check) {
            if ($check['required'] && ! $check['passed']) {
                $structuralPassed = false;
            }
        }
        $checks[] = [
            'name' => 'Production mail configuration',
            'passed' => $smtpSelected && $structuralPassed,
            'required' => true,
            'detail' => $smtpSelected && $structuralPassed
                ? 'SMTP configuration is structurally valid. Delivery is not yet considered verified.'
                : 'Production SMTP configuration is not ready.',
        ];

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
}
