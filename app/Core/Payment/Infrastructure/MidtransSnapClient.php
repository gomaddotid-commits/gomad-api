<?php

namespace App\Core\Payment\Infrastructure;

use App\Core\Payment\Domain\PaymentGatewayException;
use App\Models\OrderItem;
use App\Models\PaymentIntent;
use App\Models\TravelBooking;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class MidtransSnapClient
{
    /**
     * @return array{token: string, redirect_url: string}
     */
    public function createTransaction(PaymentIntent $payment): array
    {
        $serverKey = config('services.midtrans.server_key');
        $snapUrl = $this->resolveSnapUrl(config('services.midtrans.snap_url'));
        if (! is_string($serverKey) || $serverKey === '' || $snapUrl === '') {
            throw new PaymentGatewayException('Kredensial Midtrans belum dikonfigurasi.');
        }
        if (! str_contains($snapUrl, '/transactions')) {
            throw new PaymentGatewayException(
                'MIDTRANS_SNAP_URL harus menunjuk endpoint /snap/v1/transactions, bukan URL pustaka snap.js.',
            );
        }
        $amount = (string) $payment->amount;
        if (! preg_match('/^([0-9]+)\.00$/', $amount, $matches) || strlen($matches[1]) > 15) {
            throw new PaymentGatewayException('Nominal pembayaran harus berupa rupiah bulat yang didukung gateway.');
        }
        $rupiah = (int) $matches[1];

        $itemDetails = $payment->purpose === 'warung_order'
            ? $this->warungOrderItems($payment)
            : ($payment->purpose === 'travel_booking'
                ? $this->travelBookingItems($payment)
            : [[
                'id' => 'collateral-'.$payment->business_id,
                'price' => $rupiah,
                'quantity' => 1,
                'name' => 'Top up jaminan GoMad',
            ]]);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withBasicAuth($serverKey, '')
                ->connectTimeout(5)
                ->timeout(20)
                ->post($snapUrl, [
                    'transaction_details' => [
                        'order_id' => $payment->provider_order_id,
                        'gross_amount' => $rupiah,
                    ],
                    'customer_details' => array_filter([
                        'first_name' => $payment->user->name,
                        'email' => $payment->user->email,
                        'phone' => $payment->user->phone,
                    ], fn (mixed $value): bool => is_string($value) && trim($value) !== ''),
                    'item_details' => $itemDetails,
                    'expiry' => [
                        'unit' => 'minute',
                        'duration' => (int) config('services.midtrans.payment_timeout', 30),
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new PaymentGatewayException('Tidak dapat terhubung ke gateway pembayaran.', $exception, outcomeUnknown: true);
        }

        try {
            $response->throw();
        } catch (RequestException $exception) {
            $gatewayMessage = $this->gatewayMessage($exception);
            Log::warning('Midtrans Snap transaction request was rejected.', [
                'payment_intent_id' => $payment->getKey(),
                'http_status' => $exception->response->status(),
                'gateway_message' => $gatewayMessage,
            ]);
            throw new PaymentGatewayException(
                $gatewayMessage === ''
                    ? 'Gateway pembayaran menolak permintaan.'
                    : 'Gateway pembayaran menolak permintaan: '.$gatewayMessage,
                $exception,
                outcomeUnknown: $exception->response->serverError(),
            );
        }

        $token = $response->json('token');
        $redirectUrl = $response->json('redirect_url');
        if (! is_string($token) || $token === '' || ! is_string($redirectUrl) || ! str_starts_with($redirectUrl, 'https://')) {
            throw new PaymentGatewayException('Gateway memberikan respons transaksi yang tidak valid.', outcomeUnknown: true);
        }

        return ['token' => $token, 'redirect_url' => $redirectUrl];
    }

    /**
     * Accept the JavaScript library URL (`.../snap/snap.js`) as well, because it is
     * a common copy-paste mistake, and derive the transaction endpoint from it.
     */
    private function resolveSnapUrl(mixed $configured): string
    {
        $url = is_string($configured) ? trim($configured) : '';

        if ($url === '') {
            return '';
        }

        if (str_ends_with($url, '/snap.js')) {
            return substr($url, 0, -mb_strlen('/snap.js')).'/v1/transactions';
        }

        return $url;
    }

    /**
     * Extract a short, credential-free reason from a gateway error response.
     */
    private function gatewayMessage(RequestException $exception): string
    {
        $payload = $exception->response->json();
        if (! is_array($payload)) {
            return '';
        }

        $parts = [];

        if (isset($payload['status_message']) && is_string($payload['status_message'])) {
            $parts[] = $payload['status_message'];
        }

        if (isset($payload['error_messages']) && is_array($payload['error_messages'])) {
            foreach ($payload['error_messages'] as $message) {
                if (is_string($message) && $message !== '') {
                    $parts[] = $message;
                }
            }
        }

        return mb_substr(implode('; ', array_unique($parts)), 0, 300);
    }

    /**
     * @return list<array{id: string, price: int, quantity: int, name: string}>
     */
    private function warungOrderItems(PaymentIntent $payment): array
    {
        $order = $payment->order;
        if ($order === null) {
            throw new PaymentGatewayException('Pesanan Mad Warung pada pembayaran tidak ditemukan.');
        }

        $items = $order->items->map(function (OrderItem $item): array {
            return [
                'id' => substr('product-'.$item->sku, 0, 50),
                'price' => (int) $item->unit_price,
                'quantity' => $item->quantity,
                'name' => mb_substr($item->product_name, 0, 50),
            ];
        })->values()->all();
        if ((int) $order->platform_fee > 0) {
            $items[] = [
                'id' => 'gomad-platform-fee',
                'price' => (int) $order->platform_fee,
                'quantity' => 1,
                'name' => 'Biaya layanan platform GoMad',
            ];
        }
        if ((int) $order->ppn_amount > 0) {
            $items[] = [
                'id' => 'gomad-ppn',
                'price' => (int) $order->ppn_amount,
                'quantity' => 1,
                'name' => 'PPN',
            ];
        }

        $itemTotal = array_sum(array_map(
            fn (array $item): int => $item['price'] * $item['quantity'],
            $items,
        ));
        if ($itemTotal !== (int) $order->total_amount) {
            throw new PaymentGatewayException('Rincian nominal checkout tidak sama dengan total pesanan.');
        }

        return $items;
    }

    /**
     * @return list<array{id: string, price: int, quantity: int, name: string}>
     */
    private function travelBookingItems(PaymentIntent $payment): array
    {
        $booking = $payment->booking;
        if (! $booking instanceof TravelBooking) {
            throw new PaymentGatewayException('Pemesanan Mad Trans pada pembayaran tidak ditemukan.');
        }

        $unitFare = (string) $booking->routePricing->price;
        if (! preg_match('/^([0-9]+)\.00$/', $unitFare, $matches) || strlen($matches[1]) > 15) {
            throw new PaymentGatewayException('Tarif perjalanan harus berupa rupiah bulat yang didukung gateway.');
        }
        $items = [[
            'id' => 'travel-'.$booking->code,
            'price' => (int) $matches[1],
            'quantity' => $booking->total_passengers,
            'name' => mb_substr(
                $booking->schedule->route->origin_city.' - '.$booking->schedule->route->destination_city,
                0,
                50,
            ),
        ]];
        foreach ([
            'service_fee' => ['madtrans-service-fee', 'Biaya layanan GoMad'],
            'platform_fee' => ['madtrans-platform-fee', 'Biaya platform GoMad'],
            'ppn_amount' => ['madtrans-ppn', 'PPN'],
        ] as $field => [$id, $name]) {
            $amount = (string) $booking->{$field};
            if (! preg_match('/^([0-9]+)\.00$/', $amount, $matches) || strlen($matches[1]) > 15) {
                throw new PaymentGatewayException('Komponen pembayaran harus berupa rupiah bulat yang didukung gateway.');
            }
            if ((int) $matches[1] > 0) {
                $items[] = [
                    'id' => $id,
                    'price' => (int) $matches[1],
                    'quantity' => 1,
                    'name' => $name,
                ];
            }
        }

        // Diskon promo ditampilkan sebagai baris tersendiri agar rincian checkout
        // tetap sama dengan total yang benar-benar ditagihkan.
        $discount = (string) $booking->discount_amount;
        if (preg_match('/^([0-9]+)\.00$/', $discount, $discountMatches) && (int) $discountMatches[1] > 0) {
            $items[] = [
                'id' => 'madtrans-promo',
                'price' => -1 * (int) $discountMatches[1],
                'quantity' => 1,
                'name' => mb_substr('Diskon promo '.($booking->promo_code ?? ''), 0, 50),
            ];
        }

        $itemTotal = array_sum(array_map(
            fn (array $item): int => $item['price'] * $item['quantity'],
            $items,
        ));
        if ($itemTotal !== (int) $booking->total_price) {
            throw new PaymentGatewayException('Rincian checkout perjalanan tidak sama dengan total pemesanan.');
        }

        return $items;
    }
}
