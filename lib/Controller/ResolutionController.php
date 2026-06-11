<?php
/**
 * Decidesk Resolution Controller
 *
 * REST endpoints for Resolution lifecycle.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-resolution-controller
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\ResolutionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for the Resolution entity.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-resolution-controller
 */
class ResolutionController extends Controller
{
    use BoardPortalControllerTrait;


    /**
     * Constructor for ResolutionController.
     *
     * @param IRequest          $request           The HTTP request
     * @param ResolutionService $resolutionService The resolution service
     * @param IUserSession      $userSession       The user session
     */
    public function __construct(
        IRequest $request,
        private readonly ResolutionService $resolutionService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()


    /**
     * Propose a new resolution on a meeting.
     *
     * @param string $meetingId UUID of the parent meeting
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-resolution-controller
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function propose(string $meetingId): JSONResponse
    {
        $auth = $this->requireUserOr401($this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        $payload = $this->bodyParams($this->request, ['meetingId', '_route']);
        return $this->respondFromResult(
            $this->resolutionService->propose($meetingId, $payload),
            'resolution',
            Http::STATUS_CREATED
        );

    }//end propose()


    /**
     * Amend a resolution that has not yet entered voting.
     *
     * @param string $id UUID of the resolution
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-resolution-controller
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function amend(string $id): JSONResponse
    {
        $auth = $this->requireUserOr401($this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        $payload = $this->bodyParams($this->request);
        return $this->respondFromResult(
            $this->resolutionService->amend($id, $payload),
            'resolution'
        );

    }//end amend()


    /**
     * Open voting on a resolution (quorum-guarded).
     *
     * @param string $id UUID of the resolution
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-resolution-controller
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function openVote(string $id): JSONResponse
    {
        $auth = $this->requireUserOr401($this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        return $this->respondFromResult($this->resolutionService->openVote($id), 'resolution');

    }//end openVote()


    /**
     * Conclude voting and persist adoption status.
     *
     * @param string $id UUID of the resolution
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase3-resolution-controller
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function conclude(string $id): JSONResponse
    {
        $auth = $this->requireUserOr401($this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        $result = $this->resolutionService->conclude($id);
        if ($result['success'] === false) {
            $status = stripos((string) $result['message'], 'not found') !== false
                ? Http::STATUS_NOT_FOUND
                : Http::STATUS_UNPROCESSABLE_ENTITY;
            return new JSONResponse(['message' => $result['message']], $status);
        }

        return new JSONResponse(
            [
                'resolution' => $result['resolution'],
                'tally'      => $result['tally'],
                'message'    => $result['message'],
            ]
        );

    }//end conclude()


}//end class
