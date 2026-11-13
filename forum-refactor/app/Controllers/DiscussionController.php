<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Comment;
use App\Models\Discussion;
use App\Services\CommentService;
use App\Services\CommentTree;
use Mustache_Engine;
use Psr\Log\LoggerInterface;

final class DiscussionController extends Controller
{
    public function __construct(Mustache_Engine $views, LoggerInterface $logger, private readonly CommentTree $tree)
    {
        parent::__construct($views, $logger);
    }

    /** GET / */
    public function index(): void
    {
        $discussions = Discussion::latestWithAuthors()->map(static fn (Discussion $d): array => [
            'id' => $d->id,
            'title' => $d->title,
            'author' => $d->author?->username ?? 'Unknown',
            'comment_count' => (int) $d->comments_count,
            'created_iso' => $d->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'created_utc' => $d->created_at->utc()->format('Y-m-d H:i') . ' UTC',
        ])->all();

        $this->render('home', ['discussions' => $discussions]);
    }

    /** GET /discussions/{id} */
    public function show(string $rawId): void
    {
        $id = $this->parseId($rawId) ?? $this->notFound();
        $discussion = Discussion::findWithAuthor($id) ?? $this->notFound();

        // Load one row more than the limit so we can tell the reader some were hidden.
        $comments = Comment::forDiscussion($id, $this->tree->maxNodes() + 1);
        $authorById = [];
        foreach ($comments as $c) {
            $authorById[(int) $c->id] = $c->author?->username ?? 'Unknown';
        }

        $rows = $comments->map(static fn (Comment $c): array => [
            'id' => (int) $c->id,
            'parent_id' => $c->parent_id,
            'author' => $c->author?->username ?? 'Unknown',
            'avatar' => $c->author?->avatarUrl() ?? \App\Models\User::DEFAULT_AVATAR,
            'body' => $c->body,
            'created_iso' => $c->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'created_utc' => $c->created_at->utc()->format('Y-m-d H:i') . ' UTC',
            'parent_author' => $c->parent_id !== null ? ($authorById[$c->parent_id] ?? null) : null,
        ])->all();

        $tree = $this->tree->flatten($rows);
        $items = array_map(fn (array $item): array => $item + [
            'can_reply' => $item['depth'] < $this->tree->maxDepth(),
        ], $tree['items']);

        $this->render('discussion', [
            'discussion' => [
                'id' => $discussion->id,
                'title' => $discussion->title,
                'body' => $discussion->body,
                'author' => $discussion->author?->username ?? 'Unknown',
                'avatar' => $discussion->author?->avatarUrl() ?? \App\Models\User::DEFAULT_AVATAR,
                'created_iso' => $discussion->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'created_utc' => $discussion->created_at->utc()->format('Y-m-d H:i') . ' UTC',
            ],
            'comments' => $items,
            'comment_count' => count($items),
            'tree_height' => $tree['height'],
            'some_hidden' => $tree['hidden'] > 0 || $tree['truncated'],
            'max_length' => CommentService::MAX_LENGTH,
        ]);
    }
}
