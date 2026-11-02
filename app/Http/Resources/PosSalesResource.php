<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Halaman riwayat nota kasir: daftar nota + keterangan halaman.
 *
 * Dibungkus resource supaya generator kontrak membaca tipe tiap nota. Sebelumnya
 * daftar dikirim sebagai larik lepas di dalam `response()->json`, dan klien Dart
 * menerimanya sebagai `List<Object>` — aplikasi lalu harus menebak isi nota.
 *
 * Catatan generator: Scramble memperingatkan JR001 karena kelas ini tidak
 * membungkus satu model (isinya halaman paginasi, bukan satu baris database).
 * Peringatan itu tidak memengaruhi hasil: skema `data` tetap berupa larik
 * `RetailSaleResource` yang bertipe. Percobaan memakai `ResourceCollection`
 * justru menghasilkan `allOf` dan kelas pembungkus di klien, jadi bentuk ini
 * dipertahankan.
 */
class PosSalesResource extends JsonResource
{
    public function __construct(private readonly LengthAwarePaginator $sales)
    {
        parent::__construct($sales);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => RetailSaleResource::collection($this->sales->items()),
            'meta' => [
                'current_page' => (int) $this->sales->currentPage(),
                'per_page' => (int) $this->sales->perPage(),
                'total' => (int) $this->sales->total(),
                'last_page' => (int) $this->sales->lastPage(),
            ],
        ];
    }
}
