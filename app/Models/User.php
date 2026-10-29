<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    /** The employee this login belongs to (employee logins only). */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /** Full access. Employee logins see only their own area (Dashboard, tasks, calendar, clients, attendance). */
    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /** Admins and Content Creators: write scripts and plan every client's work on the calendar. Videos are for Video Editors (canEditVideos). */
    /** "Admin", the employee's department ("Content Creator", "Video Editor"…) or "Team member". */
    public function roleLabel(): string
    {
        return match (true) {
            $this->isAdmin() => __('Admin'),
            (bool) $this->employee?->department => __($this->employee->departmentMeta()['label']),
            default => __('Team member'),
        };
    }

    public function canPlanContent(): bool
    {
        return $this->isAdmin() || $this->employee?->department === 'content-creator';
    }

    /** Employees in the Video Editor department: an editing dashboard and the Videos page. */
    public function isVideoEditor(): bool
    {
        return ! $this->isAdmin() && $this->employee?->department === 'video-editor';
    }

    /** Admins and Video Editors: the Videos page. */
    public function canEditVideos(): bool
    {
        return $this->isAdmin() || $this->isVideoEditor();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
