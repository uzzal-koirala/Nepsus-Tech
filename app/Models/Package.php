<?php

namespace App\Models;

use App\Support\PackageTheme;
use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'icon', 'color', 'price', 'billing_cycle', 'features', 'is_active', 'position'])]
class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * Tailwind classes for this package's colour.
     *
     * @return array<string, string>
     */
    public function theme(): array
    {
        return PackageTheme::get($this->color);
    }

    public function formattedPrice(): ?string
    {
        return $this->price === null ? null : Client::money($this->price);
    }
}
