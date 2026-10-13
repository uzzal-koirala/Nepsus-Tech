<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

#[Fillable(['name', 'type', 'brand', 'model', 'plate_no', 'year', 'employee_id', 'notes'])]
class Vehicle extends Model
{
    /** Kinds of vehicle, each with a 3D picture in public/images/vehicles. */
    public const TYPES = [
        'scooter' => ['label' => 'Scooter', 'image' => 'scooter', 'tint' => 'from-rose-50 to-orange-50 dark:from-rose-500/10 dark:to-orange-500/5'],
        'bike' => ['label' => 'Bike', 'image' => 'bike', 'tint' => 'from-sky-50 to-indigo-50 dark:from-sky-500/10 dark:to-indigo-500/5'],
        'car' => ['label' => 'Car', 'image' => 'car', 'tint' => 'from-red-50 to-amber-50 dark:from-red-500/10 dark:to-amber-500/5'],
        'suv' => ['label' => 'Jeep / SUV', 'image' => 'suv', 'tint' => 'from-blue-50 to-cyan-50 dark:from-blue-500/10 dark:to-cyan-500/5'],
        'van' => ['label' => 'Van', 'image' => 'van', 'tint' => 'from-emerald-50 to-teal-50 dark:from-emerald-500/10 dark:to-teal-500/5'],
        'truck' => ['label' => 'Truck', 'image' => 'truck', 'tint' => 'from-amber-50 to-yellow-50 dark:from-amber-500/10 dark:to-yellow-500/5'],
        'bicycle' => ['label' => 'Bicycle', 'image' => 'bicycle', 'tint' => 'from-lime-50 to-emerald-50 dark:from-lime-500/10 dark:to-emerald-500/5'],
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
        ];
    }

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(self::TYPES))],
            'brand' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'plate_no' => ['nullable', 'string', 'max:40'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(VehicleExpense::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function typeMeta(): array
    {
        return self::TYPES[$this->type] ?? self::TYPES['scooter'];
    }

    public function imageUrl(): string
    {
        return asset('images/vehicles/'.$this->typeMeta()['image'].'.png');
    }

    /** "Honda Dio · 2022", or just the type. */
    public function subtitle(): string
    {
        return collect([trim($this->brand.' '.$this->model), $this->year])->filter()->join(' · ') ?: __($this->typeMeta()['label']);
    }
}
