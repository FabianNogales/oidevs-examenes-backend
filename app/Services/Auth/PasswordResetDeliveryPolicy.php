<?php

namespace App\Services\Auth;

use Illuminate\Support\ConfigurationUrlParser;
use InvalidArgumentException;
use ValueError;

class PasswordResetDeliveryPolicy
{
    public function canDeliverWithoutLogging(?string $mailer = null, array $visited = []): bool
    {
        $mailer ??= config('mail.driver') ?? config('mail.default');
        if (! is_string($mailer) || $mailer === '' || in_array($mailer, $visited, true)) {
            return false;
        }
        $options = config('mail.driver') ? config('mail') : config('mail.mailers.'.$mailer, []);
        if (! is_array($options)) {
            return false;
        }
        // Match MailManager: a MAIL_URL scheme can override the named transport.
        if (isset($options['url'])) {
            try {
                $options = (new ConfigurationUrlParser)->parseConfiguration($options);
            } catch (InvalidArgumentException | ValueError) {
                return false;
            }
            $options['transport'] = $options['driver'] ?? null;
        }
        $transport = $options['transport'] ?? config('mail.driver');
        if (! is_string($transport) || in_array($transport, ['', 'log', 'array'], true)) {
            return false;
        }
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $children = $options['mailers'] ?? [];
            if (! is_array($children) || $children === []) {
                return false;
            }
            foreach ($children as $child) {
                if (! is_string($child) || ! $this->canDeliverWithoutLogging($child, [...$visited, $mailer])) {
                    return false;
                }
            }
        }

        // A real transport still needs provider credentials and delivery testing.
        return true;
    }
}
