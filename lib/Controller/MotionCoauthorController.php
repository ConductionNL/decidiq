<?php
/**
 * Decidesk Motion Coauthor Controller
 *
 * REST controller for motion co-authoring (add/remove coauthor, update text
 * with version capture, get history).
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
 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\MotionCoauthorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for motion co-authoring endpoints.
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
 */
class MotionCoauthorController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest              $request         HTTP request
     * @param MotionCoauthorService $coauthorService Co-author service
     * @param IUserSession          $userSession     Current user session
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
     */
    public function __construct(
        IRequest $request,
        private readonly MotionCoauthorService $coauthorService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Add a coauthor to a motion.
     *
     * POST /api/motions/{id}/coauthors
     *
     * Body: { personId }
     *
     * @param string $id Motion UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
     */
    public function addCoauthor(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $personId = (string) $this->request->getParam('personId', '');
        if ($personId === '') {
            return new JSONResponse(
                ['message' => 'Missing required field: personId.'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $motion = $this->coauthorService->addCoauthor($id, $personId);
            return new JSONResponse($motion);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end addCoauthor()

    /**
     * Remove a coauthor from a motion.
     *
     * DELETE /api/motions/{id}/coauthors/{personId}
     *
     * @param string $id       Motion UUID
     * @param string $personId Person UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
     */
    public function removeCoauthor(string $id, string $personId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $motion = $this->coauthorService->removeCoauthor($id, $personId);
            return new JSONResponse($motion);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end removeCoauthor()

    /**
     * Update motion text with version capture and conflict detection.
     *
     * POST /api/motions/{id}/text
     *
     * Body: { text, summary }
     *
     * @param string $id Motion UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
     */
    public function updateText(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $text    = (string) $this->request->getParam('text', '');
        $summary = (string) $this->request->getParam('summary', '');

        if ($text === '') {
            return new JSONResponse(
                ['message' => 'Missing required field: text.'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $motion = $this->coauthorService->updateMotionText(
                motionId: $id,
                newText: $text,
                author: $user->getUID(),
                changeSummary: $summary,
            );
            return new JSONResponse($motion);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
        }

    }//end updateText()

    /**
     * Get the version history of a motion.
     *
     * GET /api/motions/{id}/history
     *
     * @param string $id Motion UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.3
     */
    public function history(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $history = $this->coauthorService->getHistory($id);
            return new JSONResponse(['history' => $history]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end history()
}//end class
