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
            static function ($c): EmailReferenceExtractor {
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

        // Register MigrateCommentsToTalkLeaf repair step.
        // @spec openspec/changes/migrate-comments-to-talk-leaf/tasks.md#task-2.1.
        $context->registerRepairStep(MigrateCommentsToTalkLeaf::class);
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

        // Register MigrateActionItemsToDeckLeaf repair step.
        // @spec openspec/changes/migrate-action-items-to-deck-leaf/tasks.md#task-3.1.
        $context->registerRepairStep(MigrateActionItemsToDeckLeaf::class);
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
     */
    public function boot(IBootContext $context): void
    {
        // C2: email-voting is disabled — MailReplyHandler is not registered.
        // The background job remains in place for future re-enablement but must
        // not be scheduled until the feature is audited and enabled deliberately.
    }//end boot()
}//end class
