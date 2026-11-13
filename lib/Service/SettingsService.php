<?php

/**
 * Decidiq Settings Service
 *
 * Service for managing Decidiq application configuration and settings.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Service\Settings\MenuStructure;
use OCA\Decidiq\Service\Settings\RegisterFragmentMerger;
use OCA\Decidiq\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Decidiq application configuration and settings.
 *
 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
 */
class SettingsService {

	/**
	 * Configuration keys managed by this service.
	 *
	 * Includes the main register slug plus schema slugs for Minutes, Decision,
	 * and ActionItem so the frontend initializeStores() can register object stores.
	 * motion_min_cosigners (motion-amendment spec) is the configurable minimum
	 * co-signer count enforced on the motion proposed→deliberating transition
	 * (0 = disabled).
	 *
	 * @var array<string>
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-10
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions/tasks.md#task-3
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private const CONFIG_KEYS = [
		'register',
		'ori_endpoint',
		'ori_bearer_secret',
		'email_voting_enabled',
		// @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
		// 'true' sends the meeting file to the case system when minutes are approved.
		'case_system_send_on_approval',
		'minutesSchema',
		'decisionSchema',
		'actionItemSchema',
		// Co-signer minimum threshold per openspec/specs/motion-amendment/spec.md.
		'motion_min_cosigners',
		// Organization-level defaults per openspec/specs/admin-settings/spec.md
		// (Organization Configuration requirement).
		'organisation_name',
		'organisation_logo',
		'organisation_timezone',
		'organisation_locale',
		'organisation_currency',
		'organisation_retention_days',
		// Mode-aware label selection: gov|corp|assoc|ops|citizen.
		// @spec openspec/specs/admin-settings/spec.md#requirement-req-adm-mode-001-organisatie-modus-tenant-setting
		// Cosmetic UI hint only — drives no authorization decision.
		'organisatie_modus',
		// @spec openspec/specs/citizen-participation/spec.md
		// Citizen-participation instance defaults.
		'participation_default_moderation_policy',
		'participation_catalog',
		'participation_anon_rate_limit',
		// @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-004-an-administrator-can-switch-automatic-conversion-off
		// Office papers are converted to PDF on arrival unless this reads false.
		'convert_office_papers',
		// 'true' makes a motion forwarded to another body wait for that body's
		// chair (MotionForwardingService).
		'motion_forwarding_requires_approval',
		// Which structure the app shows (simple-structure-profile). A key that
		// is not on this list is dropped by updateSettings() while the write
		// still answers success.
		MenuStructure::KEY,
	];

	/**
	 * Write-only configuration keys: accepted by updateSettings() but never
	 * echoed back by getSettings(). The settings#index route is reachable by
	 * any authenticated user (#[NoAdminRequired]) and previously leaked the
	 * ORI bearer secret to non-admins; consumers (OriPublicationService) read
	 * the secret directly from IAppConfig.
	 *
	 * @var array<string>
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private const SECRET_KEYS = [
		'ori_bearer_secret',
	];

	/**
	 * What getSettings() answers for a key that holds no value.
	 *
	 * The schema slugs match the slugs defined in decidesk_register.json;
	 * organisatie_modus defaults to 'gov' (government/municipal mode).
	 *
	 * @var array<string,string>
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions/tasks.md#task-3
	 * @spec openspec/specs/admin-settings/spec.md#requirement-req-adm-mode-001-organisatie-modus-tenant-setting
	 */
	private const DEFAULTS = [
		'minutesSchema' => 'minutes',
		'decisionSchema' => 'decision',
		'actionItemSchema' => 'action-item',
		'organisatie_modus' => 'gov',
		'convert_office_papers' => 'true',
		'motion_forwarding_requires_approval' => 'false',
	];

	/**
	 * Constructor for the SettingsService.
	 *
	 * @param IAppConfig $appConfig The app config interface
	 * @param IAppManager $appManager The app manager
	 * @param ContainerInterface $container The container
	 * @param IGroupManager $groupManager The group manager
	 * @param IUserSession $userSession The user session
	 * @param LoggerInterface $logger The logger
	 * @param RoleGroupMapping $roleMapping The role to group mapping written into the rules on import
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private ContainerInterface $container,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private RoleGroupMapping $roleMapping=new RoleGroupMapping(),
	) {
	}//end __construct()

	/**
	 * Check whether OpenRegister is installed and available.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-dashboard-and-navigation/tasks.md#task-1.3
	 *
	 * @return bool
	 */
	public function isOpenRegisterAvailable(): bool {
		return $this->appManager->isEnabledForAnyone('openregister');
	}//end isOpenRegisterAvailable()

	/**
	 * Retrieve all current settings.
	 *
	 * Returns a flat array containing all app config values plus metadata
	 * fields (openregisters, isAdmin) consumed by the frontend.
	 *
	 * The Minutes, Decision, and ActionItem schema/register slugs are registered
	 * directly in src/store/store.js::OBJECT_TYPES (alongside all other entity types)
	 * and do not require additional settings keys — the frontend resolves them from the
	 * static OBJECT_TYPES map after confirming OpenRegister is available.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-dashboard-and-navigation/tasks.md#task-2.1
	 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
	 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions/tasks.md#task-3.1
	 *
	 * @return array<string,mixed>
	 */
	public function getSettings(): array {
		$settings = $this->storedSettings();
		$user = $this->userSession->getUser();
		$isAdmin = ($user !== null && $this->groupManager->isAdmin($user->getUID()));

		return array_merge(
			$settings,
			[
				'openregisters' => $this->isOpenRegisterAvailable(),
				// Office papers are converted by filinq; the admin page says so when it is absent.
				'filinq' => FleetAppId::isInstalled(appManager: $this->appManager, canonical: 'filinq'),
				// UI-HINT ONLY: isAdmin is used exclusively to control frontend rendering
				// (e.g. showing/hiding admin-only settings panels). It MUST NOT be used
				// for server-side access control decisions. All admin-gated backend routes
				// enforce the admin check independently via IGroupManager::isAdmin().
				'isAdmin' => $isAdmin,
			]
		);
	}//end getSettings()

	/**
	 * The stored value of every readable key, or its default when none is stored.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-dashboard-and-navigation/tasks.md#task-2.1
	 *
	 * @return array<string,string>
	 */
	private function storedSettings(): array {
		$settings = [];
		foreach (self::CONFIG_KEYS as $key) {
			if (in_array($key, self::SECRET_KEYS, true) === true) {
				// Write-only: never echo secrets to the (any-user) index route.
				continue;
			}

			$value = $this->appConfig->getValueString(AppIdentity::APP_ID, $key, '');
			$settings[$key] = (self::DEFAULTS[$key] ?? '');
			if ($value !== '') {
				$settings[$key] = $value;
			}
		}

		return $settings;
	}//end storedSettings()

	/**
	 * Update settings with the provided data.
	 *
	 * @param array<string,mixed> $data The data to update
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-dashboard-and-navigation/tasks.md#task-2.2
	 *
	 * @return array<string,mixed> The updated settings
	 */
	public function updateSettings(array $data): array {
		foreach (self::CONFIG_KEYS as $key) {
			if (isset($data[$key]) === true) {
				$this->appConfig->setValueString(AppIdentity::APP_ID, $key, (string)$data[$key]);
			}
		}

		return $this->getSettings();
	}//end updateSettings()

	/**
	 * Load configuration from decidesk_register.json via OpenRegister.
	 *
	 * Import is skipped when the register is already configured at the same
	 * version/fragment signature. Use {@see reloadConfiguration()} to force a
	 * re-import.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-dashboard-and-navigation/tasks.md#task-1.3
	 * @spec openspec/changes/archive/2026-05-11-p1-dashboard-and-navigation/tasks.md#task-2.1
	 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 */
	public function loadConfiguration(): array {
		return $this->importConfiguration(force: false);
	}//end loadConfiguration()

	/**
	 * Re-import configuration from decidesk_register.json, ignoring the
	 * already-configured check.
	 *
	 * The forcing counterpart of {@see loadConfiguration()}; both delegate to
	 * the same import routine.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-dashboard-and-navigation/tasks.md#task-1.3
	 * @spec openspec/changes/archive/2026-05-11-p1-dashboard-and-navigation/tasks.md#task-2.1
	 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 */
	public function reloadConfiguration(): array {
		return $this->importConfiguration(force: true);
	}//end reloadConfiguration()

	/**
	 * Shared configuration import used by loadConfiguration()/reloadConfiguration().
	 *
	 * @param bool $force Force re-import even if already configured.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-dashboard-and-navigation/tasks.md#task-1.3
	 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 */
	private function importConfiguration(bool $force): array {
		if ($this->isOpenRegisterAvailable() === false) {
			$this->logger->warning('Decidiq: OpenRegister not available, skipping register initialization');
			return self::failure(message: 'OpenRegister is not installed or enabled.');
		}

		try {
			return $this->importMergedConfig(force: $force);
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: configuration import failed', ['exception' => $e->getMessage()]);
			return self::failure(message: 'Configuration import failed. See server log for details.');
		}//end try
	}//end importConfiguration()

	/**
	 * Read the base register, merge the fragments and the role mapping, and
	 * hand the result to OpenRegister.
	 *
	 * @param bool $force Force re-import even if already configured.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 */
	private function importMergedConfig(bool $force): array {
		[$configData, $failure] = $this->readBaseRegisterConfig();
		if ($failure !== null) {
			return $failure;
		}

		[$configData, $fragmentSig] = $this->mergeRegisterFragments(configData: $configData);
		$configData = $this->roleMapping->rewrite(config: $configData, mapping: $this->roleGroupMapping());
		$version    = $this->configVersion(configData: $configData, fragmentSig: $fragmentSig);

		$configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
		return $this->importResult(
			result: $configurationService->importFromApp(appId: AppIdentity::APP_ID, data: $configData, version: $version, force: $force)
		);
	}//end importMergedConfig()

	/**
	 * The groups an administrator added to each decidiq role.
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
	 *
	 * @return array<string, list<string>> Role => group ids, every role present.
	 */
	public function roleGroupMapping(): array {
		return $this->roleMapping->decode(
			json: $this->appConfig->getValueString(AppIdentity::APP_ID, RoleGroupMapping::CONFIG_KEY, '')
		);
	}//end roleGroupMapping()

	/**
	 * The register as the import sends it: base, fragments and the role
	 * mapping applied. Empty when the base file cannot be read.
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
	 *
	 * @return array<string,mixed>
	 */
	public function mergedRegisterConfig(): array {
		[$configData, $failure] = $this->readBaseRegisterConfig();
		if ($failure !== null) {
			return [];
		}

		[$configData] = $this->mergeRegisterFragments(configData: $configData);
		return $this->roleMapping->rewrite(config: $configData, mapping: $this->roleGroupMapping());
	}//end mergedRegisterConfig()

	/**
	 * Read and decode the monolithic decidesk_register.json.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-dashboard-and-navigation/tasks.md#task-1.3
	 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
	 *
	 * @return array{0: array<string,mixed>, 1: array<string,mixed>|null} [configData, failureResult]
	 */
	private function readBaseRegisterConfig(): array {
		$configPath = self::baseDescriptorPath();
		if (file_exists($configPath) === false) {
			$this->logger->error('Decidiq: decidesk_register.json not found at ' . $configPath);
			return [[], self::failure(message: 'Configuration file decidesk_register.json not found.')];
		}

		$configContent = file_get_contents($configPath);
		if ($configContent === false) {
			$this->logger->error('Decidiq: failed to read decidesk_register.json');
			return [[], self::failure(message: 'Failed to read configuration file.')];
		}

		return $this->decodeBaseRegisterConfig(configContent: $configContent);
	}//end readBaseRegisterConfig()

	/**
	 * Decode the content of decidesk_register.json.
	 *
	 * @param string $configContent The file content.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
	 *
	 * @return array{0: array<string,mixed>, 1: array<string,mixed>|null} [configData, failureResult]
	 */
	private function decodeBaseRegisterConfig(string $configContent): array {
		$configData = json_decode($configContent, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->logger->error('Decidiq: failed to parse decidesk_register.json: ' . json_last_error_msg());
			return [[], self::failure(message: 'Failed to parse configuration file: ' . json_last_error_msg())];
		}

		return [$configData, null];
	}//end decodeBaseRegisterConfig()

	/**
	 * A failed result, as loadConfiguration() answers it.
	 *
	 * @param string $message The failure message.
	 *
	 * @return array{success: false, message: string}
	 */
	private static function failure(string $message): array {
		return [
			'success' => false,
			'message' => $message,
		];
	}//end failure()

	/**
	 * Merge modular register fragments from Settings/register.d/*.json (ADR-037).
	 *
	 * Each OpenSpec change drops its own fragment file instead of editing the
	 * monolith, so concurrent builds touch disjoint files (no merge conflicts).
	 * OpenAPI `components.schemas` / `paths` are keyed objects, so disjoint
	 * fragments union cleanly by key.
	 *
	 * @param array<string,mixed> $configData The decoded base configuration.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
	 *
	 * @return array{0: array<string,mixed>, 1: string} [mergedConfig, fragmentSignature]
	 */
	private function mergeRegisterFragments(array $configData): array {
		$logger = $this->logger;
		$merger = new RegisterFragmentMerger(
			onMalformed: static function (string $fragment, string $reason) use ($logger): void {
				$logger->warning('Decidiq: skipping malformed register fragment ' . $fragment . ': ' . $reason);
			}
		);

		return $merger->mergeDirectory(configData: $configData, fragmentDir: self::fragmentDirectory());
	}//end mergeRegisterFragments()

	/**
	 * Where the base descriptor lives.
	 *
	 * Named rather than inlined so a caller that wants to read what this app
	 * SHIPS cannot drift from what this service IMPORTS.
	 *
	 * @return string Absolute path to decidesk_register.json.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
	 */
	public static function baseDescriptorPath(): string {
		return __DIR__ . '/../Settings/decidesk_register.json';
	}//end baseDescriptorPath()

	/**
	 * Where the ADR-037 fragments live.
	 *
	 * @return string Absolute path to lib/Settings/register.d.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
	 */
	public static function fragmentDirectory(): string {
		return __DIR__ . '/../Settings/register.d';
	}//end fragmentDirectory()

	/**
	 * The descriptor this app ships, base plus every fragment, merged.
	 *
	 * 🔴 ONE MERGE, NOT TWO. A guard that re-implements the merge is answering
	 * about something adjacent: it can read a register the importer never sees
	 * and pass while the instance is wrong. This is the same code path
	 * importConfiguration() hands to OpenRegister, so a test built on it is
	 * asking the authority.
	 *
	 * Static and dependency-free on purpose: reading the shipped JSON needs no
	 * container, no logger and no OpenRegister, so a unit test can call it.
	 *
	 * @return array<string,mixed> The merged configuration.
	 *
	 * @throws \JsonException When the base descriptor does not parse.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
	 */
	public static function shippedRegisterDescriptor(): array {
		$base = json_decode(
			(string)file_get_contents(self::baseDescriptorPath()),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		[$merged] = (new RegisterFragmentMerger())->mergeDirectory(configData: $base, fragmentDir: self::fragmentDirectory());

		return $merged;
	}//end shippedRegisterDescriptor()

	/**
	 * The version handed to OpenRegister's version-gated importer.
	 *
	 * The fragment signature and the role mapping signature are folded into
	 * the version so importFromApp re-imports whenever either changes.
	 *
	 * @param array<string,mixed> $configData  The merged configuration.
	 * @param string              $fragmentSig Signature of the merged fragments.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-dashboard-and-navigation/tasks.md#task-2.1
	 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
	 *
	 * @return string
	 */
	private function configVersion(array $configData, string $fragmentSig): string {
		$configVersion = ($configData['info']['version'] ?? '0.0.0');
		if ($fragmentSig !== '') {
			$configVersion .= '+frag.' . substr(md5($fragmentSig), 0, 8);
		}

		$roleSig = $this->roleMapping->signature(mapping: $this->roleGroupMapping());
		if ($roleSig !== '') {
			$configVersion .= '+roles.' . $roleSig;
		}

		return $configVersion;
	}//end configVersion()

	/**
	 * The result of an import, as loadConfiguration() answers it.
	 *
	 * @param mixed $result What importFromApp returned.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-dashboard-and-navigation/tasks.md#task-2.1
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 */
	private function importResult(mixed $result): array {
		if (empty($result) === true) {
			return self::failure(message: 'Import returned an empty result.');
		}

		$this->logger->info('Decidiq: register configuration imported successfully');

		return [
			'success' => true,
			'message' => 'Configuration imported successfully.',
			'version' => ($result['version'] ?? 'unknown'),
		];
	}//end importResult()
}//end class
