<?php

namespace App\Core\Warung\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PublishCatalogProduct
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function execute(
        User $admin,
        string $name,
        string $unit,
        ?string $category,
        ?string $sku = null,
    ): Product {
        $this->authorizer->authorize($admin, 'catalog.publish');
        $name = trim($name);
        $unit = trim($unit);
        if ($name === '' || $unit === '') {
            throw new InvalidArgumentException('Nama produk dan satuan wajib diisi.');
        }
        $sku ??= 'MW-'.Str::upper(Str::random(12));
        if (! preg_match('/^[A-Z0-9_-]{2,40}$/', $sku)) {
            throw new InvalidArgumentException('SKU hanya boleh berisi huruf besar, angka, tanda hubung, atau garis bawah.');
        }

        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'category' => $category === null ? null : trim($category),
            'unit' => $unit,
            'is_active' => true,
            'created_by' => $admin->getKey(),
        ]);
    }
}
