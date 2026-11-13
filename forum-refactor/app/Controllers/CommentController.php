<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Discussion;
use App\Services\CommentService;
use App\Services\Session;
use App\Services\ValidationException;
use Illuminate\Database\QueryException;
use Mustache_Engine;
use Psr\Log\LoggerInterface;

final class CommentController extends Controller
{
    public function __construct(Mustache_Engine $views, LoggerInterface $logger, private readonly CommentService $service)
    {
        parent::__construct($views, $logger);
    }

    /**
     * POST /discussions/{id}/comments
     * Body: csrf, body, parent_id (empty for a top-level comment).
     * Order follows the lab's activity diagram: logged in? -> token & input valid? -> save -> redirect.
     */
    public function store(string $rawId): void
    {
        // 1. Authentication is checked on the server for every write.
        $userId = Session::userId();
        if ($userId === null) {
            $this->logger->notice('comment.rejected_guest');
            Session::flash('error', 'Log in to post a comment.');
            $this->redirect('/login');
        }

        // 2. CSRF token.
        if (!Session::checkCsrf($_POST['csrf'] ?? null)) {
            $this->invalidToken();
        }

        // 3. Target discussion.
        $id = $this->parseId($rawId) ?? $this->notFound();
        $discussion = Discussion::find($id) ?? $this->notFound();

        // 4. Validate and save with the session user ID.
        try {
            $comment = $this->service->create($discussion, $userId, $_POST['body'] ?? null, $_POST['parent_id'] ?? null);
        } catch (ValidationException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/discussions/' . $id . '#post-comment');
        } catch (QueryException $e) {
            // e.g. the composite FK rejected a parent from another discussion.
            $this->logger->warning('comment.db_rejected', ['discussion_id' => $id, 'user_id' => $userId, 'code' => $e->errorInfo[1] ?? null]);
            Session::flash('error', 'Your comment could not be saved.');
            $this->redirect('/discussions/' . $id . '#post-comment');
        }

        $this->logger->info('comment.created', [
            'comment_id' => $comment->id,
            'discussion_id' => $id,
            'parent_id' => $comment->parent_id,
            'user_id' => $userId,
        ]);
        Session::flash('success', 'Your comment was posted.');

        // 5. Post/Redirect/Get.
        $this->redirect('/discussions/' . $id . '#comment-' . $comment->id);
    }
}
