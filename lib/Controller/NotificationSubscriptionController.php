<?php

/**
 * Decidesk Notification Subscription Controller
 *
 * Handles API endpoints for notification subscription management.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\Decidesk\Controller
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\DecisionNotificationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Thin controller for notification subscription endpoints.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
 */
class NotificationSubscriptionController extends Controller
{
    /**
     * Constructor for NotificationSubscriptionController.
     *
     * @param IRequest                       $request                    The HTTP request
     * @param DecisionNotificationService    $notificationService        The notification service
     * @param IUserSession                   $userSession                The current user session
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
     */
    public function __construct(
        IRequest $request,
        private DecisionNotificationService $notificationService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get subscription status for the current user.
     *
     * GET /api/notifications/{objectType}/{id}/subscriptions
     *
     * @param string $objectType Type of the object (e.g., 'decision', 'minutes')
     * @param string $id         UUID of the object
     *
     * @return JSONResponse with `{ subscribed: bool, subscribedAt: string|null }`
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
     */
    public function getSubscription(string $objectType, string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['subscribed' => false, 'subscribedAt' => null]);
        }

        $subscribed = $this->notificationService->isSubscribed($id, $user->getUID());
        return new JSONResponse(['subscribed' => $subscribed, 'subscribedAt' => null]);
    }//end getSubscription()

    /**
     * Subscribe the current user to notifications.
     *
     * POST /api/notifications/{objectType}/{id}/subscriptions
     *
     * @param string $objectType Type of the object (e.g., 'decision', 'minutes')
     * @param string $id         UUID of the object
     *
     * @return JSONResponse with `{ subscribed: true }`
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
     */
    public function subscribe(string $objectType, string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated'], 401);
        }

        $this->notificationService->subscribe($id, $objectType, $user->getUID());
        return new JSONResponse(['subscribed' => true]);
    }//end subscribe()

    /**
     * Unsubscribe the current user from notifications.
     *
     * DELETE /api/notifications/{objectType}/{id}/subscriptions
     *
     * @param string $objectType Type of the object (e.g., 'decision', 'minutes')
     * @param string $id         UUID of the object
     *
     * @return JSONResponse with `{ subscribed: false }`
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-1
     */
    public function unsubscribe(string $objectType, string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated'], 401);
        }

        $this->notificationService->unsubscribe($id, $user->getUID());
        return new JSONResponse(['subscribed' => false]);
    }//end unsubscribe()
}//end class
