<?php

/**
 * Rights per record type, and the role to group mapping.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\RoleGroupMapping;
use OCA\Decidiq\Service\SettingsService;
use OCA\Decidiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;

/**
 * Administrators only. GET lists per record type who may read, create,
 * change and delete it, and the groups mapped onto each role. PUT stores a
 * new mapping and re-imports the register, so the rules OpenRegister
 * enforces name the mapped groups.
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
 */
class RoleRightsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest         $request      The request.
	 * @param SettingsService  $settings     The register import.
	 * @param RoleGroupMapping $mapping      The mapping and the rewrite.
	 * @param IAppConfig       $appConfig    App config.
	 * @param IGroupManager    $groupManager Groups.
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
	 */
	public function __construct(
		IRequest $request,
		private readonly SettingsService $settings,
		private readonly RoleGroupMapping $mapping,
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The roles with their groups, and the rules per record type.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function index(): JSONResponse {
		return new JSONResponse($this->state());
	}//end index()

	/**
	 * Store the mapping and re-import the register.
	 *
	 * @param array<mixed> $mapping Role => list of Nextcloud group ids.
	 *
	 * @return JSONResponse 400 naming a group that does not exist.
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function update(array $mapping=[]): JSONResponse {
		$clean = $this->mapping->normalise(mapping: $mapping);
		foreach ($clean as $groups) {
			foreach ($groups as $group) {
				if ($this->groupManager->groupExists($group) === false) {
					return new JSONResponse(
						['message' => sprintf('There is no group %s.', $group)],
						Http::STATUS_BAD_REQUEST
					);
				}
			}
		}

		$this->appConfig->setValueString(
			Application::APP_ID,
			RoleGroupMapping::CONFIG_KEY,
			(string) json_encode($clean)
		);
		$import = $this->settings->reloadConfiguration();
		if (($import['success'] ?? false) !== true) {
			return new JSONResponse(
				[
					'message' => 'The mapping is saved, but the register could not be re-imported, '
						. 'so the rules still name the old groups. See the server log.',
				] + $this->state(),
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return new JSONResponse($this->state());
	}//end update()

	/**
	 * The page's data.
	 *
	 * @return array{roles: list<array{role: string, groups: list<string>, mapped: list<string>}>, groups: list<string>, recordTypes: list<array>}
	 */
	private function state(): array {
		$mapped = $this->settings->roleGroupMapping();
		$roles  = [];
		foreach (RoleGroupMapping::ROLES as $role => $groups) {
			$roles[] = ['role' => $role, 'groups' => $groups, 'mapped' => ($mapped[$role] ?? [])];
		}

		$available = [];
		foreach ($this->groupManager->search('', 500) as $group) {
			$available[] = $group->getGID();
		}

		return [
			'roles'       => $roles,
			'groups'      => $available,
			'recordTypes' => $this->mapping->overview(config: $this->settings->mergedRegisterConfig()),
		];
	}//end state()
}//end class
