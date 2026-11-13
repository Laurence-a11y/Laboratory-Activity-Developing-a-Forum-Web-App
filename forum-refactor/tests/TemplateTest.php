<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TemplateTest extends TestCase
{
    private function engine(): Mustache_Engine
    {
        $loader = new Mustache_Loader_FilesystemLoader(dirname(__DIR__) . '/app/Views');
        return new Mustache_Engine([
            'loader' => $loader,
            'partials_loader' => $loader,
            'escape' => static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ]);
    }

    private function comment(array $overrides = []): array
    {
        return $overrides + [
            'id' => 1, 'parent_id' => null, 'author' => 'Alice', 'avatar' => '/avatar.svg',
            'body' => 'Hello', 'created_iso' => '2026-11-01T08:10:00Z', 'created_utc' => '2026-11-01 08:10 UTC',
            'parent_author' => null, 'depth' => 0, 'indent' => 0, 'indent_capped' => false, 'can_reply' => true,
        ];
    }

    private function render(array $comments, bool $loggedIn = false): string
    {
        return $this->engine()->render('discussion', [
            'csrf' => 'token123', 'logged_in' => $loggedIn, 'username' => 'tester',
            'discussion' => ['id' => 1, 'title' => '<b>Title</b>', 'body' => 'Body', 'author' => 'Alice',
                'avatar' => '/avatar.svg', 'created_iso' => '', 'created_utc' => ''],
            'comments' => $comments, 'comment_count' => count($comments), 'max_length' => 5000,
        ]);
    }

    public function testCommentContentIsEscaped(): void
    {
        $html = $this->render([$this->comment(['body' => '<script>alert("x")</script>', 'author' => '<img src=x onerror=1>'])]);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;b&gt;Title&lt;/b&gt;', $html);
    }

    public function testDeepCommentShowsRealDepthWithCappedIndent(): void
    {
        $html = $this->render([$this->comment(['id' => 12, 'depth' => 11, 'indent' => 8, 'indent_capped' => true])]);
        self::assertStringContainsString('class="comment indent-8" data-depth="11"', $html);
        self::assertStringContainsString('Depth 11', $html);
        self::assertStringContainsString('indent capped', $html);
    }

    public function testGuestsSeeNoReplyForms(): void
    {
        $html = $this->render([$this->comment()], loggedIn: false);
        self::assertStringNotContainsString('name="parent_id"', $html);
        self::assertStringContainsString('Log in</a> or', $html);
    }

    public function testMembersGetReplyFormsWithCsrfAndParent(): void
    {
        $html = $this->render([$this->comment(['id' => 7])], loggedIn: true);
        self::assertStringContainsString('action="/discussions/1/comments"', $html);
        self::assertStringContainsString('name="parent_id" value="7"', $html);
        self::assertStringContainsString('name="csrf" value="token123"', $html);
        self::assertStringNotContainsString('name="user_id"', $html); // author comes from the session
    }

    public function testTemplatesNeverUseUnescapedOutput(): void
    {
        foreach (glob(dirname(__DIR__) . '/app/Views/*.mustache') as $file) {
            $source = file_get_contents($file);
            self::assertStringNotContainsString('{{{', $source, basename($file));
            self::assertDoesNotMatchRegularExpression('/\{\{&/', $source, basename($file));
        }
    }
}
