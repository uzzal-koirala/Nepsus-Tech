<?php

namespace App\Models;

use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['name', 'designation', 'department', 'email', 'phone', 'status', 'joined_on'])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    public const DEPARTMENTS = [
        'design' => ['label' => 'Design', 'icon' => 'paint-brush'],
        'development' => ['label' => 'Development', 'icon' => 'code-bracket'],
        'marketing' => ['label' => 'Marketing', 'icon' => 'megaphone'],
        'content' => ['label' => 'Content', 'icon' => 'pencil-square'],
        'sales' => ['label' => 'Sales', 'icon' => 'briefcase'],
        'management' => ['label' => 'Management', 'icon' => 'building-office'],
    ];

    public const STATUSES = ['active' => 'Active', 'on_leave' => 'On leave', 'inactive' => 'Inactive'];

    protected function casts(): array
    {
        return [
            'joined_on' => 'date',
        ];
    }

    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * Time with the company, e.g. "2y 3m", "5m" or "New".
     */
    public function tenure(): ?string
    {
        if (! $this->joined_on || $this->joined_on->isFuture()) {
            return $this->joined_on ? __('Starts soon') : null;
        }

        $months = (int) $this->joined_on->diffInMonths(today());
        [$years, $months] = [intdiv($months, 12), $months % 12];

        return match (true) {
            $years > 0 && $months > 0 => "{$years}y {$months}m",
            $years > 0 => "{$years}y",
            $months > 0 => "{$months}m",
            default => __('New'),
        };
    }

    /**
     * Phone number in wa.me form (Nepal country code added to 10-digit mobiles).
     */
    public function whatsappNumber(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->phone);

        if ($digits === '') {
            return null;
        }

        return strlen($digits) === 10 && str_starts_with($digits, '9') ? '977'.$digits : $digits;
    }
}
