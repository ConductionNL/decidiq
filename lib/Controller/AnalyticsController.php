<?php

/**
 * Decidesk Analytics Controller
 *
 * Controller for personal action-item list.  Generic dashboard metrics
 * (completion rates, overdue counts) have been migrated to the analytics
 * integration leaf via x-openregister-aggregations on the Meeting schema
 * (ADR-031, ADR-019).
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @spec openspec/changes/migrate-engagement-analytics-to-analytics-leaf/tasks.md#task-3.2
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
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for the personal action-item list endpoint.
 *
 * The getSummary and getCompletionRates endpoints that previously served
 * in-app chart components have been removed: their aggregations now live
 * in x-openregister-aggregations on the Meeting schema and are rendered by
 * the analytics integration leaf (ADR-019, ADR-031).
 *
 * @spec openspec/changes/migrate-engagement-analytics-to-analytics-leaf/tasks.md#task-3.2
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
     * @spec openspec/changes/migrate-engagement-analytics-to-analytics-leaf/tasks.md#task-3.2
     */
    public function __construct(
        IRequest $request,
        private ActionItemAnalyticsService $analyticsService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

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

        // Use the Nextcloud UID (unique per user), not the display name which is
        // user-settable and non-unique — display name spoofing would leak other
        // users' action items (PII + workflow data).
        $nextcloudUid = $user->getUID();
        $items        = $this->analyticsService->getMyItems($nextcloudUid);

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
