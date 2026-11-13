<?php
declare(strict_types=1);

use App\Services\CommentTree;
use PHPUnit\Framework\TestCase;

final class CommentTreeTest extends TestCase
{
    /** The seven comments from the lab handout (section 4.1). */
    private function labRows(): array
    {
        return [
            ['id' => 1, 'parent_id' => null, 'author' => 'Alice'],
            ['id' => 2, 'parent_id' => 1, 'author' => 'Ben'],
            ['id' => 3, 'parent_id' => 2, 'author' => 'Cara'],
            ['id' => 4, 'parent_id' => 1, 'author' => 'Dee'],
            ['id' => 5, 'parent_id' => null, 'author' => 'Eli'],
            ['id' => 6, 'parent_id' => 3, 'author' => 'Ben'],
            ['id' => 7, 'parent_id' => 6, 'author' => 'Eli'],
        ];
    }

    public function testLabExampleOrderAndDepths(): void
    {
        $result = (new CommentTree())->flatten($this->labRows());

        $order = array_map(static fn ($i) => [$i['id'], $i['depth']], $result['items']);
        // Expected output from section 2 of the handout.
        self::assertSame([[1, 0], [2, 1], [3, 2], [6, 3], [7, 4], [4, 1], [5, 0]], $order);
        self::assertSame(4, $result['height']);        // displayed height (graph height is 5)
        self::assertSame(0, $result['hidden']);
        self::assertFalse($result['truncated']);
    }

    public function testExtraFieldsArePassedThrough(): void
    {
        $result = (new CommentTree())->flatten($this->labRows());
        self::assertSame('Cara', $result['items'][2]['author']);
    }

    public function testIndentIsCappedAtEightButDepthLabelStaysAccurate(): void
    {
        $rows = [['id' => 1, 'parent_id' => null]];
        for ($i = 2; $i <= 12; $i++) {
            $rows[] = ['id' => $i, 'parent_id' => $i - 1];
        }
        $items = (new CommentTree())->flatten($rows)['items'];

        self::assertSame(8, $items[8]['depth']);
        self::assertSame(8, $items[8]['indent']);
        self::assertFalse($items[8]['indent_capped']);
        self::assertSame(11, $items[11]['depth']);
        self::assertSame(8, $items[11]['indent']);
        self::assertTrue($items[11]['indent_capped']);
    }

    public function testCycleIsNeverTraversed(): void
    {
        // 2 -> 3 -> 2 is a cycle that is not connected to the root.
        $rows = [
            ['id' => 1, 'parent_id' => null],
            ['id' => 2, 'parent_id' => 3],
            ['id' => 3, 'parent_id' => 2],
            ['id' => 4, 'parent_id' => 4], // self-parent
        ];
        $result = (new CommentTree())->flatten($rows);
        self::assertSame([1], array_column($result['items'], 'id'));
        self::assertSame(3, $result['hidden']);
    }

    public function testDepthLimitStopsTraversal(): void
    {
        $rows = [['id' => 1, 'parent_id' => null]];
        for ($i = 2; $i <= 10; $i++) {
            $rows[] = ['id' => $i, 'parent_id' => $i - 1];
        }
        $result = (new CommentTree(maxDepth: 3))->flatten($rows);
        self::assertSame([1, 2, 3, 4], array_column($result['items'], 'id'));
        self::assertTrue($result['truncated']);
        self::assertSame(6, $result['hidden']);
    }

    public function testNodeLimitStopsTraversal(): void
    {
        $rows = [];
        for ($i = 1; $i <= 20; $i++) {
            $rows[] = ['id' => $i, 'parent_id' => null];
        }
        $result = (new CommentTree(maxNodes: 5))->flatten($rows);
        self::assertCount(5, $result['items']);
        self::assertTrue($result['truncated']);
    }

    public function testSiblingsKeepInputOrder(): void
    {
        $rows = [
            ['id' => 1, 'parent_id' => null],
            ['id' => 9, 'parent_id' => 1],
            ['id' => 3, 'parent_id' => 1],
        ];
        self::assertSame([1, 9, 3], array_column((new CommentTree())->flatten($rows)['items'], 'id'));
    }

    public function testDepthMapForValidation(): void
    {
        $map = (new CommentTree())->depthMap($this->labRows());
        self::assertSame(4, $map[7]);
        self::assertSame(0, $map[5]);
        self::assertArrayNotHasKey(99, $map);
    }

    public function testEmptyDiscussion(): void
    {
        $result = (new CommentTree())->flatten([]);
        self::assertSame([], $result['items']);
        self::assertSame(-1, $result['height']);
    }
}
