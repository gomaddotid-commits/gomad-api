<?php

namespace App\Core\Payment\Application;

use App\Core\Ledger\Application\PostLedgerTransaction;
use App\Core\Ledger\Application\ReverseLedgerTransaction;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\LedgerException;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Core\MadTrans\Application\PostTravelBookingPayment;
use App\Core\MadTrans\Domain\BookingStatus;
use App\Core\Notification\Application\PaymentStatusNotification;
use App\Core\Payment\Domain\InvalidWebhookSignature;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Rental\Application\PostRentalPayment;
use App\Core\Warung\Application\ExpireWarungOrderPayment;
use App\Core\Warung\Application\PostWarungOrderPayment;
use App\Models\BookingPassenger;
use App\Models\LedgerTransaction;
use App\Models\PaymentIntent;
use App\Models\TravelBooking;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

final class ProcessMidtransWebhook
{
    public function __construct(
        private readonly PostLedgerTransaction $postTransaction,
        private readonly ReverseLedgerTransaction $reverseTransaction,
        private readonly PostWarungOrderPayment $postWarungOrderPayment,
        private readonly ExpireWarungOrderPayment $expireWarungOrderPayment,
        private readonly PostTravelBookingPayment $postTravelBookingPayment,
        private readonly ExpireTravelBookingPayment $expireTravelBookingPayment,
        private readonly PostRentalPayment $postRentalPayment,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(array $payload, ?string $signature): void
    {
        foreach (['order_id', 'status_code', 'gross_amount', 'signature_key', 'transaction_status'] as $field) {
            if (! is_string($payload[$field] ?? null) || $payload[$field] === '') {
                throw new \InvalidArgumentException('Notifikasi payment gateway tidak lengkap.');
            }
        }
        if ($signature === null || ! hash_equals($payload['signature_key'], $signature)) {
            Log::warning('Midtrans webhook signature did not match its payload.', [
                'order_id' => $payload['order_id'],
            ]);
            throw new InvalidWebhookSignature('Signature webhook pembayaran tidak valid.');
        }

        $serverKey = config('services.midtrans.server_key');
        if (! is_string($serverKey) || $serverKey === '') {
            throw new \LogicException('Kredensial verifikasi webhook belum dikonfigurasi.');
        }
        $expected = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].$serverKey,
        );
        if (! hash_equals($expected, $payload['signature_key'])) {
            Log::warning('Midtrans webhook signature verification failed.', [
                'order_id' => $payload['order_id'],
            ]);
            throw new InvalidWebhookSignature('Signature webhook pembayaran tidak valid.');
        }

        $payment = PaymentIntent::query()
            ->where('provider_order_id', $payload['order_id'])
            ->first();
        if ($payment === null) {
            throw new PaymentConflict('Referensi pembayaran tidak ditemukan.');
        }

        $grossAmount = LedgerMoney::from($payload['gross_amount']);
        if ($grossAmount->compare(LedgerMoney::from((string) $payment->amount)) !== 0) {
            throw new PaymentConflict('Nominal callback tidak sama dengan nominal pembayaran.');
        }

        $eventFields = [
            'order_id' => $payload['order_id'],
            'transaction_id' => is_string($payload['transaction_id'] ?? null) ? $payload['transaction_id'] : '',
            'transaction_status' => $payload['transaction_status'],
            'fraud_status' => is_string($payload['fraud_status'] ?? null) ? $payload['fraud_status'] : '',
            'status_code' => $payload['status_code'],
            'gross_amount' => $grossAmount->toDecimal(),
        ];
        $eventKey = hash('sha256', json_encode($eventFields, JSON_THROW_ON_ERROR));

        DB::transaction(function () use ($payment, $eventKey, $eventFields): void {
            $lockedPayment = PaymentIntent::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            $event = DB::table('payment_webhook_events')
                ->where('provider', 'midtrans')
                ->where('event_key', $eventKey)
                ->first();
            if ($event !== null) {
                return;
            }

            $status = $eventFields['transaction_status'];
            $fraudStatus = $eventFields['fraud_status'];
            $eventType = $status;
            $processingStatus = 'ignored';

            if (($status === 'settlement' || ($status === 'capture' && $fraudStatus === 'accept'))
                && $lockedPayment->status !== 'paid') {
                if ($lockedPayment->purpose === 'warung_order') {
                    $this->postWarungOrderPayment->execute($lockedPayment);
                } elseif ($lockedPayment->purpose === 'travel_booking') {
                    $this->postTravelBookingPayment->execute($lockedPayment);
                } elseif ($lockedPayment->purpose === 'rental') {
                    $this->postRentalPayment->execute($lockedPayment);
                } else {
                    $this->postCollateralTopup($lockedPayment);
                }
                $lockedPayment->forceFill([
                    'status' => 'paid',
                    'last_provider_status' => $status,
                    'provider_transaction_id' => $eventFields['transaction_id'] !== '' ? $eventFields['transaction_id'] : null,
                    'paid_at' => now(),
                ])->save();
                $processingStatus = 'processed';
                $this->notifyPaymentOwner($lockedPayment, 'paid');
            } elseif ($status === 'pending' && ! in_array($lockedPayment->status, ['paid', 'refunded'], true)) {
                $lockedPayment->forceFill([
                    'status' => 'pending',
                    'last_provider_status' => $status,
                    'provider_transaction_id' => $eventFields['transaction_id'] !== '' ? $eventFields['transaction_id'] : null,
                ])->save();
                $processingStatus = 'processed';
            } elseif (in_array($status, ['deny', 'cancel', 'expire'], true)
                && ! in_array($lockedPayment->status, ['paid', 'refunded'], true)) {
                $failedStatus = $status === 'expire' ? 'expired' : 'failed';
                if ($lockedPayment->purpose === 'travel_booking') {
                    $this->expireTravelBookingPayment->execute($lockedPayment, $failedStatus);
                } elseif ($lockedPayment->purpose === 'rental') {
                    // Sewa tetap menunggu pembayaran; pemesan boleh mencoba lagi.
                } else {
                    $this->expireWarungOrderPayment->execute($lockedPayment, $failedStatus);
                }
                $lockedPayment->forceFill([
                    'status' => $failedStatus,
                    'last_provider_status' => $status,
                    'provider_transaction_id' => $eventFields['transaction_id'] !== '' ? $eventFields['transaction_id'] : null,
                ])->save();
                $processingStatus = 'processed';
                $this->notifyPaymentOwner($lockedPayment, $lockedPayment->status);
            } elseif (in_array($status, ['refund', 'partial_refund', 'chargeback'], true)
                && $lockedPayment->status === 'paid') {
                if ($status !== 'refund') {
                    Log::warning('Midtrans refund status requires manual payment reconciliation.', [
                        'payment_intent_id' => $lockedPayment->getKey(),
                        'event_key' => $eventKey,
                        'provider_status' => $status,
                    ]);
                    throw new PaymentConflict('Refund parsial dan chargeback perlu rekonsiliasi manual.');
                }
                $ledgerTransaction = LedgerTransaction::query()
                    ->where('idempotency_key', 'midtrans:payment:'.$lockedPayment->getKey())
                    ->firstOrFail();
                try {
                    $this->reverseTransaction->execute(
                        $ledgerTransaction->getKey(),
                        'midtrans:refund:payment:'.$lockedPayment->getKey(),
                    );
                } catch (LedgerException $exception) {
                    Log::warning('Midtrans refund could not be posted; manual payment reconciliation is required.', [
                        'payment_intent_id' => $lockedPayment->getKey(),
                        'event_key' => $eventKey,
                        'ledger_transaction_id' => $ledgerTransaction->getKey(),
                        'reason' => $exception->getMessage(),
                    ]);
                    throw new PaymentConflict('Refund tidak dapat dibukukan otomatis dan perlu rekonsiliasi manual.', previous: $exception);
                }
                $lockedPayment->forceFill([
                    'status' => 'refunded',
                    'last_provider_status' => $status,
                ])->save();
                if ($lockedPayment->purpose === 'travel_booking' && $lockedPayment->booking_id !== null) {
                    $this->cancelRefundedTravelBooking($lockedPayment->booking_id);
                }
                $processingStatus = 'processed';
                $this->notifyPaymentOwner($lockedPayment, 'refunded');
            } else {
                $lockedPayment->forceFill(['last_provider_status' => $status])->save();
            }

            DB::table('payment_webhook_events')->insert([
                'provider' => 'midtrans',
                'event_key' => $eventKey,
                'payment_intent_id' => $lockedPayment->getKey(),
                'event_type' => $eventType,
                'processing_status' => $processingStatus,
                'received_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, attempts: 3);
    }

    private function postCollateralTopup(PaymentIntent $payment): void
    {
        $this->postTransaction->execute(new PostLedgerTransactionData(
            category: LedgerCategory::CollateralTopup,
            idempotencyKey: 'midtrans:payment:'.$payment->getKey(),
            entries: [
                new LedgerEntryInput(
                    'PLATFORM.CASH',
                    LedgerDirection::Debit,
                    (string) $payment->amount,
                ),
                new LedgerEntryInput(
                    'BUSINESS.COLLATERAL:'.$payment->business_id,
                    LedgerDirection::Credit,
                    (string) $payment->amount,
                ),
            ],
            referenceType: 'payment_intent',
            referenceId: (int) $payment->getKey(),
            description: 'Top up jaminan '.$payment->code,
            createdBy: (int) $payment->user_id,
        ));
    }

    private function notifyPaymentOwner(PaymentIntent $payment, string $status): void
    {
        $user = User::query()->findOrFail($payment->user_id);
        Notification::send($user, new PaymentStatusNotification(
            $payment->code,
            $status,
            (string) $payment->amount,
        ));
    }

    private function cancelRefundedTravelBooking(int $bookingId): void
    {
        DB::transaction(function () use ($bookingId): void {
            $booking = TravelBooking::query()
                ->with('schedule')
                ->whereKey($bookingId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($booking->status !== BookingStatus::Paid->value) {
                return;
            }
            if ($booking->schedule->departure_date->isToday()
                || $booking->schedule->departure_date->isPast()) {
                Log::critical('Travel booking was refunded after its departure date; seat release requires review.', [
                    'booking_id' => $booking->getKey(),
                ]);
            } else {
                BookingPassenger::query()
                    ->where('booking_id', $booking->getKey())
                    ->whereNotNull('seat_number')
                    ->update([
                        'seat_number' => null,
                        'seat_released_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
            $booking->forceFill([
                'status' => BookingStatus::Cancelled->value,
                'cancelled_at' => now(),
            ])->save();
        }, attempts: 3);
    }
}
