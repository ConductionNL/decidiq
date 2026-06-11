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

use OCA\Decidesk\BackgroundJob\OverdueActionItemsJob;
use OCA\Decidesk\Mcp\DecideskToolProvider;
use OCA\Decidesk\Controller\AnalyticsController;
use OCA\Decidesk\Controller\DecisionController;
use OCA\Decidesk\Controller\EngagementController;
use OCA\Decidesk\Controller\LiveMeetingController;
use OCA\Decidesk\Controller\MinutesController;
use OCA\Decidesk\Controller\MotionController;
use OCA\Decidesk\Controller\MotionCoauthorController;
use OCA\Decidesk\Controller\NotificationPreferenceController;
use OCA\Decidesk\Controller\ProjectionController;
use OCA\Decidesk\Controller\VotingBehaviourController;
use OCA\Decidesk\Controller\VotingController;
use OCA\Decidesk\Controller\WorkspaceController;
use OCA\Decidesk\Listener\DeepLinkRegistrationListener;
use OCA\Decidesk\Migration\MigrateActionItemsToDeckLeaf;
use OCA\Decidesk\Migration\MigrateCommentsToTalkLeaf;
use OCA\Decidesk\Service\ActionItemAnalyticsService;
use OCA\Decidesk\Service\ActionItemExtractionService;
use OCA\Decidesk\Service\ALVMinutesService;
use OCA\Decidesk\Service\DecisionNotificationService;
use OCA\Decidesk\Service\EmailReferenceExtractor;
use OCA\Decidesk\Service\EngagementService;
use OCA\Decidesk\Service\LiveDecisionService;
use OCA\Decidesk\Service\MinutesGenerationService;
use OCA\Decidesk\Service\MinutesService;
use OCA\Decidesk\Service\MotionCoauthorService;
use OCA\Decidesk\Service\MotionService;
use OCA\Decidesk\Service\NotificationPreferenceService;
use OCA\Decidesk\Service\OriPublicationService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\Decidesk\Service\VotingBehaviourService;
use OCA\Decidesk\Service\VotingService;
use OCA\Decidesk\Service\WorkspaceService;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

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
        // Register deep link patterns with OpenRegister's unified search provider.
        // Only fires when OpenRegister is installed and dispatches the event.
        $context->registerEventListener(
            event: DeepLinkRegistrationEvent::class,
            listener: DeepLinkRegistrationListener::class
        );

        // Register MinutesGenerationService for DI.
        // @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1.
        $context->registerService(
                MinutesGenerationService::class,
                static function ($c): MinutesGenerationService {
                    return new MinutesGenerationService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        // Register MinutesController for DI.
        // userId is NOT injected here — it must be resolved per-request inside each
        // action method via $this->userSession->getUser()?->getUID() to avoid the
        // DI singleton caching a null uid from an early unauthenticated bootstrap.
        // @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1.
        $context->registerService(
                MinutesController::class,
                static function ($c): MinutesController {
                    return new MinutesController(
                    request: $c->get(\OCP\IRequest::class),
                    minutesGenerationService: $c->get(MinutesGenerationService::class),
                    alvMinutesService: $c->get(ALVMinutesService::class),
                    extractionService: $c->get(ActionItemExtractionService::class),
                    minutesService: $c->get(MinutesService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                    objectService: $c->get(ObjectService::class),
                    participantResolver: $c->get(ParticipantResolver::class),
                    );
                }
                );

        // Register DecisionController for DI.
        // Explicit registration matches the MinutesController pattern and ensures
        // reliable resolution in all Nextcloud environments (≥28).
        // @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2.
        $context->registerService(
                DecisionController::class,
                static function ($c): DecisionController {
                    return new DecisionController(
                    request: $c->get(\OCP\IRequest::class),
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        // Register OverdueActionItemsJob for DI.
        // @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-2.
        $context->registerService(
                OverdueActionItemsJob::class,
                static function ($c): OverdueActionItemsJob {
                    return new OverdueActionItemsJob(
                    time: $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        // Register ActionItemAnalyticsService for DI.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.4.
        $context->registerService(
                ActionItemAnalyticsService::class,
                static function ($c): ActionItemAnalyticsService {
                    return new ActionItemAnalyticsService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        // Register OriPublicationService for DI.
        // @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.
        $context->registerService(
                OriPublicationService::class,
                static function ($c): OriPublicationService {
                    return new OriPublicationService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    appConfig: $c->get(\OCP\IAppConfig::class),
                    clientService: $c->get(\OCP\Http\Client\IClientService::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        // Register VotingBehaviourService for DI.
        // @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1.
        $context->registerService(
                VotingBehaviourService::class,
                static function ($c): VotingBehaviourService {
                    return new VotingBehaviourService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    );
                }
                );

        // Register MotionService for DI.
        // @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.4.
        $context->registerService(
                MotionService::class,
                static function ($c): MotionService {
                    return new MotionService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    userManager: $c->get(\OCP\IUserManager::class),
                    );
                }
                );

        // Register AnalyticsController for DI.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.4.
        $context->registerService(
                AnalyticsController::class,
                static function ($c): AnalyticsController {
                    return new AnalyticsController(
                    request: $c->get(\OCP\IRequest::class),
                    analyticsService: $c->get(ActionItemAnalyticsService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    );
                }
                );

        // Register VotingService for DI.
        // @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.4.
        $context->registerService(
                VotingService::class,
                static function ($c): VotingService {
                    return new VotingService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    oriPublicationService: $c->get(OriPublicationService::class),
                    motionService: $c->get(MotionService::class),
                    participantResolver: $c->get(ParticipantResolver::class),
                    );
                }
                );

        // Register VotingBehaviourController for DI.
        // @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-1.
        $context->registerService(
                VotingBehaviourController::class,
                static function ($c): VotingBehaviourController {
                    return new VotingBehaviourController(
                    request: $c->get(\OCP\IRequest::class),
                    behaviourService: $c->get(VotingBehaviourService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                    objectService: $c->get(\OCA\OpenRegister\Service\ObjectService::class),
                    );
                }
                );

        // Register MotionController for DI.
        // @spec openspec/changes/p2-motion-and-voting/tasks.md#task-1.4.
        $context->registerService(
                MotionController::class,
                static function ($c): MotionController {
                    return new MotionController(
                    request: $c->get(\OCP\IRequest::class),
                    motionService: $c->get(MotionService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                    appConfig: $c->get(\OCP\IAppConfig::class),
                    participantResolver: $c->get(ParticipantResolver::class),
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    );
                }
                );

        // Register LiveDecisionService for DI.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.4.
        $context->registerService(
                LiveDecisionService::class,
                static function ($c): LiveDecisionService {
                    return new LiveDecisionService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        // Register LiveMeetingController for DI.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-2.4.
        $context->registerService(
                LiveMeetingController::class,
                static function ($c): LiveMeetingController {
                    return new LiveMeetingController(
                    request: $c->get(\OCP\IRequest::class),
                    liveDecisionService: $c->get(LiveDecisionService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                    participantResolver: $c->get(ParticipantResolver::class),
                    );
                }
                );

        // Register ALVMinutesService for DI.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-3.4.
        $context->registerService(
                ALVMinutesService::class,
                static function ($c): ALVMinutesService {
                    return new ALVMinutesService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        // Register VotingController for DI.
        // @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.4.
        $context->registerService(
                VotingController::class,
                static function ($c): VotingController {
                    return new VotingController(
                    request: $c->get(\OCP\IRequest::class),
                    votingService: $c->get(VotingService::class),
                    oriPublicationService: $c->get(OriPublicationService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                    appConfig: $c->get(\OCP\IAppConfig::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    participantResolver: $c->get(ParticipantResolver::class),
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    );
                }
                );

        // Register ActionItemExtractionService for DI.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-4.4.
        $context->registerService(
                ActionItemExtractionService::class,
                static function ($c): ActionItemExtractionService {
                    return new ActionItemExtractionService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        // Register DecisionNotificationService for DI.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5.3.
        $context->registerService(
                DecisionNotificationService::class,
                static function ($c): DecisionNotificationService {
                    return new DecisionNotificationService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        // Register MinutesService for DI.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6.3.
        $context->registerService(
                MinutesService::class,
                static function ($c): MinutesService {
                    return new MinutesService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        // Register ProjectionController for DI (public page, no auth required).
        // @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-2.
        $context->registerService(
                ProjectionController::class,
                static function ($c): ProjectionController {
                    return new ProjectionController(
                    request: $c->get(\OCP\IRequest::class),
                    votingService: $c->get('OCA\Decidesk\Service\VotingService'),
                    );
                }
                );

        // P4-collaboration: services for collaboration, workspaces, email
        // linking, notifications, engagement, and motion co-authoring.
        //
        // TaskService / DelegationService were retired in
        // migrate-action-items-to-deck-leaf (ADR-022): action-item content lives
        // on the CalDAV VTODO ActionItem (ADR-002 source of truth) and the board
        // UI is provided by the Deck integration leaf via the ADR-019 registry.
        // @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-4.1.
        $context->registerService(
            WorkspaceService::class,
            static function ($c): WorkspaceService {
                return new WorkspaceService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            EmailReferenceExtractor::class,
            static function (): EmailReferenceExtractor {
                return new EmailReferenceExtractor();
            }
        );

        $context->registerService(
            NotificationPreferenceService::class,
            static function ($c): NotificationPreferenceService {
                return new NotificationPreferenceService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            EngagementService::class,
            static function ($c): EngagementService {
                return new EngagementService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            MotionCoauthorService::class,
            static function ($c): MotionCoauthorService {
                return new MotionCoauthorService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        // TaskController / DelegationController retired alongside their services
        // (migrate-action-items-to-deck-leaf, ADR-022 / task-4.2).
        $context->registerService(
            WorkspaceController::class,
            static function ($c): WorkspaceController {
                return new WorkspaceController(
                    request: $c->get(\OCP\IRequest::class),
                    workspaceService: $c->get(WorkspaceService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                );
            }
        );

        $context->registerService(
            NotificationPreferenceController::class,
            static function ($c): NotificationPreferenceController {
                return new NotificationPreferenceController(
                    request: $c->get(\OCP\IRequest::class),
                    preferenceService: $c->get(NotificationPreferenceService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                );
            }
        );

        $context->registerService(
            EngagementController::class,
            static function ($c): EngagementController {
                return new EngagementController(
                    request: $c->get(\OCP\IRequest::class),
                    engagementService: $c->get(EngagementService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                );
            }
        );

        $context->registerService(
            MotionCoauthorController::class,
            static function ($c): MotionCoauthorController {
                return new MotionCoauthorController(
                    request: $c->get(\OCP\IRequest::class),
                    coauthorService: $c->get(MotionCoauthorService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                );
            }
        );

        // Register MigrateCommentsToTalkLeaf DI service. The repair step itself is
        // registered via appinfo/info.xml <repair-steps>; IRegistrationContext has no
        // registerRepairStep() method, so the service registration here only makes the
        // step's constructor dependencies resolvable when Nextcloud instantiates it.
        // @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.1.
        $context->registerService(
            MigrateCommentsToTalkLeaf::class,
            static function ($c): MigrateCommentsToTalkLeaf {
                return new MigrateCommentsToTalkLeaf(
                    settingsService: $c->get(\OCA\Decidesk\Service\SettingsService::class),
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    appManager: $c->get(\OCP\App\IAppManager::class),
                );
            }
        );

        // Register MigrateActionItemsToDeckLeaf DI service. The repair step itself is
        // registered via appinfo/info.xml <repair-steps>; IRegistrationContext has no
        // registerRepairStep() method, so the service registration here only makes the
        // step's constructor dependencies resolvable when Nextcloud instantiates it.
        // @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1.
        $context->registerService(
            MigrateActionItemsToDeckLeaf::class,
            static function ($c): MigrateActionItemsToDeckLeaf {
                return new MigrateActionItemsToDeckLeaf(
                    settingsService: $c->get(\OCA\Decidesk\Service\SettingsService::class),
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        // Register DecideskToolProvider as the MCP tool provider for the AI Chat Companion.
        // The alias key 'OCA\OpenRegister\Mcp\IMcpToolProvider::decidesk' is the format
        // that OR's McpToolsService enumerates to discover per-app providers (design D3).
        // The interface ships in openregister PR #1466 (ai-chat-companion-orchestrator).
        // @spec openspec/changes/decidesk-mcp-tools/specs/mcp-tools/spec.md#REQ-DMCP-001.
        $context->registerServiceAlias(
            'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::decidesk',
            DecideskToolProvider::class
        );

        // Board portal Phase 2 services.
        // @spec openspec/changes/board-meeting-resolutions/tasks.md.
        $context->registerService(
            \OCA\Decidesk\Service\AuditLogService::class,
            static function ($c): \OCA\Decidesk\Service\AuditLogService {
                return new \OCA\Decidesk\Service\AuditLogService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Service\ConflictOfInterestService::class,
            static function ($c): \OCA\Decidesk\Service\ConflictOfInterestService {
                return new \OCA\Decidesk\Service\ConflictOfInterestService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    auditLogService: $c->get(\OCA\Decidesk\Service\AuditLogService::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Service\BoardMaterialAuthorizationService::class,
            static function ($c): \OCA\Decidesk\Service\BoardMaterialAuthorizationService {
                return new \OCA\Decidesk\Service\BoardMaterialAuthorizationService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    auditLogService: $c->get(\OCA\Decidesk\Service\AuditLogService::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Service\QuorumVerificationService::class,
            static function ($c): \OCA\Decidesk\Service\QuorumVerificationService {
                return new \OCA\Decidesk\Service\QuorumVerificationService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Service\BoardService::class,
            static function ($c): \OCA\Decidesk\Service\BoardService {
                return new \OCA\Decidesk\Service\BoardService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Service\BoardMemberService::class,
            static function ($c): \OCA\Decidesk\Service\BoardMemberService {
                return new \OCA\Decidesk\Service\BoardMemberService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Service\BoardMeetingService::class,
            static function ($c): \OCA\Decidesk\Service\BoardMeetingService {
                return new \OCA\Decidesk\Service\BoardMeetingService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    auditLogService: $c->get(\OCA\Decidesk\Service\AuditLogService::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Lifecycle\ResolutionLifecycleGuard::class,
            static function ($c): \OCA\Decidesk\Lifecycle\ResolutionLifecycleGuard {
                return new \OCA\Decidesk\Lifecycle\ResolutionLifecycleGuard(
                    quorumService: $c->get(\OCA\Decidesk\Service\QuorumVerificationService::class),
                    conflictService: $c->get(\OCA\Decidesk\Service\ConflictOfInterestService::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Service\ResolutionService::class,
            static function ($c): \OCA\Decidesk\Service\ResolutionService {
                return new \OCA\Decidesk\Service\ResolutionService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    guard: $c->get(\OCA\Decidesk\Lifecycle\ResolutionLifecycleGuard::class),
                    auditLogService: $c->get(\OCA\Decidesk\Service\AuditLogService::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Service\BoardVoteService::class,
            static function ($c): \OCA\Decidesk\Service\BoardVoteService {
                return new \OCA\Decidesk\Service\BoardVoteService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    guard: $c->get(\OCA\Decidesk\Lifecycle\ResolutionLifecycleGuard::class),
                    auditLogService: $c->get(\OCA\Decidesk\Service\AuditLogService::class),
                );
            }
        );

        // Board portal Phase 3 controllers.
        $context->registerService(
            \OCA\Decidesk\Controller\BoardController::class,
            static function ($c): \OCA\Decidesk\Controller\BoardController {
                return new \OCA\Decidesk\Controller\BoardController(
                    request: $c->get(\OCP\IRequest::class),
                    boardService: $c->get(\OCA\Decidesk\Service\BoardService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Controller\BoardMemberController::class,
            static function ($c): \OCA\Decidesk\Controller\BoardMemberController {
                return new \OCA\Decidesk\Controller\BoardMemberController(
                    request: $c->get(\OCP\IRequest::class),
                    memberService: $c->get(\OCA\Decidesk\Service\BoardMemberService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Controller\BoardMeetingController::class,
            static function ($c): \OCA\Decidesk\Controller\BoardMeetingController {
                return new \OCA\Decidesk\Controller\BoardMeetingController(
                    request: $c->get(\OCP\IRequest::class),
                    meetingService: $c->get(\OCA\Decidesk\Service\BoardMeetingService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Controller\ResolutionController::class,
            static function ($c): \OCA\Decidesk\Controller\ResolutionController {
                return new \OCA\Decidesk\Controller\ResolutionController(
                    request: $c->get(\OCP\IRequest::class),
                    resolutionService: $c->get(\OCA\Decidesk\Service\ResolutionService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Controller\BoardVoteController::class,
            static function ($c): \OCA\Decidesk\Controller\BoardVoteController {
                return new \OCA\Decidesk\Controller\BoardVoteController(
                    request: $c->get(\OCP\IRequest::class),
                    voteService: $c->get(\OCA\Decidesk\Service\BoardVoteService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Controller\BoardMaterialController::class,
            static function ($c): \OCA\Decidesk\Controller\BoardMaterialController {
                return new \OCA\Decidesk\Controller\BoardMaterialController(
                    request: $c->get(\OCP\IRequest::class),
                    authService: $c->get(\OCA\Decidesk\Service\BoardMaterialAuthorizationService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Controller\ConflictOfInterestController::class,
            static function ($c): \OCA\Decidesk\Controller\ConflictOfInterestController {
                return new \OCA\Decidesk\Controller\ConflictOfInterestController(
                    request: $c->get(\OCP\IRequest::class),
                    conflictService: $c->get(\OCA\Decidesk\Service\ConflictOfInterestService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Controller\AuditLogController::class,
            static function ($c): \OCA\Decidesk\Controller\AuditLogController {
                return new \OCA\Decidesk\Controller\AuditLogController(
                    request: $c->get(\OCP\IRequest::class),
                    auditLogService: $c->get(\OCA\Decidesk\Service\AuditLogService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                );
            }
        );

        $this->registerPhase4EidasBindings(context: $context);
        $this->registerPhase5Bindings(context: $context);
        $this->registerPhase6Bindings(context: $context);

    }//end register()

    /**
     * Phase 4 — eIDAS QES integration bindings.
     *
     * The IEIDASSignatureService binding picks the dormant
     * {@see \OCA\Decidesk\Service\LogEIDASSignatureService} fallback when
     * openconnector is absent or its `eidas-qes` Source is not configured;
     * otherwise the openconnector-delegating
     * {@see \OCA\Decidesk\Service\EIDASSignatureService} is used.
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
        // overrides can pick either side without going through the resolver.
        $context->registerService(
            \OCA\Decidesk\Service\EIDASSignatureService::class,
            static function ($c): \OCA\Decidesk\Service\EIDASSignatureService {
                return new \OCA\Decidesk\Service\EIDASSignatureService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    auditLogService: $c->get(\OCA\Decidesk\Service\AuditLogService::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Service\LogEIDASSignatureService::class,
            static function ($c): \OCA\Decidesk\Service\LogEIDASSignatureService {
                return new \OCA\Decidesk\Service\LogEIDASSignatureService(
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    auditLogService: $c->get(\OCA\Decidesk\Service\AuditLogService::class),
                );
            }
        );

        // Resolve the IEIDASSignatureService interface at request time. If
        // openconnector's CallService binding is registered, prefer the
        // delegating implementation; otherwise the dormant LogEIDASSignatureService.
        $context->registerService(
            \OCA\Decidesk\Service\IEIDASSignatureService::class,
            static function ($c): \OCA\Decidesk\Service\IEIDASSignatureService {
                $hasOpenconnector = false;
                try {
                    $c->get('OCA\\OpenConnector\\Service\\CallService');
                    $hasOpenconnector = true;
                } catch (\Throwable $e) {
                    $hasOpenconnector = false;
                }

                if ($hasOpenconnector === true) {
                    return $c->get(\OCA\Decidesk\Service\EIDASSignatureService::class);
                }

                return $c->get(\OCA\Decidesk\Service\LogEIDASSignatureService::class);
            }
        );

        $context->registerService(
            \OCA\Decidesk\Lifecycle\QesGuard::class,
            static function ($c): \OCA\Decidesk\Lifecycle\QesGuard {
                return new \OCA\Decidesk\Lifecycle\QesGuard(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    signatureService: $c->get(\OCA\Decidesk\Service\IEIDASSignatureService::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Controller\EIDASSignatureController::class,
            static function ($c): \OCA\Decidesk\Controller\EIDASSignatureController {
                return new \OCA\Decidesk\Controller\EIDASSignatureController(
                    request: $c->get(\OCP\IRequest::class),
                    signatureService: $c->get(\OCA\Decidesk\Service\IEIDASSignatureService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                );
            }
        );

    }//end registerPhase4EidasBindings()

    /**
     * Phase 5 — Proxy votes, written resolutions, governance reporting bindings.
     *
     * @param IRegistrationContext $context Registration context
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.1
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.2
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.3
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-5.4
     *
     * @return void
     */
    private function registerPhase5Bindings(IRegistrationContext $context): void
    {
        $context->registerService(
            \OCA\Decidesk\Service\ProxyVoteService::class,
            static function ($c): \OCA\Decidesk\Service\ProxyVoteService {
                return new \OCA\Decidesk\Service\ProxyVoteService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    auditLogService: $c->get(\OCA\Decidesk\Service\AuditLogService::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Controller\ProxyVoteController::class,
            static function ($c): \OCA\Decidesk\Controller\ProxyVoteController {
                return new \OCA\Decidesk\Controller\ProxyVoteController(
                    request: $c->get(\OCP\IRequest::class),
                    proxyService: $c->get(\OCA\Decidesk\Service\ProxyVoteService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Service\WrittenResolutionService::class,
            static function ($c): \OCA\Decidesk\Service\WrittenResolutionService {
                return new \OCA\Decidesk\Service\WrittenResolutionService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    signatureService: $c->get(\OCA\Decidesk\Service\IEIDASSignatureService::class),
                    auditLogService: $c->get(\OCA\Decidesk\Service\AuditLogService::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Service\GovernanceReportingService::class,
            static function ($c): \OCA\Decidesk\Service\GovernanceReportingService {
                return new \OCA\Decidesk\Service\GovernanceReportingService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Controller\GovernanceReportController::class,
            static function ($c): \OCA\Decidesk\Controller\GovernanceReportController {
                return new \OCA\Decidesk\Controller\GovernanceReportController(
                    request: $c->get(\OCP\IRequest::class),
                    reportingService: $c->get(\OCA\Decidesk\Service\GovernanceReportingService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                );
            }
        );

    }//end registerPhase5Bindings()

    /**
     * Phase 6 — Regulator export, multilingual reconciliation bindings.
     *
     * @param IRegistrationContext $context Registration context
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.1
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
     *
     * @return void
     */
    private function registerPhase6Bindings(IRegistrationContext $context): void
    {
        $context->registerService(
            \OCA\Decidesk\Service\RegulatorExportService::class,
            static function ($c): \OCA\Decidesk\Service\RegulatorExportService {
                return new \OCA\Decidesk\Service\RegulatorExportService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    auditLogService: $c->get(\OCA\Decidesk\Service\AuditLogService::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Controller\RegulatorExportController::class,
            static function ($c): \OCA\Decidesk\Controller\RegulatorExportController {
                return new \OCA\Decidesk\Controller\RegulatorExportController(
                    request: $c->get(\OCP\IRequest::class),
                    exportService: $c->get(\OCA\Decidesk\Service\RegulatorExportService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Service\MultilingualReconciliationService::class,
            static function ($c): \OCA\Decidesk\Service\MultilingualReconciliationService {
                return new \OCA\Decidesk\Service\MultilingualReconciliationService(
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
     */
    public function boot(IBootContext $context): void
    {
        // C2: email-voting is disabled — MailReplyHandler is not registered.
        // The background job remains in place for future re-enablement but must
        // not be scheduled until the feature is audited and enabled deliberately.
    }//end boot()
}//end class
