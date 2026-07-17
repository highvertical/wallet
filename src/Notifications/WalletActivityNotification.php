<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One generic notification for every wallet event, sent by
 * Listeners\SendTransactionNotification. Deliberately not ShouldQueue: the
 * listener that sends it already implements ShouldQueue, so the async hop
 * happens once, at the listener level, instead of double-queueing.
 */
final class WalletActivityNotification extends Notification
{
    public function __construct(private string $action, private array $data)
    {
    }

    public function via(mixed $notifiable): array
    {
        return (array) config('wallet.notifications.channels', ['database']);
    }

    public function toArray(mixed $notifiable): array
    {
        return array_merge(['action' => $this->action], $this->data);
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Wallet activity: '.str_replace('_', ' ', $this->action))
            ->line($this->line());
    }

    private function line(): string
    {
        $amount = isset($this->data['amount'], $this->data['currency'])
            ? $this->data['amount'].' '.$this->data['currency']
            : null;

        return match ($this->action) {
            'credited' => "Your wallet was credited {$amount}.",
            'debited' => "Your wallet was debited {$amount}.",
            'transferred' => "A transfer of {$amount} completed on your wallet.",
            'frozen' => 'Your wallet has been frozen.',
            'unfrozen' => 'Your wallet has been unfrozen.',
            'low_balance_detected' => 'Your wallet balance is running low.',
            'hold_placed' => "A hold of {$amount} was placed on your wallet.",
            'hold_released' => "A hold of {$amount} on your wallet was released.",
            'hold_captured' => "A hold of {$amount} on your wallet was captured.",
            'transaction_reversed' => "A transaction of {$amount} on your wallet was reversed.",
            default => 'Activity occurred on your wallet.',
        };
    }
}
