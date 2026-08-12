<?php

/**
 * Decidesk NC Platform Integration Registrar
 *
 * Nextcloud platform integration bindings: unified search, the object-write
 * guard listeners, and the Nextcloud main-dashboard widget.
 *
 * Extracted from {@see \OCA\Decidesk\AppInfo\Application} so the bootstrap
 * class stops accumulating a class reference for every registration it makes
 * (PHPMD CouplingBetweenObjects); the imports move with the registrations.
 *
 * @category AppInfo
 * @package  OCA\Decidesk\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\AppInfo\Registrar;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Dashboard\DecideskDashboardWidget;
use OCA\Decidesk\Listener\PortalCreateOpenParentGuardListener;
use OCA\Decidesk\Search\DecideskSearchProvider;
use OCA\Decidesk\Service\DashboardWidgetService;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Activity, unified search, object-write guards, reminders and the dashboard widget.
 *
 * The meeting-workspace bindings (Files folder tree, recurring series, meeting
 * document package), the governance role -> OpenRegister RBAC scope projection
 * and the meeting-transcription / AI draft-minutes bindings are all autowired;
 * only the listeners' event subscriptions (see
 * {@see ObjectListenerRegistrar}) and the factory-built bindings below need
 * declaring.
 *
 * The Activity provider/filter/setting classes are declared in
 * appinfo/info.xml <activity> (the Activity app resolves them from there); the
 * fail-soft Activity publisher called from the governance services is
 * autowired.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 * @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md
 */
class PlatformIntegrationRegistrar {
	/**
	 * Register the Nextcloud platform integration bindings.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$this->registerSearch(context: $context);
		$this->registerObjectWriteGuards(context: $context);
		$this->registerDashboardWidget(context: $context);

	}//end register()

	/**
	 * Unified search over decisions / meetings / resolutions (OR RBAC scoped).
	 *
	 * Registered explicitly because its IL10N has to be obtained from IFactory
	 * for this app id, which the container cannot infer.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 */
	private function registerSearch(IRegistrationContext $context): void {
		$context->registerService(
			DecideskSearchProvider::class,
			static function ($c): DecideskSearchProvider {
				return new DecideskSearchProvider(
					container: $c->get(ContainerInterface::class),
					urlGenerator: $c->get(IURLGenerator::class),
					l10n: $c->get(IFactory::class)->get(Application::APP_ID),
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
		$context->registerSearchProvider(DecideskSearchProvider::class);

	}//end registerSearch()

	/**
	 * Object-write guard listeners: the portal citizen create-actions open-parent guard.
	 *
	 * The submission deadline gate (motion-amendment spec) is a pre-save hook
	 * that rejects motion/amendment creations after the linked meeting's
	 * submissionDeadline (OpenRegister converts the stopped event into HTTP
	 * 422 at the object API). It declares its schema interest up front and is
	 * therefore subscribed from boot() via {@see ObjectListenerRegistrar}, not
	 * here.
	 *
	 * The portal citizen create-actions open-parent guard
	 * (portal-citizen-create-actions, REQ-DKPCA-001/002) rejects a
	 * consultation-reaction/budget-proposal create whose parent
	 * consultation/budget round is not open, closing the gap left by
	 * portaliq's shared create receiver (which stamps scope + defaults but does
	 * not enforce a declared parentConstraint).
	 *
	 * Deliberately NOT narrowed with ObjectEventSubscription, unlike the
	 * listeners in {@see ObjectListenerRegistrar}. This is the one object
	 * listener in the app that does not identify its rows by schema slug at
	 * all: its class docblock records that on `ObjectCreatingEvent` no slug is
	 * reachable, so it identifies its two schemas by their REQUIRED field
	 * signature instead, and therefore also fires on any lookalike row —
	 * described there as a deliberately stricter, defence-in-depth posture.
	 * Declaring `['consultation-reaction', 'budget-proposal']` would quietly
	 * narrow a live security guard from "field signature" to "these two schema
	 * ids". That is a behaviour change, not a performance change.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 */
	private function registerObjectWriteGuards(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: PortalCreateOpenParentGuardListener::class
		);

	}//end registerObjectWriteGuards()

	/**
	 * Nextcloud main-dashboard widget (dashboard-iwidget-v1).
	 *
	 * A per-user "Decidesk" widget showing pending votes count + next meeting
	 * on the Nextcloud Hub, deep-linking into the app. Fail-soft, OR-scoped.
	 * Registered explicitly because its IL10N has to be obtained from IFactory
	 * for this app id, which the container cannot infer.
	 *
	 * The voting deadline reminder sweep (hourly job in appinfo/info.xml) and
	 * the dashboard widget's backing service are autowired.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	private function registerDashboardWidget(IRegistrationContext $context): void {
		$context->registerService(
			DecideskDashboardWidget::class,
			static function ($c): DecideskDashboardWidget {
				return new DecideskDashboardWidget(
					l10n: $c->get(IFactory::class)->get(Application::APP_ID),
					urlGenerator: $c->get(IURLGenerator::class),
					timeFactory: $c->get(ITimeFactory::class),
					widgetService: $c->get(DashboardWidgetService::class),
				);
			}
		);
		$context->registerDashboardWidget(DecideskDashboardWidget::class);

	}//end registerDashboardWidget()
}//end class
