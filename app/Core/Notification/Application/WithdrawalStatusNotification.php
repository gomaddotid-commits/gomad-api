<?php

namespace App\Core\Notification\Application;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WithdrawalStatusNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        private readonly string $withdrawalCode,
        private readonly string $status,
        private readonly string $amount,
        private readonly ?string $message = null,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['database', 'mail'] : ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'event' => 'withdrawal.status_changed',
            'withdrawal_code' => $this->withdrawalCode,
            'status' => $this->status,
            'amount' => $this->amount,
            'message' => $this->message(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Status pencairan GoMad')
            ->greeting('Halo '.$notifiable->name)
            ->line($this->message())
            ->line('Kode pencairan: '.$this->withdrawalCode);
    }

    private function message(): string
    {
        return $this->message ?? match ($this->status) {
            'paid' => 'Pencairan '.$this->withdrawalCode.' telah ditransfer.',
            'failed' => 'Pencairan '.$this->withdrawalCode.' gagal. Saldo telah dikembalikan.',
            'approved' => 'Pencairan '.$this->withdrawalCode.' disetujui dan menunggu transfer.',
            default => 'Pencairan '.$this->withdrawalCode.' telah diajukan.',
        };
    }
}
