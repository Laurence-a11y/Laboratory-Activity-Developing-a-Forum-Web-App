<?php
declare(strict_types=1);

use App\Services\CommentService;
use App\Services\CommentTree;
use App\Services\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Input rules that do not need a database. Database rules are covered by tests/http-checks.php. */
final class CommentServiceTest extends TestCase
{
    private CommentService $service;

    protected function setUp(): void
    {
        $this->service = new CommentService(new CommentTree());
    }

    public function testBodyIsTrimmedAndNewlinesNormalised(): void
    {
        self::assertSame("Line 1\nLine 2", $this->service->cleanBody("  Line 1\r\nLine 2 \n"));
    }

    public static function badBodies(): array
    {
        return [
            'empty' => [''],
            'spaces' => ["   \n\t"],
            'array' => [['x']],
            'null' => [null],
            'too long' => [str_repeat('a', CommentService::MAX_LENGTH + 1)],
            'invalid UTF-8' => ["\xC3\x28"],
        ];
    }

    #[DataProvider('badBodies')]
    public function testBadBodiesAreRejected(mixed $body): void
    {
        $this->expectException(ValidationException::class);
        $this->service->cleanBody($body);
    }

    public function testMaxLengthIsCountedInCharacters(): void
    {
        $text = str_repeat('é', CommentService::MAX_LENGTH); // 2 bytes each
        self::assertSame($text, $this->service->cleanBody($text));
    }

    public function testParentIdParsing(): void
    {
        self::assertNull($this->service->parseParentId(''));
        self::assertNull($this->service->parseParentId(null));
        self::assertSame(7, $this->service->parseParentId('7'));
    }

    public static function badParents(): array
    {
        return [['0'], ['-1'], ['07'], ['1.5'], ['abc'], ['99999999999'], [['1']]];
    }

    #[DataProvider('badParents')]
    public function testBadParentIdsAreRejected(mixed $value): void
    {
        $this->expectException(ValidationException::class);
        $this->service->parseParentId($value);
    }
}
