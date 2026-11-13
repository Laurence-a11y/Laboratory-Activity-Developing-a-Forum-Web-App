<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Comment;
use App\Models\Discussion;

/**
 * Write rules for comments. The controller has already checked login and CSRF.
 *
 * Tree invariants enforced here:
 *  - a non-null parent must be a comment of the same discussion that is
 *    reachable from the root (the composite FK also enforces "same discussion");
 *  - the new reply's displayed depth may not exceed CommentTree::maxDepth();
 *  - parent links are immutable (no update route exists) and a reply can only
 *    point at an existing comment, so a cycle can never be created.
 */
final class CommentService
{
    public const MAX_LENGTH = 5000;

    public function __construct(private readonly CommentTree $tree)
    {
    }

    public function create(Discussion $discussion, int $userId, mixed $body, mixed $parentId): Comment
    {
        $text = $this->cleanBody($body);
        $parent = $this->parseParentId($parentId);

        $links = Comment::treeLinks((int) $discussion->id, $this->tree->maxNodes());
        if (count($links) >= $this->tree->maxNodes()) {
            throw new ValidationException('This discussion has reached its comment limit.');
        }

        if ($parent !== null) {
            $depths = $this->tree->depthMap($links);
            if (!isset($depths[$parent])) {
                throw new ValidationException('The comment you replied to does not belong to this discussion.');
            }
            if ($depths[$parent] + 1 > $this->tree->maxDepth()) {
                throw new ValidationException('This conversation is too deeply nested; reply higher up instead.');
            }
        }

        return Comment::create([
            'discussion_id' => (int) $discussion->id,
            'user_id' => $userId,          // always from the session, never from the form
            'parent_id' => $parent,
            'body' => $text,
        ]);
    }

    public function cleanBody(mixed $body): string
    {
        if (!is_string($body) || !mb_check_encoding($body, 'UTF-8')) {
            throw new ValidationException('Write a comment before posting.');
        }
        $text = trim(str_replace(["\r\n", "\r"], "\n", $body));
        if ($text === '') {
            throw new ValidationException('Write a comment before posting.');
        }
        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw new ValidationException('Comments may be at most ' . self::MAX_LENGTH . ' characters.');
        }
        return $text;
    }

    public function parseParentId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null; // top-level comment
        }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', $value) || (int) $value > 4294967295) {
            throw new ValidationException('The comment you replied to does not exist.');
        }
        return (int) $value;
    }
}
