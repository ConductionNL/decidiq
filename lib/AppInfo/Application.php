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
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\AppInfo;

use OCA\Decidesk\Dashboard\DecideskDashboardWidget;
use OCA\Decidesk\Event\DecisionRequestedEvent;
use OCA\Decidesk\Listener\DecisionRequestedListener;
use OCA\Decidesk\Listener\GovernanceRoleProjectionListener;
use OCA\Decidesk\Listener\MeetingFolderListener;
use OCA\Decidesk\Listener\PortalCreateOpenParentGuardListener;
use OCA\Decidesk\Listener\SubmissionDeadlineListener;
use OCA\Decidesk\Mcp\DecideskToolProvider;
use OCA\Decidesk\Search\DecideskSearchProvider;
use OCA\Decidesk\Service\DashboardWidgetService;
use OCA\Decidesk\Service\EIDASSignatureService;
use OCA\Decidesk\Service\IEIDASSignatureService;
use OCA\Decidesk\Service\ITranslationAdapter;
use OCA\Decidesk\Service\LogEIDASSignatureService;
use OCA\Decidesk\Service\LogTranslationAdapter;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Main application class for the Decidesk Nextcloud app.
 *
 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'decidesk';

    /**
     * Constructor for the Application class.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(appName: self::APP_ID);
    }//end __construct()

    /**
     * Register event listeners and services.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1
     */
    public function register(IRegistrationContext $context): void
    {
        // AppHost adoption (ADR-040 / ADR-022): re-point the mechanical
        // dashboard + observability + deep-link plumbing at the OpenRegister
        // AppHost generics, keeping decidesk's URLs unchanged. Decidesk's
        // domain-entangled Settings / Preferences / AdminSettings / repair /
        // SettingsService stay bespoke (see registerAppHostBoilerplate()).
        $this->registerAppHostBoilerplate(context: $context);

        // Decidesk's services, controllers and background jobs are resolved by
        // Nextcloud's autowiring container. `SimpleContainer::query()` resolves
        // an unregistered class through `resolve()` -> `buildClass()`, which
        // injects each constructor parameter by its declared type, and then
        // caches the built instance by registering it as a service. An explicit
        // `registerService()` closure that repeats
        // `<param>: $c->get(<declared type>::class)` for every parameter is
        // therefore exact boilerplate: it produces the same object graph with
        // the same per-container sharing. Only bindings that cannot be derived
        // from the constructor signature are declared here and in the
        // registerPhase*/registerNcPlatformIntegration helpers below, namely:
        // interface -> implementation bindings, named service aliases, event
        // listeners, and factories that pass a value the container cannot infer
        // (the app id, or an IL10N obtained from IFactory for this app).
        //
        // MinutesController deliberately takes no userId: it is resolved
        // per-request inside each action via
        // $this->userSession->getUser()?->getUID(), so that the shared instance
        // never caches a null uid from an early unauthenticated bootstrap.
        // @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1.
        // @spec openspec/specs/decision-management/spec.md.
        //
        // The event contract for delegated decisions: consumer apps dispatch
        // DecisionRequestedEvent (handled here -> createDecision) and listen for
        // DecisionConcludedEvent (emitted from DecisionLifecycleService).
        // In-process replacement for the broken IntegrationService::getLeaf path.
        // @spec openspec/changes/decidesk-decision-events/specs/decidesk-decision-events/spec.md.
        $context->registerEventListener(
            event: DecisionRequestedEvent::class,
            listener: DecisionRequestedListener::class
        );

        // P4-collaboration: services for collaboration, workspaces, email
        // linking, notifications, engagement, and motion co-authoring.
        //
        // TaskService / DelegationService were retired in
        // migrate-action-items-to-deck-leaf (ADR-022): action-item content lives
        // on the CalDAV VTODO ActionItem (ADR-002 source of truth) and the board
        // UI is provided by the Deck integration leaf via the ADR-019 registry.
        // @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-4.1.
        //
        // WorkspaceService was retired in migrate-workspaces-to-collectives-leaf
        // (ADR-022): faction/committee/task-group workspaces are now Nextcloud
        // Collectives bound to the governance-body OR object via the ADR-019
        // registry. The collectives leaf is declared in
        // lib/Settings/register.d/41-migrate-workspaces-to-collectives-leaf.json.
        // @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-4.1.
        //
        // TaskController / DelegationController retired alongside their services
        // (migrate-action-items-to-deck-leaf, ADR-022 / task-4.2).
        // WorkspaceController retired alongside WorkspaceService
        // (migrate-workspaces-to-collectives-leaf, ADR-022 / task-4.1).
        //
        // The MigrateCommentsToTalkLeaf / MigrateActionItemsToDeckLeaf repair
        // steps are registered via appinfo/info.xml <repair-steps>;
        // IRegistrationContext has no registerRepairStep() method, and their
        // constructor dependencies are autowired when Nextcloud instantiates
        // them.
        // @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.1.
        // @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1.
        // @spec openspec/specs/user-settings/spec.md.
        //
        // Register DecideskToolProvider as the MCP tool provider for the AI Chat Companion.
        // The alias key 'OCA\OpenRegister\Mcp\IMcpToolProvider::decidesk' is the format
        // that OR's McpToolsService enumerates to discover per-app providers (design D3).
        // The interface ships in openregister PR #1466 (ai-chat-companion-orchestrator).
        // @spec openspec/specs/mcp-tools/spec.md.
        $context->registerServiceAlias(
            'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::decidesk',
            DecideskToolProvider::class
        );

        // Board portal Phase 2 services (audit log, conflict of interest,
        // quorum verification and their controllers) are autowired.
        // @spec openspec/changes/board-meeting-resolutions/tasks.md.
        $this->registerPhase4EidasBindings(context: $context);
        $this->registerPhase6Bindings(context: $context);
        $this->registerNcPlatformIntegration(context: $context);

    }//end register()

    /**
     * AppHost boilerplate adoption (ADR-040 / ADR-022).
     *
     * Re-points the mechanical, fleet-standard plumbing at the OpenRegister
     * AppHost generics — keeping decidesk's existing URLs unchanged — while
     * leaving every domain-entangled class bespoke:
     *
     *   - `Controller\DashboardController`  -> `GenericDashboardController`
     *     (pure SPA/template host; identical to the generic).
     *   - `Controller\MetricsController`    -> `GenericMetricsController`
     *     (decidesk had NO metrics endpoint; this is an additive ADR-006
     *     compliance upgrade serving the manifest `observability` block).
     *   - `Controller\HealthController`     -> kept as a thin generic subclass
     *     (NOT aliased here) so it can reshape the engine result into the
     *     published REQ-API-004 body. Its engine dependencies are wired below.
     *   - the generic deep-link listener (manifest `deepLinks` driven) replaces
     *     the former hand-written `Listener\DeepLinkRegistrationListener`.
     *
     * Deliberately NOT adopted (kept bespoke — domain behaviour the generics
     * cannot express, per the "don't force" rule):
     *   - `Controller\SettingsController` + `Service\SettingsService`
     *     (decidesk-register import, publication-config CRUD).
     *   - `Settings\AdminSettings` (domain initial state: publication config,
     *     transcript-retention defaults) and `Sections\SettingsSection`,
     *     `Settings\PersonalSettings`, `Sections\PersonalSection`.
     *   - `Repair\InitializeSettings` (voter_token_secret seeding + OR
     *     configuration import).
     *   - `Controller\PreferencesController` — the AppHost has no
     *     `GenericPreferencesController` in OpenRegister development, so the
     *     bespoke per-user preferences controller is retained as-is.
     *
     * Lazy by construction: every binding is a `registerService` closure, so a
     * disabled OpenRegister never loads an AppHost class at bootstrap (the
     * closure only resolves when a route is dispatched), matching the AppHost
     * fatal-free invariant.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @spec openspec/changes/adopt-apphost/tasks.md#task-2
     * @spec openspec/specs/apphost-adoption/spec.md
     *
     * @return void
     */
    private function registerAppHostBoilerplate(IRegistrationContext $context): void
    {
        // The dashboard / metrics / health route targets are thin decidesk
        // subclasses of the AppHost generics
        // (`Controller\DashboardController`, `Controller\MetricsController`,
        // `Controller\HealthController`) — concrete classes so the route
        // targets stay reachable (gate-5 / gate-14). Their constructor
        // dependencies (the engine's ManifestLoader / MetricsEngine /
        // HealthCheckExecutor, all OpenRegister services) are resolved by the
        // DI container at dispatch time, so no explicit binding is needed here
        // and a disabled OpenRegister never loads an AppHost class at bootstrap.
        //
        // Generic, manifest-driven deep-link listener replaces the former
        // hand-written listener. Patterns now live in the manifest `deepLinks`
        // block. Fires only when OpenRegister dispatches the event.
        $context->registerService(
            'OCA\\OpenRegister\\AppHost\\Listener\\GenericDeepLinkRegistrationListener',
            static function ($c): object {
                $class = 'OCA\\OpenRegister\\AppHost\\Listener\\GenericDeepLinkRegistrationListener';
                return new $class(
                    appId: self::APP_ID,
                    appManager: $c->get(\OCP\App\IAppManager::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );
        // The listener is an OpenRegister AppHost class that only exists when
        // openregister is installed, so PHPStan cannot verify it is a
        // class-string<IEventListener>. It is registered as a service just above and
        // is only instantiated when OpenRegister dispatches the event.
        $listenerClass = 'OCA\\OpenRegister\\AppHost\\Listener\\GenericDeepLinkRegistrationListener';
        // @phpstan-ignore-next-line
        $context->registerEventListener(event: DeepLinkRegistrationEvent::class, listener: $listenerClass);

    }//end registerAppHostBoilerplate()

    /**
     * NC platform integration bindings: Activity publisher, unified search,
     * meeting Files folders, and the voting deadline reminder.
     *
     * The Activity provider/filter/setting classes are declared in
     * appinfo/info.xml <activity> (the Activity app resolves them from
     * there); only the publisher and the listener wiring live here.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return void
     */
    private function registerNcPlatformIntegration(IRegistrationContext $context): void
    {
        // The meeting-workspace bindings (Files folder tree, recurring series,
        // meeting document package), the governance role -> OpenRegister RBAC
        // scope projection and the meeting-transcription / AI draft-minutes
        // bindings are all autowired; only the listeners' event subscriptions
        // (see boot()) and the factory-built bindings below need declaring.
        // @spec openspec/specs/nextcloud-integration/spec.md.
        // @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md.
        $this->registerActivityAndSearch(context: $context);
        $this->registerObjectWriteGuards(context: $context);
        $this->registerRemindersAndDashboard(context: $context);

    }//end registerNcPlatformIntegration()

    /**
     * Activity publishing and unified search over decisions/meetings/resolutions.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return void
     */
    private function registerActivityAndSearch(IRegistrationContext $context): void
    {
        // The fail-soft Activity publisher (called from the governance services)
        // is autowired.
        //
        // Unified search over decisions / meetings / resolutions (OR RBAC
        // scoped). Registered explicitly because its IL10N has to be obtained
        // from IFactory for this app id, which the container cannot infer.
        $context->registerService(
            DecideskSearchProvider::class,
            static function ($c): DecideskSearchProvider {
                return new DecideskSearchProvider(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    urlGenerator: $c->get(\OCP\IURLGenerator::class),
                    l10n: $c->get(\OCP\L10N\IFactory::class)->get(self::APP_ID),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );
        $context->registerSearchProvider(DecideskSearchProvider::class);

    }//end registerActivityAndSearch()

    /**
     * Object-write guard listeners: the submission deadline gate and the portal
     * citizen create-actions open-parent guard.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return void
     */
    private function registerObjectWriteGuards(IRegistrationContext $context): void
    {
        // Submission deadline gate (motion-amendment spec): pre-save hook that
        // rejects motion/amendment creations after the linked meeting's
        // submissionDeadline (OpenRegister converts the stopped event into
        // HTTP 422 at the object API). It declares its schema interest up front
        // and is therefore subscribed from boot(), not here — see boot().
        //
        // Portal citizen create-actions open-parent guard
        // (portal-citizen-create-actions, REQ-DKPCA-001/002): rejects a
        // consultation-reaction/budget-proposal create whose parent
        // consultation/budget round is not open, closing the gap left by
        // portaliq's shared create receiver (which stamps scope + defaults
        // but does not enforce a declared parentConstraint).
        //
        // Deliberately NOT narrowed with ObjectEventSubscription, unlike the
        // three listeners above. This is the one object listener in the app
        // that does not identify its rows by schema slug at all: its class
        // docblock records that on `ObjectCreatingEvent` no slug is reachable,
        // so it identifies its two schemas by their REQUIRED field signature
        // instead, and therefore also fires on any lookalike row — described
        // there as a deliberately stricter, defence-in-depth posture. Declaring
        // `['consultation-reaction', 'budget-proposal']` would quietly narrow
        // a live security guard from "field signature" to "these two schema
        // ids". That is a behaviour change, not a performance change, and does
        // not belong in this commit.
        $context->registerEventListener(
            event: ObjectCreatingEvent::class,
            listener: PortalCreateOpenParentGuardListener::class
        );

    }//end registerObjectWriteGuards()

    /**
     * Voting deadline reminder sweep and the Nextcloud main-dashboard widget.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return void
     */
    private function registerRemindersAndDashboard(IRegistrationContext $context): void
    {
        // The voting deadline reminder sweep (hourly job in appinfo/info.xml)
        // and the dashboard widget's backing service are autowired.
        //
        // Nextcloud main-dashboard widget (dashboard-iwidget-v1): a per-user
        // "Decidesk" widget showing pending votes count + next meeting on the
        // Nextcloud Hub, deep-linking into the app. Fail-soft, OR-scoped.
        // Registered explicitly because its IL10N has to be obtained from
        // IFactory for this app id, which the container cannot infer.
        // @spec openspec/specs/dashboard/spec.md.
        $context->registerService(
            DecideskDashboardWidget::class,
            static function ($c): DecideskDashboardWidget {
                return new DecideskDashboardWidget(
                    l10n: $c->get(\OCP\L10N\IFactory::class)->get(self::APP_ID),
                    urlGenerator: $c->get(\OCP\IURLGenerator::class),
                    timeFactory: $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                    widgetService: $c->get(DashboardWidgetService::class),
                );
            }
        );
        $context->registerDashboardWidget(DecideskDashboardWidget::class);

    }//end registerRemindersAndDashboard()

    /**
     * Register an object-lifecycle listener that declares its interest up front.
     *
     * OpenRegister's `ObjectEventSubscription` records the register/schema slugs
     * a listener reacts to and routes dispatches through a single shared proxy,
     * so an uninterested listener is neither constructed nor invoked. When
     * OpenRegister is absent — Decidesk carries no hard dependency on it — this
     * degrades to the plain global registration it replaced, which is exactly
     * the behaviour every listener had before.
     *
     * MUST be called from boot(), never from register(). Nextcloud enables each
     * app's own autoloader immediately before calling that app's register(), so
     * during register() OpenRegister's classes are only autoloadable to apps
     * that happen to be registered after it — the class_exists() guard below
     * would then resolve differently purely by app load order and silently fall
     * back to an unfiltered registration. boot() runs only after every app's
     * register() has completed, so the guard is order-independent there.
     *
     * @param IEventDispatcher       $dispatcher The live event dispatcher.
     * @param string                 $event      OpenRegister event class name
     * @param string                 $listener   Listener class name
     * @param array<int,string>|null $registers  Register slugs the listener reacts to, or null for all
     * @param array<int,string>|null $schemas    Schema slugs the listener reacts to, or null for all
     *
     * @return void
     */
    private function registerFilteredObjectListener(
        IEventDispatcher $dispatcher,
        string $event,
        string $listener,
        ?array $registers,
        ?array $schemas
    ): void {
        $subscription = '\\OCA\\OpenRegister\\Event\\ObjectEventSubscription';
        if (class_exists($subscription) === true) {
            $subscription::subscribe(
                dispatcher: $dispatcher,
                event: $event,
                listener: $listener,
                registers: $registers,
                schemas: $schemas
            );
            return;
        }

        // Loud on purpose. This fallback is correct but UNFILTERED, and while it
        // was silent it was indistinguishable from a working narrowing.
        \OCP\Server::get(\Psr\Log\LoggerInterface::class)->warning(
            'OpenRegister ObjectEventSubscription unavailable: '.$listener
            .' fell back to an UNFILTERED registration for '.$event
            .' and will be invoked on every object write instance-wide.',
            ['app' => self::APP_ID]
        );

        $dispatcher->addServiceListener($event, $listener);

    }//end registerFilteredObjectListener()

    /**
     * Phase 4 — eIDAS QES integration bindings.
     *
     * The IEIDASSignatureService binding picks the dormant
     * {@see LogEIDASSignatureService} fallback when
     * openconnector is absent or its `eidas-qes` Source is not configured;
     * otherwise the openconnector-delegating
     * {@see EIDASSignatureService} is used.
     *
     * @param IRegistrationContext $context Registration context
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.1
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-3.3
     *
     * @return void
     */
    private function registerPhase4EidasBindings(IRegistrationContext $context): void
    {
        // Both implementations are individually constructable so tests / DI
        // overrides can pick either side without going through the resolver;
        // both are autowired from their constructor signatures.
        //
        // Resolve the IEIDASSignatureService interface at request time. If
        // openconnector's CallService binding is registered, prefer the
        // delegating implementation; otherwise the dormant LogEIDASSignatureService.
        $context->registerService(
            IEIDASSignatureService::class,
            static function ($c): IEIDASSignatureService {
                $hasOpenconnector = false;
                try {
                    $c->get('OCA\\OpenConnector\\Service\\CallService');
                    $hasOpenconnector = true;
                } catch (\Throwable $e) {
                    $hasOpenconnector = false;
                }

                if ($hasOpenconnector === true) {
                    return $c->get(EIDASSignatureService::class);
                }

                return $c->get(LogEIDASSignatureService::class);
            }
        );

        // QesGuard and EIDASSignatureController take IEIDASSignatureService by
        // its interface type, so the resolver above is what the container
        // injects into them; both are autowired.
        //
        // GovernanceScopeGuard consumes the OpenRegister-projected per-body
        // signatory/chair scopes (consume-or-rbac-authorization). It replaces
        // the retired app-local MinutesAuthorizationService, and is autowired.
    }//end registerPhase4EidasBindings()

    /**
     * Phase 6 — the one binding the container cannot infer: the dormant default
     * translation adapter.
     *
     * Phase 5 (proxy votes, written resolutions, governance reporting) and the
     * rest of Phase 6 (regulator export, multilingual reconciliation, board
     * self-evaluation) are autowired from their constructor signatures.
     *
     * @param IRegistrationContext $context Registration context
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.2
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.4
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
     *
     * @return void
     */
    private function registerPhase6Bindings(IRegistrationContext $context): void
    {
        // Dormant default translation adapter — rebind in production to delegate
        // to openconnector's translation source service.
        $context->registerService(
            ITranslationAdapter::class,
            static function ($c): ITranslationAdapter {
                return new LogTranslationAdapter(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

    }//end registerPhase6Bindings()

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
    public function boot(IBootContext $context): void
    {
        // C2: email-voting is disabled — MailReplyHandler is not registered.
        // The background job remains in place for future re-enablement but must
        // not be scheduled until the feature is audited and enabled deliberately.
        //
        // ADR-019 / ADR-022: load the tiny global integration-leaf bootstrap on
        // EVERY Nextcloud page so decidesk's "Besluitvorming" decisions leaf
        // registers on the shared OpenRegister integration registry and surfaces
        // as a sidebar tab + detail-page widget on host objects (e.g. a procest
        // case) without the full decidesk app bundle being present.
        \OCP\Util::addInitScript(Application::APP_ID, 'decidesk-integration-init');

        $dispatcher = $context->getServerContainer()->get(IEventDispatcher::class);

        // Declares its schema interest at subscription time instead of
        // re-deriving it on every dispatch: the handler's first guard is
        // `resolveSchemaSlug(...) !== MeetingFolderListener::SCHEMA_MEETING`
        // (= the `meeting` schema slug). Registered globally it was
        // constructed and invoked on every object create on the instance —
        // a larpingapp character create reached `handle()` and bailed at that
        // guard. No register is declared: schema-only is exactly the guard
        // the handler already applies, and stays correct if a Decidesk
        // deployment ever splits meetings across registers.
        $this->registerFilteredObjectListener(
            dispatcher: $dispatcher,
            event: ObjectCreatedEvent::class,
            listener: \OCA\Decidesk\Listener\MeetingFolderListener::class,
            registers: null,
            schemas: ['meeting']
        );

        // Same narrowing as MeetingFolderListener above. The declared slug list
        // is `GovernanceRoleProjectionListener::ROSTER_SCHEMAS` verbatim — the
        // handler bails on
        // `in_array($slug, self::ROSTER_SCHEMAS, true) === false` — so the
        // declaration cannot be narrower than the guard it fronts.
        $this->registerFilteredObjectListener(
            dispatcher: $dispatcher,
            event: ObjectCreatedEvent::class,
            listener: \OCA\Decidesk\Listener\GovernanceRoleProjectionListener::class,
            registers: null,
            schemas: ['participant', 'membership']
        );
        $this->registerFilteredObjectListener(
            dispatcher: $dispatcher,
            event: ObjectUpdatedEvent::class,
            listener: \OCA\Decidesk\Listener\GovernanceRoleProjectionListener::class,
            registers: null,
            schemas: ['participant', 'membership']
        );
        $this->registerFilteredObjectListener(
            dispatcher: $dispatcher,
            event: \OCA\OpenRegister\Event\ObjectDeletedEvent::class,
            listener: \OCA\Decidesk\Listener\GovernanceRoleProjectionListener::class,
            registers: null,
            schemas: ['participant', 'membership']
        );

        // Submission deadline gate (motion-amendment spec). Declared interest is
        // the handler's own literal guard verbatim —
        // `in_array($slug, ['motion', 'amendment'], true) === false` — so the
        // declaration cannot be narrower than the guard it fronts.
        $this->registerFilteredObjectListener(
            dispatcher: $dispatcher,
            event: ObjectCreatingEvent::class,
            listener: \OCA\Decidesk\Listener\SubmissionDeadlineListener::class,
            registers: null,
            schemas: ['motion', 'amendment']
        );
    }//end boot()
}//end class
