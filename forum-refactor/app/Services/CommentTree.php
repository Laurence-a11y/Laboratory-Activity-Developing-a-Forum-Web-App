<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Turns flat comment rows (id + parent_id) into a depth-first, pre-flattened
 * list that a logic-less template can print in order.
 *
 * - The discussion is the tree root; rows with parent_id NULL are its children.
 * - Displayed depth starts at 0 for top-level comments and is CALCULATED here,
 *   never stored. (Graph depth from the discussion root = displayed depth + 1.)
 * - Indentation is capped at INDENT_CAP while the depth label stays accurate.
 * - Traversal is bounded: at most $maxNodes items and $maxDepth levels, and a
 *   visited set means corrupt data (e.g. a cycle 2 -> 3 -> 2) can never loop.
 *   Rows in a cycle are unreachable from the root, so they are simply not shown.
 */
final class CommentTree
{
    public const INDENT_CAP = 8;
    public const MAX_DEPTH = 50;     // deepest displayed depth that may exist
    public const MAX_NODES = 1000;   // most comments rendered / stored per discussion

    public function __construct(
        private readonly int $maxDepth = self::MAX_DEPTH,
        private readonly int $maxNodes = self::MAX_NODES,
    ) {
    }

    public function maxDepth(): int
    {
        return $this->maxDepth;
    }

    public function maxNodes(): int
    {
        return $this->maxNodes;
    }

    /**
     * @param list<array{id:int|string, parent_id:int|string|null}&array<string,mixed>> $rows
     *        Rows in sibling order (oldest first). Extra keys are passed through.
     * @return array{items: list<array<string,mixed>>, hidden: int, truncated: bool, height: int}
     *         Each item gains: depth, indent, indent_capped.
     *         height = deepest displayed depth shown (-1 when there are no comments).
     */
    public function flatten(array $rows): array
    {
        // Group children by parent; key 0 stands for the discussion root
        // (AUTO_INCREMENT ids start at 1, so 0 is never a real comment id).
        $children = [];
        foreach ($rows as $row) {
            $parent = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];
            $children[$parent][] = $row;
        }

        $items = [];
        $visited = [];
        $truncated = false;
        $height = -1;

        // Explicit stack instead of recursion: no PHP stack overflow on deep data.
        $stack = [];
        foreach (array_reverse($children[0] ?? []) as $row) {
            $stack[] = [$row, 0];
        }

        while ($stack !== []) {
            [$row, $depth] = array_pop($stack);
            $id = (int) $row['id'];
            if (isset($visited[$id])) {
                continue; // cycle or duplicate guard
            }
            if (count($items) >= $this->maxNodes) {
                $truncated = true;
                break;
            }
            $visited[$id] = true;

            $row['depth'] = $depth;
            $row['indent'] = min($depth, self::INDENT_CAP);
            $row['indent_capped'] = $depth > self::INDENT_CAP;
            $items[] = $row;
            $height = max($height, $depth);

            $kids = $children[$id] ?? [];
            if ($kids === []) {
                continue;
            }
            if ($depth + 1 > $this->maxDepth) {
                $truncated = true; // do not descend past the depth limit
                continue;
            }
            foreach (array_reverse($kids) as $kid) {
                $stack[] = [$kid, $depth + 1];
            }
        }

        return [
            'items' => $items,
            'hidden' => count($rows) - count($items),
            'truncated' => $truncated,
            'height' => $height,
        ];
    }

    /**
     * Displayed depth of every comment reachable from the root.
     *
     * @param list<array{id:int, parent_id:int|null}> $links
     * @return array<int,int> comment id => displayed depth
     */
    public function depthMap(array $links): array
    {
        $map = [];
        foreach ($this->flatten($links)['items'] as $item) {
            $map[(int) $item['id']] = $item['depth'];
        }
        return $map;
    }
}
