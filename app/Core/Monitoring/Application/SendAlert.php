<?php

namespace App\Core\Monitoring\Application;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kanal peringatan untuk kegagalan yang menyangkut uang.
 *
 * Tujuan: kegagalan uang tidak berhenti di `laravel.log`. Bila `ALERT_WEBHOOK_URL`
 * diisi (Slack/Discord/webhook internal), setiap kegagalan kritis dikirim ke sana.
 * Bila kosong, peringatan tetap ditulis ke log agar tidak pernah hilang diam-diam.
 */
final class SendAlert
{
    public function critical(string $message, array $context = []): void
    {
        $this->send('critical', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->send('warning', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function send(string $severity, string $message, array $context): void
    {
        $payload = [
            'severity' => $severity,
            'message' => $message,
            'context' => $context,
            'application' => config('app.name', 'GoMad'),
            'environment' => app()->environment(),
            'occurred_at' => now()->toIso8601String(),
        ];

        if ($severity === 'critical') {
            Log::critical('alert: '.$message, $context);
        } else {
            Log::warning('alert: '.$message, $context);
        }

        $url = config('services.alerts.webhook_url');
        if (! is_string($url) || trim($url) === '') {
            return;
        }

        try {
            Http::timeout((int) config('services.alerts.timeout', 5))
                ->acceptJson()
                ->post($url, $payload);
        } catch (Throwable $exception) {
            // Kegagalan mengirim peringatan tidak boleh menggagalkan proses uang.
            Log::error('alert: gagal mengirim peringatan', [
                'severity' => $severity,
                'message' => $message,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
