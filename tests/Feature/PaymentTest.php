<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Ledger\Application\PostLedgerTransaction;
use App\Core\Ledger\Application\ReconcileLedger;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\Notification\Application\PaymentStatusNotification;
use App\Models\Business;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\PaymentIntent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_KEY = 'sandbox-server-key-for-tests';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.midtrans.server_key' => self::SERVER_KEY,
            'services.midtrans.client_key' => 'sandbox-client-key-for-tests',
            'services.midtrans.snap_url' => 'https://snap.sandbox.test/v1/transactions',
            'services.midtrans.payment_timeout' => 30,
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_owner_can_create_idempotent_snap_payment_without_storing_card_data(): void
    {
        Http::fake(['https://snap.sandbox.test/*' => Http::response([
            'token' => 'snap-token-test',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-test',
        ])]);
        [$owner, $business] = $this->agency();
        $token = $owner->createToken('payment-test')->plainTextToken;

        $first = $this->withToken($token)
            ->withHeader('Idempotency-Key', 'collateral-topup-1')
            ->postJson('/api/v1/payments/initiate', [
                'business_id' => $business->id,
                'amount' => '150000',
            ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', '150000.00')
            ->assertJsonPath('data.snap_token', 'snap-token-test')
            ->assertJsonPath('data.provider', 'midtrans_snap');

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'collateral-topup-1')
            ->postJson('/api/v1/payments/initiate', [
                'business_id' => $business->id,
                'amount' => '150000',
            ])->assertOk()
            ->assertJsonPath('data.code', $first->json('data.code'));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($business): bool {
            $payload = $request->data();

            return $request->url() === 'https://snap.sandbox.test/v1/transactions'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode(self::SERVER_KEY.':'))
                && $payload['transaction_details']['gross_amount'] === 150000
                && $payload['item_details'][0]['id'] === 'collateral-'.$business->id
                && ! array_key_exists('card_number', $payload)
                && ! array_key_exists('card_cvv', $payload);
        });
        $this->assertDatabaseCount('payment_intents', 1);
        $this->assertSame('0.00', LedgerAccount::query()
            ->where('code', 'BUSINESS.COLLATERAL:'.$business->id)
            ->value('balance_cached'));
    }

    public function test_payment_idempotency_key_cannot_be_reused_for_another_amount(): void
    {
        Http::fake(['https://snap.sandbox.test/*' => Http::response([
            'token' => 'snap-token-test',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/redirect',
        ])]);
        [$owner, $business] = $this->agency();
        $token = $owner->createToken('payment-test')->plainTextToken;

        $this->withToken($token)->withHeader('Idempotency-Key', 'topup-key')
            ->postJson('/api/v1/payments/initiate', ['business_id' => $business->id, 'amount' => '1000'])
            ->assertCreated();
        $this->withToken($token)->withHeader('Idempotency-Key', 'topup-key')
            ->postJson('/api/v1/payments/initiate', ['business_id' => $business->id, 'amount' => '2000'])
            ->assertConflict()
            ->assertJsonPath('code', 'PAYMENT_CONFLICT');

        Http::assertSentCount(1);
    }

    public function test_gateway_failure_is_reported_and_requires_a_new_idempotency_key_to_retry(): void
    {
        Http::fakeSequence('https://snap.sandbox.test/*')
            ->push(['message' => 'request rejected'], 422)
            ->push([
                'token' => 'snap-token-retry',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/redirect',
            ], 201);
        [$owner, $business] = $this->agency();
        $token = $owner->createToken('payment-test')->plainTextToken;
        $payload = ['business_id' => $business->id, 'amount' => '100000'];

        $this->withToken($token)->withHeader('Idempotency-Key', 'payment-retry-key')
            ->postJson('/api/v1/payments/initiate', $payload)
            ->assertStatus(502)
            ->assertJsonPath('code', 'PAYMENT_GATEWAY_UNAVAILABLE');
        $failedPayment = PaymentIntent::query()->where('idempotency_key', 'payment-retry-key')->firstOrFail();
        $this->assertSame('failed', $failedPayment->status);

        $this->withToken($token)->withHeader('Idempotency-Key', 'payment-retry-key')
            ->postJson('/api/v1/payments/initiate', $payload)
            ->assertConflict()
            ->assertJsonPath('code', 'PAYMENT_CONFLICT');
        $this->withToken($token)->withHeader('Idempotency-Key', 'payment-retry-key-2')
            ->postJson('/api/v1/payments/initiate', $payload)
            ->assertCreated()
            ->assertJsonPath('data.snap_token', 'snap-token-retry');

        Http::assertSentCount(2);
        $this->assertDatabaseCount('payment_intents', 2);
        $this->assertDatabaseCount('ledger_transactions', 0);
    }

    public function test_non_agency_members_cannot_initiate_collateral_payments(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $warung = Business::query()->create([
            'type' => 'warung',
            'name' => 'Test Warung',
            'code' => 'GM-WARUNG-PAY00001',
            'owner_user_id' => $owner->id,
            'status' => 'active',
        ]);
        $warung->members()->attach($owner, ['role' => 'pemilik', 'status' => 'active', 'joined_at' => now()]);
        $token = $owner->createToken('payment-test')->plainTextToken;

        $this->withToken($token)->withHeader('Idempotency-Key', 'warung-topup')
            ->postJson('/api/v1/payments/initiate', ['business_id' => $warung->id, 'amount' => '1000'])
            ->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_unsigned_midtrans_webhook_is_rejected_without_side_effects(): void
    {
        $payment = $this->paymentIntent();
        Notification::fake();
        Log::spy();
        $payload = $this->webhookPayload($payment, 'settlement');
        $payload['signature_key'] = str_repeat('0', 128);

        $this->postJson('/api/v1/webhooks/midtrans', $payload)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'INVALID_WEBHOOK_SIGNATURE');
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertDatabaseCount('payment_webhook_events', 0);
        $this->assertDatabaseCount('ledger_transactions', 0);
        Notification::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_valid_settlement_callback_is_idempotent_and_notifies_owner(): void
    {
        Notification::fake();
        $payment = $this->paymentIntent();
        $payload = $this->webhookPayload($payment, 'settlement');

        foreach (range(1, 3) as $_) {
            $this->postJson('/api/v1/webhooks/midtrans', $payload)
                ->assertOk()
                ->assertJsonPath('status', 'ok');
        }

        $payment->refresh();
        $this->assertSame('paid', $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(1, LedgerTransaction::query()->count());
        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertSame('100000.00', $this->account('PLATFORM.CASH')->balance_cached);
        $this->assertSame('100000.00', $this->account('BUSINESS.COLLATERAL:'.$payment->business_id)->balance_cached);
        Notification::assertSentToTimes($payment->user, PaymentStatusNotification::class, 1);
        Notification::assertSentTo(
            $payment->user,
            PaymentStatusNotification::class,
            fn (PaymentStatusNotification $notification, array $channels): bool => $channels === ['database', 'mail'],
        );
        $reconciliation = app(ReconcileLedger::class)->execute();
        $this->assertSame([], $reconciliation['unbalanced_transactions']);
        $this->assertTrue(collect($reconciliation['accounts'])->every(
            fn (array $account): bool => $account['matches'],
        ));
    }

    public function test_payment_amount_mismatch_is_rejected_even_with_a_valid_signature(): void
    {
        $payment = $this->paymentIntent();
        $payload = $this->webhookPayload($payment, 'settlement');
        $payload['gross_amount'] = '99999.00';
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].self::SERVER_KEY,
        );

        $this->postJson('/api/v1/webhooks/midtrans', $payload)
            ->assertConflict()
            ->assertJsonPath('code', 'PAYMENT_CONFLICT');
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertDatabaseCount('payment_webhook_events', 0);
        $this->assertDatabaseCount('ledger_transactions', 0);
    }

    public function test_paid_callback_then_old_pending_callback_does_not_downgrade_payment(): void
    {
        Notification::fake();
        $payment = $this->paymentIntent();
        $paid = $this->webhookPayload($payment, 'settlement');
        $this->postJson('/api/v1/webhooks/midtrans', $paid)->assertOk();

        $pending = $this->webhookPayload($payment, 'pending');
        $this->postJson('/api/v1/webhooks/midtrans', $pending)->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseCount('payment_webhook_events', 2);
    }

    public function test_refund_callback_appends_a_reversal_without_mutating_original_entries(): void
    {
        Notification::fake();
        $payment = $this->paymentIntent();
        $paid = $this->webhookPayload($payment, 'settlement');
        $this->postJson('/api/v1/webhooks/midtrans', $paid)->assertOk();
        $original = LedgerTransaction::query()->firstOrFail();
        $originalEntries = $original->entries()->orderBy('id')->get()->map(fn ($entry): array => [
            $entry->direction, $entry->amount, $entry->balance_after,
        ])->all();

        $refund = $this->webhookPayload($payment, 'refund');
        $refund['transaction_id'] = 'midtrans-refund-test-transaction';
        $this->postJson('/api/v1/webhooks/midtrans', $refund)->assertOk();

        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame(2, LedgerTransaction::query()->count());
        $this->assertSame('reversed', $original->fresh()->status);
        $this->assertSame($originalEntries, $original->entries()->orderBy('id')->get()->map(fn ($entry): array => [
            $entry->direction, $entry->amount, $entry->balance_after,
        ])->all());
        $this->assertSame('0.00', $this->account('PLATFORM.CASH')->balance_cached);
        $this->assertSame('0.00', $this->account('BUSINESS.COLLATERAL:'.$payment->business_id)->balance_cached);
    }

    public function test_refund_cannot_silently_overdraw_collateral_and_is_flagged_for_manual_reconciliation(): void
    {
        Notification::fake();
        Log::spy();
        $payment = $this->paymentIntent();
        $this->postJson('/api/v1/webhooks/midtrans', $this->webhookPayload($payment, 'settlement'))->assertOk();
        app(PostLedgerTransaction::class)->execute(new PostLedgerTransactionData(
            category: LedgerCategory::Adjustment,
            idempotencyKey: 'spend-collateral-before-refund:'.$payment->getKey(),
            entries: [
                new LedgerEntryInput(
                    'BUSINESS.COLLATERAL:'.$payment->business_id,
                    LedgerDirection::Debit,
                    '100000.00',
                ),
                new LedgerEntryInput(
                    'BUSINESS.AVAILABLE:'.$payment->business_id,
                    LedgerDirection::Credit,
                    '100000.00',
                ),
            ],
            referenceType: 'test_collateral_transfer',
            createdBy: (int) $payment->user_id,
        ));
        $refund = $this->webhookPayload($payment, 'refund');
        $refund['transaction_id'] = 'midtrans-refund-after-spend';
        $refund['signature_key'] = hash(
            'sha512',
            $refund['order_id'].$refund['status_code'].$refund['gross_amount'].self::SERVER_KEY,
        );

        $this->postJson('/api/v1/webhooks/midtrans', $refund)
            ->assertConflict()
            ->assertJsonPath('code', 'PAYMENT_CONFLICT')
            ->assertJsonPath('message', 'Refund tidak dapat dibukukan otomatis dan perlu rekonsiliasi manual.');

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('0.00', $this->account('BUSINESS.COLLATERAL:'.$payment->business_id)->balance_cached);
        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertSame(2, LedgerTransaction::query()->count());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_user_can_only_read_their_own_payments_and_notifications(): void
    {
        [$owner, $business] = $this->agency();
        $other = User::factory()->create();
        $payment = PaymentIntent::query()->create([
            'code' => 'PAY-PRIVATE-TEST',
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'purpose' => 'collateral_topup',
            'amount' => '1000.00',
            'currency' => 'IDR',
            'idempotency_key' => 'private-payment-key',
            'request_hash' => hash('sha256', 'private'),
            'provider_order_id' => 'PAY-PRIVATE-TEST',
            'status' => 'pending',
        ]);
        $notificationId = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $notificationId,
            'type' => PaymentStatusNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $owner->getKey(),
            'data' => json_encode(['payment_code' => $payment->code], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/payments/'.$payment->getKey())
            ->assertNotFound();
        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonMissing(['id' => $notificationId]);
        $this->postJson('/api/v1/notifications/'.$notificationId.'/read')
            ->assertNotFound();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonFragment(['id' => $notificationId]);
        $this->postJson('/api/v1/notifications/'.$notificationId.'/read')
            ->assertOk()
            ->assertJsonPath('data.id', $notificationId);
        $this->assertNotNull(DB::table('notifications')->where('id', $notificationId)->value('read_at'));
    }

    /**
     * @return array{User, Business}
     */
    private function agency(): array
    {
        $owner = User::factory()->create();
        $business = Business::query()->create([
            'type' => 'agency',
            'name' => 'Payment Test Agency',
            'code' => 'GM-AGENCY-PAY'.str_pad((string) (Business::query()->count() + 1), 5, '0', STR_PAD_LEFT),
            'owner_user_id' => $owner->id,
            'status' => 'active',
        ]);
        $business->members()->attach($owner, [
            'role' => 'pemilik',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(LedgerAccountSeeder::class)->ensureBusinessAccounts($business);

        return [$owner, $business];
    }

    private function paymentIntent(): PaymentIntent
    {
        [$owner, $business] = $this->agency();

        return PaymentIntent::query()->create([
            'code' => 'PAY-TEST-'.str_pad((string) (PaymentIntent::query()->count() + 1), 5, '0', STR_PAD_LEFT),
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'purpose' => 'collateral_topup',
            'amount' => '100000.00',
            'currency' => 'IDR',
            'idempotency_key' => 'webhook-payment-'.$business->id,
            'request_hash' => hash('sha256', 'payment-'.$business->id),
            'provider_order_id' => 'PAY-TEST-'.str_pad((string) PaymentIntent::query()->count(), 5, '0', STR_PAD_LEFT),
            'status' => 'pending',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function webhookPayload(PaymentIntent $payment, string $status): array
    {
        $payload = [
            'order_id' => $payment->provider_order_id,
            'status_code' => '200',
            'gross_amount' => '100000.00',
            'transaction_status' => $status,
            'transaction_id' => 'midtrans-transaction-'.$payment->getKey(),
            'fraud_status' => 'accept',
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].self::SERVER_KEY,
        );

        return $payload;
    }

    private function account(string $code): LedgerAccount
    {
        return LedgerAccount::query()->where('code', $code)->firstOrFail();
    }
}
