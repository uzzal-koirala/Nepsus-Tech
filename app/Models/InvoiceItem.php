<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['description', 'details', 'quantity', 'price', 'position'])]
class InvoiceItem extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'price' => 'decimal:2'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
