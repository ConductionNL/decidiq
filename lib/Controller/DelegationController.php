<?php
/**
 * Decidesk Delegation Controller
 *
 * REST controller for Delegation lifecycle endpoints (revoke/expire).
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
 * @spec openspec/changes/p4-collaboration/tasks.md#task-2.4
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\DelegationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for Delegation revocation and expiry.
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-2.4
 */
class DelegationController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request           HTTP request
     * @param DelegationService $delegationService Delegation service
     * @param IUserSession      $userSession       Current user session
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-2.4
     */
    public function __construct(
        IRequest $request,
        private readonly DelegationService $delegationService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Revoke a delegation.
     *
     * DELETE /api/delegations/{id}
     *
     * @param string $id Delegation UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-2.4
     */
    public function revoke(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $delegation = $this->delegationService->revokeDelegation($id, $user->getUID());
            return new JSONResponse($delegation);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end revoke()

    /**
     * Expire a delegation manually (normally driven by a background job).
     *
     * POST /api/delegations/{id}/expire
     *
     * @param string $id Delegation UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-2.4
     */
    public function expire(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $delegation = $this->delegationService->expireDelegation($id, $user->getUID());
            return new JSONResponse($delegation);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

    }//end expire()
}//end class
