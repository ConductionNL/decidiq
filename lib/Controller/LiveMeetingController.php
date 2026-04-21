<?php

/**
 * Decidesk Live Meeting Controller
 *
 * Controller for live meeting operations such as recording decisions during
 * an active meeting via the live decision panel.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Exception\MissingObjectException;
use OCA\Decidesk\Service\LiveDecisionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for live meeting operations.
 *
 * Provides the endpoint for recording decisions during an active meeting.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2
 */
class LiveMeetingController extends Controller
{
    /**
     * Constructor for LiveMeetingController.
     *
     * @param IRequest            $request             The HTTP request
     * @param LiveDecisionService $liveDecisionService The live decision service
     * @param IUserSession        $userSession         The current user session
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.2
     */
    public function __construct(
        IRequest $request,
        private LiveDecisionService $liveDecisionService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Record a decision during an active meeting.
     *
     * POST /api/meetings/{meetingId}/live-decisions
     *
     * Body: { "title": string, "text": string, "outcome": string, "legalBasis"?: string }
     *
     * Returns 200 with the created Decision object on success.
     * Returns 400 when required fields are missing.
     * Returns 401 when not authenticated.
     * Returns 404 when the Meeting is not found.
     * Returns 409 when the Meeting is not in 'opened' state.
     *
     * @param string $meetingId The Meeting ID
     *
     * @return JSONResponse The created Decision object
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.2
     */
    public function recordLiveDecision(string $meetingId): JSONResponse
    {
        try {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return new JSONResponse(['error' => 'Not authenticated'], 401);
            }

            $title   = $this->request->getParam('title');
            $text    = $this->request->getParam('text');
            $outcome = $this->request->getParam('outcome');

            if (empty($title) === true || empty($text) === true || empty($outcome) === true) {
                return new JSONResponse(
                    ['error' => 'Missing required fields: title, text, outcome'],
                    400
                );
            }

            $decisionData = [
                'title'      => $title,
                'text'       => $text,
                'outcome'    => $outcome,
                'legalBasis' => $this->request->getParam('legalBasis'),
            ];

            $decisionSlug = $this->liveDecisionService->recordDecision(
                $meetingId,
                $decisionData,
                $user->getUID()
            );

            return new JSONResponse(
                    [
                        'slug'    => $decisionSlug,
                        'message' => 'Decision recorded successfully',
                    ]
                    );
        } catch (MissingObjectException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            if ((int) $e->getCode() === 409) {
                return new JSONResponse(['error' => $e->getMessage()], 409);
            }

            return new JSONResponse(['error' => 'Internal server error: '.$e->getMessage()], 500);
        }//end try
    }//end recordLiveDecision()
}//end class
