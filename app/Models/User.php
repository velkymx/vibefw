<?php

declare(strict_types=1);

namespace App\Models;

use Fw\Auth\ApiToken;
use Fw\Model\Model;
use Fw\Support\Option;

class User extends Model
{
    protected static ?string $table = 'users';

    protected static array $fillable = ['name', 'email', 'password', 'email_verified_at'];

    protected static array $hidden = ['password', 'remember_token'];

    protected static array $casts = [
        'email_verified_at' => 'datetime',
    ];

    public static function findByEmail(string $email): Option
    {
        return static::where('email', '=', $email)->first();
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function markEmailAsVerified(): void
    {
        $this->fill(['email_verified_at' => date('Y-m-d H:i:s')])->save()->match(ok: fn () => null, err: fn ($e) => throw $e);
    }

    public function createToken(string $name, array $abilities = ['*']): \Fw\Auth\NewAccessToken
    {
        return ApiToken::create($this, $name, $abilities);
    }

    public function verifyPassword(string $plainPassword): bool
    {
        return password_verify($plainPassword, (string) $this->password);
    }
}
