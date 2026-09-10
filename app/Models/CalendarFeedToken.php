<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarFeedToken extends Model
{
    use BelongsToTenant;

    public const SCOPE_STAFF = 'staff';

    public const SCOPE_SALON = 'salon';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'staff_id',
        'scope',
        'token',
        'last_pulled_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_pulled_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function scopes(): array
    {
        return [self::SCOPE_STAFF, self::SCOPE_SALON];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * @param  Builder<CalendarFeedToken>  $query
     * @return Builder<CalendarFeedToken>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
