<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $username
 * @property string $email
 * @property string|null $password_hash
 * @property string|null $avatar
 */
final class User extends Model
{
    public const UPDATED_AT = null;
    public const DEFAULT_AVATAR = '/avatar.svg';

    protected $table = 'users';
    protected $fillable = ['username', 'email', 'password_hash'];
    protected $hidden = ['password_hash'];
    protected $casts = ['settings' => 'array'];

    public static function findByEmail(string $email): ?self
    {
        return self::query()->where('email', $email)->first();
    }

    public static function usernameOrEmailTaken(string $username, string $email): bool
    {
        return self::query()->where('username', $username)->orWhere('email', $email)->exists();
    }

    /** A NULL (or empty) hash can never authenticate. */
    public function checkPassword(string $password): bool
    {
        $hash = $this->password_hash;
        return is_string($hash) && $hash !== '' && password_verify($password, $hash);
    }

    public function avatarUrl(): string
    {
        return $this->avatar ?: self::DEFAULT_AVATAR;
    }

    public function discussions(): HasMany
    {
        return $this->hasMany(Discussion::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'user_id');
    }
}
