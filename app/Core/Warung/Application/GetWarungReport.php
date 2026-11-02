<?php

namespace App\Core\Warung\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class GetWarungReport
{
    private const PAID_STATUSES = [
        'paid',
        'preparing',
        'ready',
        'in_delivery',
        'ready_for_pickup',
        'received',
        'completed',
    ];

    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user, Business $business): array
    {
        $this->authorizer->authorize($user, 'order.report', $business);
        if (! in_array($business->type, ['warung', 'grosir'], true)) {
            abort(404);
        }

        $orders = DB::table('orders')
            ->where($business->type === 'warung' ? 'business_id' : 'wholesaler_id', $business->getKey());
        $statusRows = (clone $orders)
            ->select('status', DB::raw('COUNT(*) as order_count'), DB::raw('SUM(total_amount) as order_amount'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();
        $paid = (clone $orders)->whereIn('status', self::PAID_STATUSES);
        $byCounterparty = (clone $orders)
            ->join(
                'businesses as counterparty',
                'counterparty.id',
                '=',
                $business->type === 'warung' ? 'orders.wholesaler_id' : 'orders.business_id',
            )
            ->select(
                'counterparty.id',
                'counterparty.name',
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(orders.total_amount) as order_amount'),
            )
            ->groupBy('counterparty.id', 'counterparty.name')
            ->orderByDesc('order_count')
            ->get();
        $topProducts = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where($business->type === 'warung' ? 'orders.business_id' : 'orders.wholesaler_id', $business->getKey())
            ->select(
                'order_items.product_id',
                'order_items.sku',
                'order_items.product_name',
                'order_items.unit',
                DB::raw('SUM(order_items.quantity) as quantity'),
                DB::raw('SUM(order_items.subtotal) as item_amount'),
            )
            ->groupBy('order_items.product_id', 'order_items.sku', 'order_items.product_name', 'order_items.unit')
            ->orderByDesc('quantity')
            ->limit(10)
            ->get();

        return [
            'business_id' => $business->getKey(),
            'business_name' => $business->name,
            'order_count' => (int) (clone $orders)->count(),
            'ordered_amount_total' => $this->decimal((clone $orders)->sum('total_amount')),
            'paid_order_count' => (int) $paid->count(),
            'paid_amount_total' => $this->decimal($paid->sum('total_amount')),
            'by_status' => $statusRows->map(fn (object $row): array => [
                'status' => $row->status,
                'order_count' => (int) $row->order_count,
                'order_amount' => $this->decimal($row->order_amount),
            ])->values()->all(),
            'by_counterparty' => $byCounterparty->map(fn (object $row): array => [
                'business_id' => (int) $row->id,
                'business_name' => $row->name,
                'order_count' => (int) $row->order_count,
                'order_amount' => $this->decimal($row->order_amount),
            ])->values()->all(),
            'top_products' => $topProducts->map(fn (object $row): array => [
                'product_id' => (int) $row->product_id,
                'sku' => $row->sku,
                'name' => $row->product_name,
                'unit' => $row->unit,
                'quantity' => (int) $row->quantity,
                'item_amount' => $this->decimal($row->item_amount),
            ])->values()->all(),
        ];
    }

    private function decimal(string|int|float|null $value): string
    {
        $amount = (string) ($value ?? '0');
        if (! preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/', $amount, $matches)) {
            throw new \UnexpectedValueException('Aggregate nominal pesanan memiliki format yang tidak valid.');
        }

        return $matches[1].'.'.str_pad($matches[2] ?? '', 2, '0');
    }
}
