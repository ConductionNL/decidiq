<?php

/**
 * Decidesk Analytics Controller
 *
 * Controller for analytics-related operations such as action item metrics,
 * completion rates, and personal task lists.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
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
use OCA\Decidesk\Service\ActionItemAnalyticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use DateTime;

/**
 * Controller for analytics endpoints.
 *
 * Provides three thin endpoints that call ActionItemAnalyticsService and return
 * summary metrics, per-meeting completion rates, and personal action item lists.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
 */
class AnalyticsController extends Controller
{
    /**
     * Constructor for AnalyticsController.
     *
     * @param IRequest                   $request          The HTTP request
     * @param ActionItemAnalyticsService $analyticsService The analytics service
     * @param IUserSession               $userSession      The current user session
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.2
     */
    public function __construct(
        IRequest $request,
        private ActionItemAnalyticsService $analyticsService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get a summary of action item metrics for a date range.
     *
     * GET /api/analytics/action-items?dateFrom=2026-01-01&dateTo=2026-12-31
     *
     * Returns { "totalOpen": int, "totalOverdue": int, "completedThisMonth": int, "avgDaysToClose": float }
     *
     * @return JSONResponse The summary metrics
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.2
     */
    #[NoAdminRequired]
    public function getSummary(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // Default to current calendar year.
        $now      = new DateTime();
        $year     = (int) $now->format('Y');
        $dateFrom = $this->request->getParam('dateFrom', "$year-01-01");
        $dateTo   = $this->request->getParam('dateTo', "$year-12-31");

        $summary = $this->analyticsService->getSummary($dateFrom, $dateTo);

        return new JSONResponse($summary);
    }//end getSummary()

    /**
     * Get per-meeting completion rates.
     *
     * GET /api/analytics/action-items/completion-rates
     *
     * Returns [ { "meetingTitle": string, "completionRate": float, "total": int }, ... ]
     *
     * @return JSONResponse Array of completion rate objects
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.2
     */
    #[NoAdminRequired]
    public function getCompletionRates(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $limit = (int) $this->request->getParam('limit', 6);
        $rates = $this->analyticsService->getCompletionRates($limit);

        return new JSONResponse($rates);
    }//end getCompletionRates()

    /**
     * Get action items assigned to the current user.
     *
     * GET /api/analytics/action-items/my-items
     *
     * Returns { "overdue": [...], "thisWeek": [...], "later": [...] }
     *
     * @return JSONResponse Grouped action items
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.2
     */
    #[NoAdminRequired]
    public function getMyItems(): JSONResponse
    {
        $user = $this->requireAuthenticatedUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $userDisplayName = $user->getDisplayName();
        $items           = $this->analyticsService->getMyItems($userDisplayName);

        return new JSONResponse($items);
    }//end getMyItems()

    /**
     * Ensure a user is authenticated; returns the current user or null.
     *
     * @return \OCP\IUser|null The authenticated user, or null
     */
    private function requireAuthenticatedUser(): ?\OCP\IUser
    {
        return $this->userSession->getUser();
    }//end requireAuthenticatedUser()
}//end class
