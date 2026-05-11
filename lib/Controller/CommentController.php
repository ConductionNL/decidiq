<?php
/**
 * Decidesk Comment Controller
 *
 * REST controller for governance discussion comments. Generic CRUD is
 * delegated to OpenRegister; this controller adds the target-scoped find
 * endpoint and the resolve-thread action.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-5.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\CommentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for Comment endpoints.
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-5.2
 */
class CommentController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest       $request        HTTP request
     * @param CommentService $commentService Comment service
     * @param IUserSession   $userSession    Current user session
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-5.2
     */
    public function __construct(
        IRequest $request,
        private readonly CommentService $commentService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Create a new comment.
     *
     * POST /api/comments
     *
     * Body: { text, target, author, mentions[], parentComment }
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-5.2
     */
    public function create(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $payload = [
            'text'          => (string) $this->request->getParam('text', ''),
            'target'        => $this->request->getParam('target'),
            'author'        => ($this->request->getParam('author') ?? $user->getUID()),
            'mentions'      => $this->request->getParam('mentions', []),
            'parentComment' => $this->request->getParam('parentComment'),
        ];

        try {
            $comment = $this->commentService->saveComment($payload);
            return new JSONResponse($comment, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }//end try

    }//end create()

    /**
     * Find comments by target reference.
     *
     * GET /api/comments?target={register}:{schema}:{uuid}
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-5.2
     */
    public function index(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $target = (string) $this->request->getParam('target', '');
        if ($target === '') {
            return new JSONResponse(
                ['message' => 'Missing required query parameter: target.'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $comments = $this->commentService->findCommentsForTarget($target);
        return new JSONResponse(['comments' => $comments]);

    }//end index()

    /**
     * Mark a comment thread as resolved.
     *
     * POST /api/comments/{id}/resolve
     *
     * @param string $id Comment UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-5.2
     */
    public function resolve(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $comment = $this->commentService->resolveThread($id);
            return new JSONResponse($comment);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end resolve()
}//end class
