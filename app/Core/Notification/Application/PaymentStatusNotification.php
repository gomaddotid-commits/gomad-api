<?php

namespace App\Core\Notification\Application;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentStatusNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        private readonly string $paymentCode,
        private readonly string $status,
        private readonly string $amount,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->email
            ? ['database', 'mail']
            : ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'event' => 'payment.status_changed',
            'payment_code' => $this->paymentCode,
            'status' => $this->status,
            'amount' => $this->amount,
            'message' => $this->message(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Status pembayaran GoMad')
            ->greeting('Halo '.$notifiable->name)
            ->line($this->message())
            ->line('Kode pembayaran: '.$this->paymentCode);
    }

    private function message(): string
    {
        return match ($this->status) {
            'paid' => 'Pembayaran '.$this->paymentCode.' berhasil diterima.',
            'refunded' => 'Pembayaran '.$this->paymentCode.' telah dibalik sesuai pemberitahuan gateway.',
            'expired' => 'Pembayaran '.$this->paymentCode.' kedaluwarsa.',
            default => 'Pembayaran '.$this->paymentCode.' tidak berhasil.',
        };
    }
}
