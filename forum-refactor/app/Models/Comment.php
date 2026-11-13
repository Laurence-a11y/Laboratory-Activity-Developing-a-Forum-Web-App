<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $discussion_id
 * @property int $user_id
 * @property int|null $parent_id
 * @property string $body
 * @property \Carbon\Carbon $created_at
 */
final class Comment extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'comments';
    // parent_id is set once on insert; there is no update route, so links are immutable.
    protected $fillable = ['discussion_id', 'user_id', 'parent_id', 'body'];
    protected $casts = ['parent_id' => 'integer', 'discussion_id' => 'integer', 'user_id' => 'integer'];

    /**
     * All comments of one discussion with their authors, in one bound query
     * (plus one for authors). Siblings come out oldest first.
     *
     * @return Collection<int, Comment>
     */
    public static function forDiscussion(int $discussionId, int $limit): Collection
    {
        return self::query()->with('author')
            ->where('discussion_id', $discussionId)
            ->orderBy('created_at')->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Only the tree columns, used to validate a new reply.
     *
     * @return list<array{id:int, parent_id:int|null}>
     */
    public static function treeLinks(int $discussionId, int $limit): array
    {
        return self::query()->where('discussion_id', $discussionId)
            ->orderBy('created_at')->orderBy('id')->limit($limit)
            ->get(['id', 'parent_id'])
            ->map(static fn (Comment $c): array => ['id' => (int) $c->id, 'parent_id' => $c->parent_id])
            ->all();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class, 'discussion_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
