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

declare(strict_types=1);

namespace OCA\Decidesk\AppInfo;

use OCA\Decidesk\BackgroundJob\MailReplyHandler;
use OCA\Decidesk\BackgroundJob\OverdueActionItemsJob;
use OCA\Decidesk\Controller\AnalyticsController;
use OCA\Decidesk\Controller\DecisionController;
use OCA\Decidesk\Controller\LiveMeetingController;
use OCA\Decidesk\Controller\MinutesController;
use OCA\Decidesk\Controller\ProjectionController;
use OCA\Decidesk\Controller\VotingBehaviourController;
use OCA\Decidesk\Lifecycle\MeetingTransitionGuard;
use OCA\Decidesk\Listener\DeepLinkRegistrationListener;
use OCA\Decidesk\Listener\MinutesTransitionListener;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Decidesk\Repair\InitializeSettings;
use OCA\Decidesk\Service\ActionItemAnalyticsService;
use OCA\Decidesk\Service\ActionItemExtractionService;
use OCA\Decidesk\Service\ALVMinutesService;
use OCA\Decidesk\Service\DecisionNotificationService;
use OCA\Decidesk\Service\LiveDecisionService;
use OCA\Decidesk\Service\MinutesGenerationService;
use OCA\Decidesk\Service\MinutesService;
use OCA\Decidesk\Service\VotingBehaviourService;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;

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

        // Apply Minutes-specific bookkeeping (approvedAt, signedBy) when the
        // platform fires ObjectTransitionedEvent on the minutes schema.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: MinutesTransitionListener::class
        );

        // Initialize register and schemas on install/upgrade.
        $context->registerRepairStep(InitializeSettings::class);

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

        // Register AnalyticsController for DI.
        // @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-1.4.
        $context->registerService(
                AnalyticsController::class,
                static function ($c): AnalyticsController {
                    return new AnalyticsController(
                    request: $c->get(\OCP\IRequest::class),
                    analyticsService: $c->get(ActionItemAnalyticsService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
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

        // MeetingTransitionGuard is autowired by Nextcloud's server
        // container — its constructor takes WorkflowService + QuorumService,
        // both autowireable. OpenRegister's LifecycleGuardRegistry resolves
        // it by FQCN from the server container; no manual registration here.

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
        $serverContainer = $context->getServerContainer();
        $jobList         = $serverContainer->get(IJobList::class);
        if ($jobList->has(MailReplyHandler::class, null) === false) {
            $jobList->add(MailReplyHandler::class);
        }

    }//end boot()
}//end class
