<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['email', 'team_name', 'shopify_order_id', 'sent_at'])]
class Order extends Model
{
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Case-insensitive on purpose (mirrors the original tool's explicit
     * strtolower() comparisons) — done in application code rather than
     * relying on the database column's collation, since that varies
     * between environments (e.g. MariaDB's ci collation vs. SQLite's
     * case-sensitive default in tests).
     */
    public function scopeForTeam(Builder $query, string $email, string $teamName): Builder
    {
        return $query
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->whereRaw('LOWER(team_name) = ?', [Str::lower(static::normalizeTeamName($teamName))]);
    }

    public static function hasBeenSentFor(string $email, string $teamName): ?self
    {
        return static::query()->forTeam($email, $teamName)->first();
    }

    /**
     * The BioSteel Teams API and this app's own historical data occasionally
     * disagree on straight vs. "smart" punctuation for the same team name
     * (e.g. "Women's" vs "Women's"). Normalizing to plain ASCII punctuation
     * on write means a plain string comparison at read time still matches.
     */
    public static function normalizeTeamName(string $teamName): string
    {
        return str_replace(['’', '‘', '´', '`'], "'", $teamName);
    }
}
