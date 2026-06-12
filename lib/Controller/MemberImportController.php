<?php
/**
 * Decidesk Member Import Controller
 *
 * Admin-only endpoints backing the governance-body member import dialogs
 * (Nextcloud-group import and CSV account matching).
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
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Controller;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\MemberImportService;
use OCA\Decidesk\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin-only member-import endpoints.
 *
 * All methods carry #[AuthorizedAdminSetting(AdminSettings::class)] — group
 * enumeration and email-to-account matching are directory disclosure and are
 * restricted to (delegated) administrators, matching the posture of the
 * settings mutation endpoints. Participant creation itself happens through
 * the OpenRegister object API with OpenRegister's per-object RBAC.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class MemberImportController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest            $request             The request object.
     * @param MemberImportService $memberImportService The member import service.
     */
    public function __construct(
        IRequest $request,
        private MemberImportService $memberImportService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List all Nextcloud groups (id, display name, member count).
     *
     * Admin-only: enumerating groups is directory disclosure.
     *
     * @spec openspec/specs/admin-settings/spec.md
     *
     * @return JSONResponse
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function groups(): JSONResponse
    {
        return new JSONResponse(
            ['groups' => $this->memberImportService->listGroups()]
        );
    }//end groups()

    /**
     * List the members of one Nextcloud group (uid, display name, email).
     *
     * Admin-only: enumerating group membership is directory disclosure.
     *
     * @param string $groupId The Nextcloud group id.
     *
     * @spec openspec/specs/admin-settings/spec.md
     *
     * @return JSONResponse
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function groupMembers(string $groupId): JSONResponse
    {
        $members = $this->memberImportService->getGroupMembers($groupId);
        if ($members === null) {
            return new JSONResponse(
                ['message' => 'Group not found'],
                Http::STATUS_NOT_FOUND
            );
        }

        return new JSONResponse(['members' => $members]);
    }//end groupMembers()

    /**
     * Match CSV emails to Nextcloud accounts.
     *
     * Validates each email shape and caps the batch server-side at
     * MemberImportService::MAX_MATCH_ROWS (413 beyond the cap, 422 when the
     * payload is not a list of emails).
     *
     * @spec openspec/specs/admin-settings/spec.md
     *
     * @return JSONResponse
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function match(): JSONResponse
    {
        $emails = $this->request->getParam('emails');
        if (is_array($emails) === false) {
            return new JSONResponse(
                ['message' => 'emails must be an array'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $matches = $this->memberImportService->matchEmails($emails);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['message' => $e->getMessage()],
                Http::STATUS_REQUEST_ENTITY_TOO_LARGE
            );
        }

        return new JSONResponse(['matches' => $matches]);
    }//end match()
}//end class
