<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string $body
 * @property \Carbon\Carbon $created_at
 */
final class Discussion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'discussions';
    protected $fillable = ['user_id', 'title', 'body'];

    /** @return Collection<int, Discussion> newest first, with authors */
    public static function latestWithAuthors(int $limit = 50): Collection
    {
        return self::query()->with('author')->withCount('comments')
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
    }

    public static function findWithAuthor(int $id): ?self
    {
        return self::query()->with('author')->find($id);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'discussion_id');
    }
}
