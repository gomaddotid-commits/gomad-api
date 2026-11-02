<?php

namespace App\Core\Notification\Application;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Kode verifikasi dua langkah. Hanya dikirim lewat email — tidak disimpan ke
 * notifikasi dalam aplikasi supaya kode tidak bisa dibaca dari sesi yang sudah
 * terbuka di perangkat lain.
 *
 * Sengaja **tidak** masuk antrean: kalau worker mati, tidak ada yang bisa masuk.
 */
class TwoFactorCodeNotification extends Notification
{
    public function __construct(
        public readonly string $code,
        public readonly string $purpose,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reason = $this->purpose === 'enable'
            ? 'Anda meminta mengaktifkan verifikasi dua langkah.'
            : 'Ada percobaan masuk ke akun Anda yang memerlukan verifikasi.';

        return (new MailMessage)
            ->subject('Kode verifikasi GoMad: '.$this->code)
            ->greeting('Halo '.$notifiable->name)
            ->line($reason)
            ->line('Kode verifikasi Anda: **'.$this->code.'**')
            ->line('Kode berlaku 10 menit dan hanya bisa dipakai sekali.')
            ->line('Bila Anda tidak merasa melakukan ini, abaikan email ini dan ganti kata sandi Anda.');
    }
}
