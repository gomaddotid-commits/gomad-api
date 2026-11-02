<?php

namespace App\Http\Resources;

use App\Models\Business;
use App\Models\RetailListing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Isi layar kasir dalam satu jawaban: usaha, angka hari ini, dan daftar barang.
 *
 * Digabung supaya aplikasi cukup satu permintaan untuk membuka kasir. Bentuknya
 * resource (bukan array lepas) agar generator kontrak membaca tipe tiap field —
 * pengalaman sebelumnya: `response()->json([...])` membuat klien Dart menerima
 * `Object?` dan aplikasi harus menebak tipe.
 *
 * `@mixin` berikut bukan hiasan: tanpa itu generator kontrak memperingatkan
 * bahwa resource ini tidak jelas membungkus model apa (JR001).
 *
 * @mixin Business
 */
class PosCatalogResource extends JsonResource
{
    /**
     * @param  array{count: int, amount: string}  $today
     * @param  Collection<int, RetailListing>  $listings
     */
    public function __construct(
        private readonly Business $business,
        private readonly array $today,
        private readonly Collection $listings,
    ) {
        parent::__construct($business);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'business' => [
                'id' => (int) $this->business->getKey(),
                'name' => (string) $this->business->name,
            ],
            'today' => [
                'count' => (int) $this->today['count'],
                'amount' => (string) $this->today['amount'],
            ],
            'listings' => RetailListingResource::collection($this->listings),
        ];
    }
}
