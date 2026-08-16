<?php

/**
 * Decidesk Application
 *
 * Main application class for the Decidesk Nextcloud app.
 *
 * @category AppInfo
 * @package  OCA\Decidesk\AppInfo
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

namespace OCA\Decidesk\AppInfo;

use OCA\Decidesk\AppInfo\Registrar\AppHostRegistrar;
use OCA\Decidesk\AppInfo\Registrar\DomainServiceRegistrar;
use OCA\Decidesk\AppInfo\Registrar\ObjectListenerRegistrar;
use OCA\Decidesk\AppInfo\Registrar\PlatformIntegrationRegistrar;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Util;

/**
 * Main application class for the Decidesk Nextcloud app.
 *
 * The bootstrap itself holds no registration detail. Every cohesive group of
 * bindings lives in a dedicated registrar under
 * {@see \OCA\Decidesk\AppInfo\Registrar}, so the class references (and the
 * `use` imports that carry them) sit with the registrations they serve rather
 * than accumulating here:
 *
 *   - {@see AppHostRegistrar}            AppHost boilerplate adoption (ADR-040 / ADR-022).
 *   - {@see DomainServiceRegistrar}      decision events, MCP tools, eIDAS, translation.
 *   - {@see PlatformIntegrationRegistrar} search, object-write guards, dashboard widget.
 *   - {@see ObjectListenerRegistrar}     boot()-time filtered object-lifecycle subscriptions.
 *
 * Decidesk's services, controllers and background jobs that are NOT listed in a
 * registrar are resolved by Nextcloud's autowiring container from their
 * constructor signatures; only bindings the container cannot infer are
 * declared at all.
 *
 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'decidesk';

	/**
	 * Constructor for the Application class.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct(appName: self::APP_ID);
	}//end __construct()

	/**
	 * Register event listeners and services.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
	 */
	public function register(IRegistrationContext $context): void {

		// ADR-084: services type-hint OpenRegister's PUBLISHED interface, never its
		// concrete class, so this app's unit tests can mock a type they are able to
		// load. Nextcloud autowires concrete classes across apps but not interfaces,
		// so the binding has to be stated — and the composition root is where this
		// app says how it is wired.
		//
		// An ALIAS, not a factory: it resolves when something actually asks for the
		// interface, so an instance without OpenRegister fails at the route that
		// needed the data rather than at registration. Both names are strings and
		// neither triggers an autoload, which is what keeps ADR-083 rule 3's promise
		// that the start screen still boots.
		$context->registerServiceAlias(
			ObjectServiceInterface::class,
			'OCA\OpenRegister\Service\ObjectService'
		);
		// AppHost adoption (ADR-040 / ADR-022): re-point the mechanical
		// dashboard + observability + deep-link plumbing at the OpenRegister
		// AppHost generics, keeping decidesk's URLs unchanged. Decidesk's
		// domain-entangled Settings / Preferences / AdminSettings / repair /
		// SettingsService stay bespoke.
		(new AppHostRegistrar())->register(context: $context);

		// The value migration talks to the database through a three-method port
		// rather than IDBConnection, because decidesk's unit environment cannot
		// double that connection at all (no doctrine/dbal). Bind the port here.
		$context->registerService(
			\OCA\Decidesk\Repair\ValueMigrationGateway::class,
			static fn ($c): \OCA\Decidesk\Repair\ValueMigrationGateway
				=> $c->get(\OCA\Decidesk\Repair\DbValueMigrationGateway::class)
		);

		// Decidesk domain bindings the autowiring container cannot infer:
		// the delegated-decision event listener, the MCP tool-provider alias,
		// the eIDAS QES resolver and the dormant translation adapter.
		//
		// MinutesController deliberately takes no userId: it is resolved
		// per-request inside each action via
		// $this->userSession->getUser()?->getUID(), so that the shared instance
		// never caches a null uid from an early unauthenticated bootstrap.
		// @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1.
		// @spec openspec/specs/decision-management/spec.md
		//
		// TaskService / DelegationService and their controllers were retired in
		// migrate-action-items-to-deck-leaf (ADR-022); WorkspaceService and
		// WorkspaceController in migrate-workspaces-to-collectives-leaf.
		// @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-4.1.
		// @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-4.1.
		//
		// The MigrateCommentsToTalkLeaf / MigrateActionItemsToDeckLeaf repair
		// steps are registered via appinfo/info.xml <repair-steps>;
		// IRegistrationContext has no registerRepairStep() method, and their
		// constructor dependencies are autowired when Nextcloud instantiates
		// them.
		// @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.1.
		// @spec openspec/specs/user-settings/spec.md
		(new DomainServiceRegistrar())->register(context: $context);

		// Board portal Phase 2 services (audit log, conflict of interest,
		// quorum verification and their controllers) are autowired.
		// board-meeting-resolutions is archived (openspec/changes/archive/
		// 2026-06-12-board-meeting-resolutions), so its tasks.md is not a live
		// target. @spec points at the CANONICAL spec that survived the change.
		// @spec openspec/specs/decision-management/spec.md
		(new PlatformIntegrationRegistrar())->register(context: $context);

	}//end register()

	/**
	 * Boot the application.
	 *
	 * @param IBootContext $context The boot context
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1
	 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
	 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OCP\Util exposes script and style
	 * registration (addInitScript/addScript/addStyle) as STATIC methods only —
	 * Nextcloud ships no injectable service for it, and boot() is the only place
	 * the app-wide init script can be registered. Wrapping the call in a seam
	 * class would relocate the identical static call rather than remove it, so
	 * the rule cannot be satisfied without abandoning the framework's script API.
	 * Verified against nextcloud lib/public/Util.php.
	 */
	public function boot(IBootContext $context): void {
		// C2: email-voting is disabled — MailReplyHandler is not registered.
		// The background job remains in place for future re-enablement but must
		// not be scheduled until the feature is audited and enabled deliberately.
		//
		// ADR-019 / ADR-022: load the tiny global integration-leaf bootstrap on
		// EVERY Nextcloud page so decidesk's "Besluitvorming" decisions leaf
		// registers on the shared OpenRegister integration registry and surfaces
		// as a sidebar tab + detail-page widget on host objects (e.g. a procest
		// case) without the full decidesk app bundle being present.
		Util::addInitScript(self::APP_ID, 'decidesk-integration-init');

		$serverContainer = $context->getServerContainer();

		// Object-lifecycle subscriptions MUST be made from boot(), never from
		// register(): OpenRegister's classes are only autoloadable to apps
		// registered after it, so the registrar's class_exists() guard would
		// resolve differently purely by app load order during register().
		$serverContainer->get(ObjectListenerRegistrar::class)->register(
			dispatcher: $serverContainer->get(IEventDispatcher::class)
		);

	}//end boot()
}//end class
