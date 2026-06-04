<?php
/**
 * Decidesk Board Regulator Controller
 *
 * Secretary/admin endpoints to grant/revoke regulator access, plus a
 * token-gated read-only endpoint that validates the signed bearer grant.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\BoardAuditLogService;
use OCA\Decidesk\Service\RegulatorAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Thin controller for regulator/auditor access endpoints.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.2
 */
class BoardRegulatorController extends Controller
{

    /**
     * Register slug.
     *
     * @var string
     */
    private const REGISTER = 'decidesk';

    /**
     * Constructor.
     *
     * @param IRequest               $request      The request.
     * @param RegulatorAccessService $regulatorSvc The regulator access service.
     * @param BoardAuditLogService   $auditLog     The audit log service.
     * @param IUserSession           $userSession  The user session.
     * @param IGroupManager          $groupManager The group manager.
     * @param IAppConfig             $appConfig    The app config.
     * @param ContainerInterface     $container    The DI container.
     * @param LoggerInterface        $logger       The logger.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.2
     */
    public function __construct(
        IRequest $request,
        private readonly RegulatorAccessService $regulatorSvc,
        private readonly BoardAuditLogService $auditLog,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IAppConfig $appConfig,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Require the caller to be a board secretary (configured group) or admin.
     *
     * @return JSONResponse|null
     */
    private function requireSecretary(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $uid          = $user->getUID();
        $secretaryGrp = $this->appConfig->getValueString('decidesk', 'board_secretary_group', '');
        $authorized   = $this->groupManager->isAdmin($uid);
        if ($secretaryGrp !== '') {
            $authorized = $this->groupManager->isInGroup($uid, $secretaryGrp);
        }

        if ($authorized === false) {
            return new JSONResponse(['message' => 'Board secretary role required'], Http::STATUS_FORBIDDEN);
        }

        return null;

    }//end requireSecretary()

    /**
     * Create a regulator/auditor access grant.
     *
     * POST /api/board/auditor-access
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.2
     */
    #[NoAdminRequired]
    public function grant(): JSONResponse
    {
        $guard = $this->requireSecretary();
        if ($guard !== null) {
            return $guard;
        }

        $params    = $this->request->getParams();
        $recipient = (string) ($params['recipientEmail'] ?? '');
        $scope     = (string) ($params['scope'] ?? '');
        $duration  = (int) ($params['durationDays'] ?? 7);
        if ($recipient === '' || $scope === '') {
            return new JSONResponse(['message' => 'recipientEmail and scope are required'], Http::STATUS_BAD_REQUEST);
        }

        $uid = (string) ($this->userSession->getUser()?->getUID() ?? '');
        try {
            $grant = $this->regulatorSvc->grantAccess($recipient, $scope, $duration, $uid);
            return new JSONResponse($grant, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end grant()

    /**
     * Read board records via a signed regulator bearer token (token-gated public page).
     *
     * This endpoint is a PublicPage because the recipient is an external auditor or
     * regulator without a Nextcloud account, but it is NOT open: it requires a valid
     * HMAC-signed, unexpired, scope-limited token in the Authorization header (or the
     * `token` query parameter) and every view is logged to the audit trail.
     *
     * GET /api/board/auditor/records
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.2
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function auditorRecords(): JSONResponse
    {
        $authHeader = (string) $this->request->getHeader('Authorization');
        $token      = (string) ($this->request->getParam('token', ''));
        if (str_starts_with($authHeader, 'Bearer ') === true) {
            $token = substr($authHeader, 7);
        }

        if ($token === '') {
            return new JSONResponse(['message' => 'Access token required'], Http::STATUS_UNAUTHORIZED);
        }

        $validation = $this->regulatorSvc->validateToken($token);
        if ($validation['valid'] === false) {
            return new JSONResponse(['message' => 'Invalid or expired access token'], Http::STATUS_UNAUTHORIZED);
        }

        $scope = (string) ($validation['scope'] ?? '');
        $this->auditLog->append('regulator:'.($validation['recipient'] ?? 'unknown'), 'material-access', [$scope]);

        $data = $this->collectScopedRecords();
        $view = $this->regulatorSvc->filterByScope($scope, $data);

        return new JSONResponse(['scope' => $scope, 'results' => $view], Http::STATUS_OK);

    }//end auditorRecords()

    /**
     * Collect resolutions and materials annotated for scope filtering.
     *
     * @return array<int,array<string,mixed>>
     */
    private function collectScopedRecords(): array
    {
        $records = [];
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            foreach ([['resolution', 'resolution'], ['board-material', 'material']] as $pair) {
                $objectService->setRegister(register: self::REGISTER);
                $objectService->setSchema(schema: $pair[0]);
                $result = $objectService->findAll(config: []);
                foreach (($result['results'] ?? $result) as $item) {
                    $data = (array) $item;
                    if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
                        $data = $item->jsonSerialize();
                    }

                    $data['_type'] = $pair[1];
                    $records[]     = $data;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Decidesk: could not collect regulator records', ['exception' => $e->getMessage()]);
        }

        return $records;

    }//end collectScopedRecords()
}//end class
