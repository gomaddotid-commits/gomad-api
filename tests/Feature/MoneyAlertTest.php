<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Ledger\Application\PostLedgerTransaction;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Models\Business;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MoneyAlertTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://alerts.test/hooks/gomad';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.alerts.webhook_url' => self::WEBHOOK,
            'services.alerts.timeout' => 2,
            'services.midtrans.payment_timeout' => 30,
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_clean_ledger_sends_no_alert(): void
    {
        Http::fake([self::WEBHOOK => Http::response(['ok' => true], 200)]);

        $this->artisan('gomad:money-health')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_ledger_mismatch_is_pushed_to_the_alert_webhook(): void
    {
        $this->fundAgency(150000);
        LedgerAccount::query()->where('code', 'PLATFORM.CASH')->update(['balance_cached' => '777777.00']);

        Http::fake([self::WEBHOOK => Http::response(['ok' => true], 200)]);

        $this->artisan('gomad:money-health')->assertExitCode(1);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === self::WEBHOOK
                && ($body['severity'] ?? null) === 'critical'
                && str_contains((string) ($body['message'] ?? ''), 'PLATFORM.CASH')
                && ($body['context']['source'] ?? null) === 'gomad:money-health';
        });
    }

    public function test_daily_reconcile_also_alerts_when_it_finds_a_difference(): void
    {
        $this->fundAgency(90000);
        LedgerAccount::query()->where('code', 'BUSINESS.COLLATERAL:1')->update(['balance_cached' => '1.00']);

        Http::fake([self::WEBHOOK => Http::response(['ok' => true], 200)]);

        $this->artisan('gomad:reconcile')->assertExitCode(1);

        Http::assertSent(fn (Request $request): bool => ($request->data()['context']['source'] ?? null) === 'gomad:reconcile');
    }

    public function test_a_broken_webhook_does_not_change_the_command_result(): void
    {
        $this->fundAgency(150000);
        LedgerAccount::query()->where('code', 'PLATFORM.CASH')->update(['balance_cached' => '1.00']);

        Http::fake([self::WEBHOOK => Http::response('boom', 500)]);

        $this->artisan('gomad:money-health')->assertExitCode(1);
    }

    private function fundAgency(int $amount): void
    {
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Agency Alert',
            'code' => 'GM-AGENCY-ALT1',
            'owner_user_id' => User::factory()->create(['status' => 'active'])->getKey(),
            'status' => 'active',
        ]);
        app(LedgerAccountSeeder::class)->ensureBusinessAccounts($agency);

        app(PostLedgerTransaction::class)->execute(new PostLedgerTransactionData(
            category: LedgerCategory::CollateralTopup,
            idempotencyKey: 'alert-topup-'.$agency->getKey(),
            entries: [
                new LedgerEntryInput('PLATFORM.CASH', LedgerDirection::Debit, (string) $amount),
                new LedgerEntryInput(
                    'BUSINESS.COLLATERAL:'.$agency->getKey(),
                    LedgerDirection::Credit,
                    (string) $amount,
                ),
            ],
            referenceType: 'test',
            referenceId: (int) $agency->getKey(),
            description: 'Fixture alert',
        ));
    }
}
