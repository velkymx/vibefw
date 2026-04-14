<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use DateTimeInterface;
use Fw\Auth\Contracts\RevocableTokenInterface;
use Fw\Domain\UserId;
use Fw\Model\BelongsTo;
use Fw\Model\Collection;
use Fw\Model\Model;

class PersonalAccessToken extends Model implements RevocableTokenInterface
{
    protected static ?string $table = 'personal_access_tokens';

    protected static bool $incrementing = false;

    protected static string $keyType = 'string';

    protected static array $fillable = [
        'user_id',
        'name',
        'token',
        'abilities',
        'expires_at',
        'last_used_at',
    ];

    protected static array $hidden = ['token'];

    protected static array $casts = [
        'user_id' => UserId::class,
        'abilities' => 'json',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public static function findToken(string $hashedToken): ?self
    {
        return static::where('token', $hashedToken)->first()->unwrapOr(null);
    }

    public static function forUser(string|UserId $userId): Collection
    {
        return static::where('user_id', (string) $userId)->get();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function can(string $ability): bool
    {
        $abilities = $this->getAttribute('abilities') ?? [];
        if (empty($abilities)) {
            return false;
        }

        if (in_array('*', $abilities, true)) {
            return true;
        }

        if (in_array($ability, $abilities, true)) {
            return true;
        }

        if (str_contains($ability, ':')) {
            [$parent, $action] = explode(':', $ability, 2);

            if (in_array($parent, $abilities, true)) {
                return true;
            }

            if (in_array($action, $abilities, true)) {
                return true;
            }
        }

        return false;
    }

    public function cannot(string $ability): bool
    {
        return !$this->can($ability);
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->getAttribute('expires_at');
        if ($expiresAt === null) {
            return false;
        }
        if ($expiresAt instanceof DateTimeInterface) {
            return $expiresAt->getTimestamp() < time();
        }
        return strtotime((string) $expiresAt) < time();
    }

    public function isValid(): bool
    {
        return !$this->isExpired();
    }

    public function revoke(): bool
    {
        return $this->delete();
    }

    public function touchLastUsed(): void
    {
        $this->setAttribute('last_used_at', new DateTimeImmutable());
        (void) $this->save();
    }
}
