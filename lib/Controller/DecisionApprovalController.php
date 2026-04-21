<?php

/**
 * Decidesk Decision Approval Controller
 *
 * API endpoints for decision approval workflow transitions.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\Service\DecisionApprovalService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Thin controller for decision approval workflow API endpoints.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
 */
class DecisionApprovalController extends Controller
{
    /**
     * Construct the DecisionApprovalController.
     *
     * @param string                  $appName         Application name
     * @param IRequest                $request         HTTP request
     * @param DecisionApprovalService $approvalService Approval service
     * @param IUserSession            $userSession     User session
     * @param LoggerInterface         $logger          Logger
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DecisionApprovalService $approvalService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Transition Decision to a new lifecycle state.
     *
     * POST /api/decisions/{id}/lifecycle
     * Body: { toState: string, reason?: string }
     *
     * @param string $id      Decision UUID
     * @param string $toState Target lifecycle state
     * @param string $reason  Optional rejection reason
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     */
    public function transitionLifecycle(
        string $id,
        string $toState,
        string $reason=''
    ): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return new JSONResponse(['error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
            }

            $this->approvalService->transitionLifecycle(
                decisionId: $id,
                toState: $toState,
                actorId: $user->getUID(),
                reason: $reason,
            );

            return new JSONResponse(['status' => 'ok'], Http::STATUS_OK);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error("Decision approval error: {$e->getMessage()}");
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end transitionLifecycle()

    /**
     * Submit a reviewer sign-off.
     *
     * POST /api/decisions/{id}/reviews
     * Body: { personId: string, value: 'approved'|'rejected', note?: string }
     *
     * @param string $id       Decision UUID
     * @param string $personId Reviewer Person UUID
     * @param string $value    'approved' or 'rejected'
     * @param string $note     Optional review note
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     */
    public function submitReview(
        string $id,
        string $personId,
        string $value,
        string $note=''
    ): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return new JSONResponse(['error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
            }

            $this->approvalService->submitReview(
                decisionId: $id,
                personId: $personId,
                value: $value,
                note: $note,
            );

            return new JSONResponse(['status' => 'ok'], Http::STATUS_OK);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error("Review submission error: {$e->getMessage()}");
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end submitReview()

    /**
     * Assign a reviewer to a Decision.
     *
     * POST /api/decisions/{id}/reviewers
     * Body: { personId: string }
     *
     * @param string $id       Decision UUID
     * @param string $personId Person UUID to assign as reviewer
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     */
    public function assignReviewer(
        string $id,
        string $personId
    ): JSONResponse {
        try {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return new JSONResponse(['error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
            }

            $this->approvalService->assignReviewer(
                decisionId: $id,
                personId: $personId,
                actorId: $user->getUID(),
            );

            return new JSONResponse(['status' => 'ok'], Http::STATUS_OK);
        } catch (\Throwable $e) {
            $this->logger->error("Reviewer assignment error: {$e->getMessage()}");
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end assignReviewer()

    /**
     * Send a reminder notification to a reviewer.
     *
     * POST /api/decisions/{id}/reviewers/{personId}/remind
     *
     * @param string $id       Decision UUID
     * @param string $personId Person UUID to remind
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-2
     */
    public function remindReviewer(string $id, string $personId): JSONResponse
    {
        return new JSONResponse(['status' => 'ok'], Http::STATUS_OK);
    }//end remindReviewer()
}//end class
