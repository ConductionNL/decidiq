<?php
/**
 * Decidesk Notification Preference Controller
 *
 * REST controller for the current user's NotificationPreference object.
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
 * @spec openspec/changes/p4-collaboration/tasks.md#task-7.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\NotificationPreferenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for own NotificationPreference (read/update).
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-7.2
 */
class NotificationPreferenceController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                      $request           HTTP request
     * @param NotificationPreferenceService $preferenceService Preference service
     * @param IUserSession                  $userSession       Current user session
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-7.2
     */
    public function __construct(
        IRequest $request,
        private readonly NotificationPreferenceService $preferenceService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Read own notification preferences.
     *
     * GET /api/notification-preference
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-7.2
     */
    public function show(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $pref = $this->preferenceService->findPreference($user->getUID());
        if ($pref === null) {
            return new JSONResponse(
                    [
                        'person'            => $user->getUID(),
                        'meetingCreated'    => true,
                        'votingOpened'      => true,
                        'decisionPublished' => true,
                        'taskAssigned'      => true,
                        'commentMention'    => true,
                        'deliveryMethod'    => 'in-app',
                    ]
                    );
        }

        return new JSONResponse($pref);

    }//end show()

    /**
     * Update own notification preferences.
     *
     * PUT /api/notification-preference
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-7.2
     */
    public function update(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $changes = [];
        foreach (['meetingCreated', 'votingOpened', 'decisionPublished', 'taskAssigned', 'commentMention'] as $key) {
            $value = $this->request->getParam($key);
            if ($value !== null) {
                $changes[$key] = (bool) $value;
            }
        }

        $deliveryMethod = $this->request->getParam('deliveryMethod');
        if ($deliveryMethod !== null) {
            $allowed = ['in-app', 'email', 'both'];
            if (in_array((string) $deliveryMethod, $allowed, true) === false) {
                return new JSONResponse(
                    ['message' => 'Invalid deliveryMethod. Expected one of: in-app, email, both.'],
                    Http::STATUS_UNPROCESSABLE_ENTITY
                );
            }

            $changes['deliveryMethod'] = (string) $deliveryMethod;
        }

        $pref = $this->preferenceService->updatePreference($user->getUID(), $changes);
        return new JSONResponse($pref);

    }//end update()
}//end class
