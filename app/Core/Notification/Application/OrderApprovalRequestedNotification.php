<?php

namespace App\Core\Notification\Application;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderApprovalRequestedNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        private readonly string $orderCode,
        private readonly string $requesterName,
        private readonly string $amount,
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
            'event' => 'warung_order.approval_requested',
            'order_code' => $this->orderCode,
            'requester_name' => $this->requesterName,
            'amount' => $this->amount,
            'message' => 'Pesanan '.$this->orderCode.' sebesar Rp '.$this->amount.' menunggu persetujuan Anda.',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Pesanan menunggu persetujuan')
            ->greeting('Halo '.$notifiable->name)
            ->line($this->requesterName.' mengajukan pesanan '.$this->orderCode.' sebesar Rp '.$this->amount.'.')
            ->line('Tinjau dan putuskan pesanan sebelum pembayaran dilakukan.');
    }
}
