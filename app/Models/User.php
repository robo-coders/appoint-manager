<?php

namespace App\Models;

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

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_bookable',
        'is_active',
        'colour',
        'is_super_admin',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_bookable' => 'boolean',
            'is_active' => 'boolean',
            'is_super_admin' => 'boolean',
        ];
    }

    public function isOwner(): bool
    {
        return $this->role === UserRole::Owner;
    }

    /**
     * This person's calendar subscription token, minted on first use.
     *
     * Not fillable and not in `$fillable` above: it is a credential, and a
     * credential a staff form could mass-assign is a credential anybody with a
     * form can choose. `saveQuietly` because minting a token is not a change to
     * the member of staff that anything should observe.
     *
     * `random_bytes(16)` rather than a UUID: 128 bits either way, but a UUID
     * carries a version and a variant and reads as an identifier somebody may
     * feel free to log. This reads as a secret.
     */
    public function calendarToken(): string
    {
        if ($this->calendar_token === null) {
            $this->calendar_token = bin2hex(random_bytes(16));
            $this->saveQuietly();
        }

        return $this->calendar_token;
    }

    /**
     * Throw the old token away and issue a new one.
     *
     * This is the whole answer to "the link got forwarded to someone who should
     * not have it": the previous URL stops resolving immediately, and the member
     * of staff is sent the new one. Their calendar app will keep polling the old
     * address and quietly show nothing, which is why the screen says to re-send
     * the link.
     */
    public function regenerateCalendarToken(): string
    {
        $this->calendar_token = bin2hex(random_bytes(16));
        $this->saveQuietly();

        return $this->calendar_token;
    }

    /**
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class);
    }

    /**
     * @return HasMany<AvailabilityRule, $this>
     */
    public function availabilityRules(): HasMany
    {
        return $this->hasMany(AvailabilityRule::class);
    }

    /**
     * @return HasMany<TimeOff, $this>
     */
    public function timeOff(): HasMany
    {
        return $this->hasMany(TimeOff::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'staff_id');
    }
}
