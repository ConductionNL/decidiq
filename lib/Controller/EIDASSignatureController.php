<?php
/**
 * Decidesk eIDAS Signature Controller
 *
 * Thin REST surface around the IEIDASSignatureService. Endpoints map
 * one-to-one onto the four interface methods so external integrations
 * (kassakoppeling, MFA wrappers, supervisor desks) can drive the signing
 * flow without learning the openconnector source name.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\IEIDASSignatureService;
use OCA\Decidesk\Service\GovernanceScopeGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST controller for the eIDAS QES adapter.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
 */
class EIDASSignatureController extends Controller
{
    use GovernanceControllerTrait;

    /**
     * Constructor.
     *
     * @param IRequest               $request          HTTP request
     * @param IEIDASSignatureService $signatureService eIDAS adapter
     * @param IUserSession           $userSession      User session
     * @param GovernanceScopeGuard   $scopeGuard       Consumes the OR-projected signatory scope (R-4)
     */
    public function __construct(
        IRequest $request,
        private readonly IEIDASSignatureService $signatureService,
        private readonly IUserSession $userSession,
        private readonly GovernanceScopeGuard $scopeGuard,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Initialise a QES signing request for a Minutes record.
     *
     * @param string $minutesId UUID of the minutes record
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function initiate(string $minutesId): JSONResponse
    {
        $auth = $this->requireUserOr401(session: $this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        // R-4 guard: only members of the linked GovernanceBody's OR-projected
        // signatory scope (chair/chairman/vice-chairman/secretary) may initiate
        // a QES signing request. Enforcement consumes the OpenRegister-owned
        // scope (consume-or-rbac-authorization); fail-closed.
        $userId = (string) $this->userSession->getUser()->getUID();
        if ($this->scopeGuard->canInitiateSigning(userId: $userId, minutesId: $minutesId) === false) {
            return new JSONResponse(
                ['message' => 'You are not authorised to initiate a signing request for these minutes.'],
                Http::STATUS_FORBIDDEN
            );
        }

        $signatories = (array) $this->request->getParam('signatories', []);
        $signatories = array_values(array_map('strval', $signatories));

        $result = $this->signatureService->initializeSigningRequest($minutesId, $signatories);
        if (($result['success'] ?? false) === false) {
            return new JSONResponse(
                ['message' => (string) ($result['message'] ?? 'Failed to initiate signing.')],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        return new JSONResponse(
            [
                'requestId'  => $result['requestId'],
                'signingUrl' => $result['signingUrl'],
                'message'    => $result['message'],
            ],
            Http::STATUS_ACCEPTED
        );

    }//end initiate()

    /**
     * Verify a signature blob against the EU Trusted List.
     *
     * @param string $minutesId UUID of the minutes record (forensic context)
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function verify(string $minutesId): JSONResponse
    {
        $auth = $this->requireUserOr401(session: $this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        $requestId = (string) $this->request->getParam('requestId', '');
        $signature = (string) $this->request->getParam('signature', '');
        if ($requestId === '' || $signature === '') {
            return new JSONResponse(
                ['message' => "Missing required parameter 'requestId' or 'signature'."],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->signatureService->verifySignature($requestId, $signature);

        return new JSONResponse(
            [
                'valid'                 => (bool) ($result['valid'] ?? false),
                'certificateThumbprint' => ($result['certificateThumbprint'] ?? null),
                'timestamp'             => ($result['timestamp'] ?? null),
                'message'               => (string) ($result['message'] ?? ''),
                'minutesId'             => $minutesId,
            ]
        );

    }//end verify()

    /**
     * Finalise a signed Minutes record (collect signatures, archive PDF).
     *
     * @param string $minutesId UUID of the minutes record
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function finalize(string $minutesId): JSONResponse
    {
        $auth = $this->requireUserOr401(session: $this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        $signatures = (array) $this->request->getParam('signatures', []);

        $result = $this->signatureService->finalizeMinutes($minutesId, $signatures);
        if (($result['success'] ?? false) === false) {
            return new JSONResponse(
                ['message' => (string) ($result['message'] ?? 'Failed to finalize minutes.')],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        return new JSONResponse(
            [
                'pdfArchiveReference' => $result['pdfArchiveReference'],
                'hashSha256'          => $result['hashSha256'],
                'message'             => $result['message'],
            ]
        );

    }//end finalize()

    /**
     * Report a certificate chain's trust status against the EU Trusted List
     * (informational pre-flight for the signing UI — the authoritative chain
     * validation happens server-side inside finalizeMinutes()).
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function certStatus(): JSONResponse
    {
        $auth = $this->requireUserOr401(session: $this->userSession);
        if ($auth !== null) {
            return $auth;
        }

        $thumbprint = (string) $this->request->getParam('certificateThumbprint', '');
        if ($thumbprint === '') {
            return new JSONResponse(
                ['message' => "Missing required parameter 'certificateThumbprint'."],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->signatureService->validateCertificateChain($thumbprint);

        return new JSONResponse(
            [
                'valid'          => (bool) ($result['valid'] ?? false),
                'issuer'         => ($result['issuer'] ?? null),
                'trustListLevel' => ($result['trustListLevel'] ?? null),
                'message'        => (string) ($result['message'] ?? ''),
            ]
        );

    }//end certStatus()
}//end class
