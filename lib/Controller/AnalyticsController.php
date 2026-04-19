<?php

/**
 * Decidesk Analytics Controller
 *
 * Controller for action item analytics endpoints.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\ActionItemAnalyticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for action item analytics.
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
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
     */
    public function __construct(
        IRequest $request,
        private ActionItemAnalyticsService $analyticsService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get action item summary for a date range.
     *
     * GET /api/analytics/action-items
     *
     * Query parameters:
     * - dateFrom: Start date (ISO 8601 format, default: start of current year)
     * - dateTo: End date (ISO 8601 format, default: today)
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
     */
    public function summary(): JSONResponse
    {
        $dateFrom = $this->request->getParam('dateFrom') ?? '';
        $dateTo   = $this->request->getParam('dateTo') ?? '';

        $summary = $this->analyticsService->getSummary($dateFrom, $dateTo);

        return new JSONResponse($summary);
    }//end summary()

    /**
     * Get completion rates for recent meetings.
     *
     * GET /api/analytics/action-items/completion-rates
     *
     * Query parameters:
     * - limit: Number of meetings to fetch (default: 6)
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
     */
    public function completionRates(): JSONResponse
    {
        $limit = (int) ($this->request->getParam('limit') ?? 6);
        $limit = max(1, min(50, $limit));
        // Clamp between 1 and 50.
        $rates = $this->analyticsService->getCompletionRates($limit);

        return new JSONResponse(['results' => $rates]);
    }//end completionRates()

    /**
     * Get the current user's action items.
     *
     * GET /api/analytics/action-items/my-items
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
     */
    public function myItems(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['overdue' => [], 'thisWeek' => [], 'later' => []]);
        }

        $displayName = $user->getDisplayName() ?? $user->getUID();
        $items       = $this->analyticsService->getMyItems($displayName);

        return new JSONResponse($items);
    }//end myItems()
}//end class
