<?php
/**
 * Decidiq SetupController.
 *
 * The ADR-042 first-time setup contract, in its smallest honest form:
 *
 *   GET  /api/setup/status            per-step state
 *   POST /api/setup/action/{actionId} run a privileged server-side action
 *
 * The wizard orients, then asks the ONE question a new administrator actually
 * has: which kind of organisation is this for? The answer picks an example set,
 * and the app plants that set and nothing else.
 *
 * It deliberately does not invent further configuration steps: a wizard that
 * asks questions the app does not act on is worse than none.
 *
 * @category Controller
 * @package  OCA\Decidiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Controller;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use OCA\Decidiq\Service\SeedProfileService;

/**
 * First-time setup wizard endpoints.
 *
 * @spec exclude First-time-setup action dispatch; ADR-042 contract, no per-app behavioural spec.
 */
class SetupController extends Controller {
	/**
	 * Setup contract version; matches manifest.setup.version.
	 *
	 * @var integer
	 */
	private const SETUP_VERSION = 1;

	/**
	 * App-config key recording that the demo-data step was DEALT WITH.
	 *
	 * Not "objects exist": an operator who declines has finished the step, and
	 * re-offering the import on every visit would make "no thanks" impossible to
	 * express. Since @conduction/nextcloud-vue 2.21 that also matters visually —
	 * an OUTSTANDING OPTIONAL step opens the wizard over every page
	 * (nextcloud-vue#806), so a step that can never be marked done is a dialog
	 * that never closes.
	 *
	 * @var string
	 */
	private const DEMO_DECIDED_KEY = 'demo_data_decided';

	/**
	 * App-config key holding the example set the operator picked.
	 *
	 * The wizard's `choice` step writes it through `POST /api/setup/config`, and
	 * the `run-action` step that follows reads it back. Two steps rather than
	 * one because `CnSetupWizard::runAction()` posts to
	 * `/api/setup/action/{action}` with no body: an action cannot carry the
	 * answer, so the answer has to be stored before the action runs.
	 *
	 * @var string
	 */
	private const PROFILE_KEY = 'example_profile';

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request      The request.
	 * @param IAppConfig         $appConfig    Records the operator's answer.
	 * @param LoggerInterface    $logger       Records a failed import.
	 * @param SeedProfileService $seedProfiles Lists and imports the example sets.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly SeedProfileService $seedProfiles,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Report per-step setup status for the wizard.
	 *
	 * `completed` is deliberately TRUE: this app declares no REQUIRED step, so
	 * setup must never gate the app. Both example-set steps are reported so the
	 * wizard can stop asking once it has an answer.
	 *
	 * @return JSONResponse The status document.
	 *
	 * @spec exclude Setup status document; ADR-042 contract, no per-app behavioural spec.
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function status(): JSONResponse {
		$picked      = $this->appConfig->getValueString(Application::APP_ID, self::PROFILE_KEY, '');
		$demoDecided = $this->appConfig->getValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, '') !== '';

		return new JSONResponse(
			data: [
				'version'   => self::SETUP_VERSION,
				'completed' => true,
				'profiles'  => $this->seedProfiles->listProfiles(),
				'steps'     => [
					'example-set' => ['done' => ($picked !== '')],
					// "None" is an ANSWER, so the load step is finished the moment
					// it is chosen: there is nothing left for the operator to run.
					'load-example-set' => [
						'done' => ($demoDecided === true || $picked === SeedProfileService::NONE_PROFILE),
					],
				],
			]
		);

	}//end status()

	/**
	 * Persist the wizard's `choice` and `config-fields` answers.
	 *
	 * The `CnSetupWizard` contract: a `choice` step POSTs
	 * `{ <configKey>: <value> }` here before it advances.
	 *
	 * @return JSONResponse `{ success, config }`.
	 *
	 * @spec openspec/changes/seed-profiles/specs/seed-profiles/spec.md#requirement-record-the-chosen-example-set
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function saveConfig(): JSONResponse {
		// 🔴 ONE NAMED KEY, NEVER A CALLER-SUPPLIED ONE. The body arrives from the
		// browser, and this app's own settings share the appconfig namespace —
		// including `voter_token_secret`, the HMAC key signing every voting token
		// and mail-reply link. Looping over the posted keys would let this
		// endpoint rotate that secret and silently invalidate every outstanding
		// vote link, so the key is written in the source and only its value comes
		// from the request.
		$value = $this->request->getParam(self::PROFILE_KEY);
		if ($value === null) {
			return new JSONResponse(data: ['success' => true, 'config' => []]);
		}

		$profileId = (string)$value;
		if ($this->isSelectableProfile(profileId: $profileId) === false) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'No example set is called "' . $profileId . '".'],
				statusCode: Http::STATUS_BAD_REQUEST,
			);
		}

		$this->appConfig->setValueString(Application::APP_ID, self::PROFILE_KEY, $profileId);

		return new JSONResponse(data: ['success' => true, 'config' => [self::PROFILE_KEY => $profileId]]);

	}//end saveConfig()

	/**
	 * Whether a value is one the choice step may legitimately carry.
	 *
	 * @param string $profileId The submitted value.
	 *
	 * @return boolean True when it names a set, or declines one.
	 */
	private function isSelectableProfile(string $profileId): bool {
		if ($profileId === SeedProfileService::NONE_PROFILE) {
			return true;
		}

		return $this->seedProfiles->isKnown(profileId: $profileId);

	}//end isSelectableProfile()

	/**
	 * Run a privileged server-side setup action.
	 *
	 * Admin-only by Nextcloud's default for an un-attributed method.
	 *
	 * @param string $actionId One of `load-example-set` | `skip-example-set`.
	 *
	 * @return JSONResponse `{ success, message }`.
	 *
	 * @spec exclude Setup action dispatch; ADR-042 contract, no per-app behavioural spec.
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function runAction(string $actionId): JSONResponse {
		if ($actionId === 'load-example-set') {
			return $this->loadExampleSet();
		}

		// DECLINING IS AN ANSWER — see DEMO_DECIDED_KEY.
		//
		// 🔴 AND IT ANSWERS *BOTH* STEPS, WHICH ONE WRITE HERE USED TO MISS.
		// Splitting the old single `demo-data` step into a `choice` and a
		// `run-action` gave the wizard TWO outstanding steps, and this action
		// closed only the second. CnAppRoot opens the wizard while ANY optional
		// step is outstanding, so `skip-example-set` returned 200, reported "no
		// example data was loaded", and left the wizard open over every page.
		//
		// Measured 2026-08-30: after ci-seed.sh posted this action the status was
		// still `example-set: {done: false}`, and the e2e suite failed on
		// `<ol class="cn-wizard-dialog__progress">` intercepting clicks that
		// Playwright had already resolved — "visible, enabled and stable", then a
		// timeout.
		//
		// Skipping IS choosing none, so it records that choice.
		if ($actionId === 'skip-example-set') {
			$this->appConfig->setValueString(Application::APP_ID, self::PROFILE_KEY, SeedProfileService::NONE_PROFILE);
			$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, 'skipped');

			return new JSONResponse(data: ['success' => true, 'message' => 'No example data was loaded.']);
		}

		return new JSONResponse(
			data: ['success' => false, 'message' => 'Unknown setup action: ' . $actionId],
			statusCode: Http::STATUS_NOT_FOUND,
		);

	}//end runAction()

	/**
	 * Import the example set the operator picked in the previous step.
	 *
	 * Reports the FAILURE rather than a quiet success: an operator who asked for
	 * example data and got none must be told, which is why
	 * SeedProfileService::install() throws instead of returning an empty result.
	 *
	 * @return JSONResponse `{ success, message }`.
	 */
	private function loadExampleSet(): JSONResponse {
		$profileId = $this->appConfig->getValueString(Application::APP_ID, self::PROFILE_KEY, '');

		// 🔴 NO SILENT DEFAULT. Guessing a set here would plant a municipality
		// into an operator's register because they clicked Run one step early,
		// which is the exact failure this whole change exists to remove.
		if ($profileId === '') {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'Pick an example set first.'],
				statusCode: Http::STATUS_BAD_REQUEST,
			);
		}

		if ($profileId === SeedProfileService::NONE_PROFILE) {
			$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, 'skipped');

			return new JSONResponse(data: ['success' => true, 'message' => 'No example data was loaded.']);
		}

		try {
			$imported = $this->seedProfiles->install(profileId: $profileId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Setup load-example-set failed for "' . $profileId . '": ' . $e->getMessage(),
				['app' => Application::APP_ID, 'exception' => $e]
			);

			return new JSONResponse(
				data: ['success' => false, 'message' => 'Could not import the example data: ' . $e->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DECIDED_KEY, 'installed');

		return new JSONResponse(
			data: [
				'success' => true,
				'message' => 'Imported ' . $imported['objects'] . ' example object(s).',
			]
		);

	}//end loadExampleSet()
}//end class
