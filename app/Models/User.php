<?php

namespace App\Models;

use App\Enums\ThemePreference;
use App\Enums\UserRole;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasFactory, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_bookable',
        'can_see_customer_contacts',
        'is_active',
        'colour',
        'is_super_admin',
        'theme_preference',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_bookable' => 'boolean',
            'can_see_customer_contacts' => 'boolean',
            'is_active' => 'boolean',
            'is_super_admin' => 'boolean',
            'theme_preference' => ThemePreference::class,
        ];
    }

    public function isOwner(): bool
    {
        return $this->role === UserRole::Owner;
    }

    public function calendarToken(): string
    {
        if ($this->calendar_token === null) {
            $this->calendar_token = bin2hex(random_bytes(16));
            $this->saveQuietly();
        }

        return $this->calendar_token;
    }

    public function regenerateCalendarToken(): string
    {
        $this->calendar_token = bin2hex(random_bytes(16));
        $this->saveQuietly();

        return $this->calendar_token;
    }

    /** @return BelongsToMany<Service, $this> */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class);
    }

    /** @return HasMany<AvailabilityRule, $this> */
    public function availabilityRules(): HasMany
    {
        return $this->hasMany(AvailabilityRule::class);
    }

    /** @return HasMany<TimeOff, $this> */
    public function timeOff(): HasMany
    {
        return $this->hasMany(TimeOff::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'staff_id');
    }
}
