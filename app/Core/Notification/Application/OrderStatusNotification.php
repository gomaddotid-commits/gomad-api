<?php

namespace App\Core\Notification\Application;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderStatusNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        private readonly string $orderCode,
        private readonly string $status,
        private readonly ?string $detail = null,
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
            'event' => 'warung_order.status_changed',
            'order_code' => $this->orderCode,
            'status' => $this->status,
            'detail' => $this->detail ?? '',
            'message' => $this->message(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Pembaruan pesanan Mad Warung')
            ->greeting('Halo '.$notifiable->name)
            ->line($this->message())
            ->line('Kode pesanan: '.$this->orderCode);
    }

    private function message(): string
    {
        return match ($this->status) {
            'approved' => 'Pesanan '.$this->orderCode.' disetujui. Pemilik warung dapat melanjutkan pembayaran.',
            'rejected' => 'Pesanan '.$this->orderCode.' ditolak.'.($this->detail ? ' Alasan: '.$this->detail : ''),
            'paid' => 'Pembayaran pesanan '.$this->orderCode.' berhasil diterima dan diteruskan ke grosir.',
            'payment_failed' => 'Pembayaran pesanan '.$this->orderCode.' tidak berhasil. Stok yang direservasi telah dilepas.',
            'pending_payment' => 'Pesanan '.$this->orderCode.' siap dibayar oleh pemilik warung.',
            'expired' => 'Pesanan '.$this->orderCode.' kedaluwarsa dan reservasi stok telah dilepas.',
            'preparing' => 'Grosir mulai menyiapkan pesanan '.$this->orderCode.'.',
            'ready', 'ready_for_pickup' => 'Pesanan '.$this->orderCode.' siap.',
            'in_delivery' => 'Pesanan '.$this->orderCode.' sedang diantar oleh grosir.',
            'received' => 'Penerimaan pesanan '.$this->orderCode.' telah dikonfirmasi.',
            'completed' => 'Pesanan '.$this->orderCode.' selesai.',
            default => 'Status pesanan '.$this->orderCode.' diperbarui.',
        };
    }
}
