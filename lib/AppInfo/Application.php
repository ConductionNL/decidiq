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

use OCA\Decidesk\BackgroundJob\ConsultationAutoCloseJob;
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
use OCA\Decidesk\Controller\ParticipationController;
use OCA\Decidesk\Controller\ProjectionController;
use OCA\Decidesk\Controller\VotingBehaviourController;
use OCA\Decidesk\Controller\VotingController;
use OCA\Decidesk\Event\DecisionRequestedEvent;
use OCA\Decidesk\Listener\DecisionRequestedListener;
use OCA\Decidesk\Migration\MigrateActionItemsToDeckLeaf;
use OCA\Decidesk\Migration\MigrateCommentsToTalkLeaf;
use OCA\Decidesk\Service\ActionItemAnalyticsService;
use OCA\Decidesk\Service\ActionItemExtractionService;
use OCA\Decidesk\Service\ALVMinutesService;
use OCA\Decidesk\Service\DecisionIntegrationService;
use OCA\Decidesk\Service\DecisionLifecycleService;
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
use OCA\Decidesk\Service\ParticipationLifecycleService;
use OCA\Decidesk\Service\ParticipationPublicationService;
use OCA\Decidesk\Service\BudgetVotingService;
use OCA\Decidesk\Service\ReactionIntakeService;
use OCA\Decidesk\Service\VotingBehaviourService;
use OCA\Decidesk\Service\VotingService;
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
                    minutesDocumentService: $c->get(\OCA\Decidesk\Service\MinutesDocumentService::class),
                    );
                }
                );

        // Register DecisionLifecycleService for DI (guarded decision state machine).
        // @spec openspec/specs/decision-management/spec.md.
        $context->registerService(
                DecisionLifecycleService::class,
                static function ($c): DecisionLifecycleService {
                    return new DecisionLifecycleService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    transitionGuard: new \OCA\Decidesk\Lifecycle\DecisionTransitionGuard(),
                    auditLogService: $c->get(\OCA\Decidesk\Service\AuditLogService::class),
                    templateService: $c->get(\OCA\Decidesk\Service\ProcessTemplateService::class),
                    integrationService: $c->get(DecisionIntegrationService::class),
                    eventDispatcher: $c->get(\OCP\EventDispatcher\IEventDispatcher::class),
                    );
                }
                );

        // Register DecisionIntegrationService for DI (cross-app decision hub):
        // assembles the outcome envelope and the idempotent create-decision
        // logic reused by the event contract and the HTTP integration surface.
        // @spec openspec/changes/decidesk-decision-events/specs/decidesk-decision-events/spec.md.
        $context->registerService(
                DecisionIntegrationService::class,
                static function ($c): DecisionIntegrationService {
                    return new DecisionIntegrationService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    auditLog: $c->get(\OCA\Decidesk\Service\AuditLogService::class),
                    );
                }
                );

        // Register the event contract for delegated decisions: consumer apps
        // dispatch DecisionRequestedEvent (handled here -> createDecision) and
        // listen for DecisionConcludedEvent (emitted from DecisionLifecycleService).
        // In-process replacement for the broken IntegrationService::getLeaf path.
        // @spec openspec/changes/decidesk-decision-events/specs/decidesk-decision-events/spec.md.
        $context->registerService(
                DecisionRequestedListener::class,
                static function ($c): DecisionRequestedListener {
                    return new DecisionRequestedListener(
                    integrationService: $c->get(DecisionIntegrationService::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );
        $context->registerEventListener(
            event: DecisionRequestedEvent::class,
            listener: DecisionRequestedListener::class
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
                    lifecycleService: $c->get(DecisionLifecycleService::class),
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
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        // Register citizen-participation services for DI.
        // @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md.
        $context->registerService(
                ParticipationLifecycleService::class,
                static function ($c): ParticipationLifecycleService {
                    return new ParticipationLifecycleService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        $context->registerService(
                ReactionIntakeService::class,
                static function ($c): ReactionIntakeService {
                    return new ReactionIntakeService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    appConfig: $c->get(\OCP\IAppConfig::class),
                    lifecycleService: $c->get(ParticipationLifecycleService::class),
                    );
                }
                );

        $context->registerService(
                BudgetVotingService::class,
                static function ($c): BudgetVotingService {
                    return new BudgetVotingService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    lifecycleService: $c->get(ParticipationLifecycleService::class),
                    votingService: $c->get(VotingService::class),
                    );
                }
                );

        $context->registerService(
                ParticipationPublicationService::class,
                static function ($c): ParticipationPublicationService {
                    return new ParticipationPublicationService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    appManager: $c->get(\OCP\App\IAppManager::class),
                    appConfig: $c->get(\OCP\IAppConfig::class),
                    budgetService: $c->get(BudgetVotingService::class),
                    );
                }
                );

        $context->registerService(
                ParticipationController::class,
                static function ($c): ParticipationController {
                    return new ParticipationController(
                    request: $c->get(\OCP\IRequest::class),
                    lifecycleService: $c->get(ParticipationLifecycleService::class),
                    intakeService: $c->get(ReactionIntakeService::class),
                    budgetService: $c->get(BudgetVotingService::class),
                    publicationService: $c->get(ParticipationPublicationService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                    appConfig: $c->get(\OCP\IAppConfig::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        $context->registerService(
                ConsultationAutoCloseJob::class,
                static function ($c): ConsultationAutoCloseJob {
                    return new ConsultationAutoCloseJob(
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

        // Register publication services for DI (publish-decisions-via-opencatalogi).
        // @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md.
        $context->registerService(
                \OCA\Decidesk\Service\PublicationConfigService::class,
                static function ($c): \OCA\Decidesk\Service\PublicationConfigService {
                    return new \OCA\Decidesk\Service\PublicationConfigService(
                    appConfig: $c->get(\OCP\IAppConfig::class),
                    );
                }
                );

        $context->registerService(
                \OCA\Decidesk\Service\PublicationEligibilityService::class,
                static function ($c): \OCA\Decidesk\Service\PublicationEligibilityService {
                    return new \OCA\Decidesk\Service\PublicationEligibilityService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        $context->registerService(
                \OCA\Decidesk\Service\PublicationPayloadService::class,
                static function ($c): \OCA\Decidesk\Service\PublicationPayloadService {
                    return new \OCA\Decidesk\Service\PublicationPayloadService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    configService: $c->get(\OCA\Decidesk\Service\PublicationConfigService::class),
                    );
                }
                );

        $context->registerService(
                \OCA\Decidesk\Service\OpenCatalogiPublisher::class,
                static function ($c): \OCA\Decidesk\Service\OpenCatalogiPublisher {
                    return new \OCA\Decidesk\Service\OpenCatalogiPublisher(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    appManager: $c->get(\OCP\App\IAppManager::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        $context->registerService(
                \OCA\Decidesk\Service\PublicationService::class,
                static function ($c): \OCA\Decidesk\Service\PublicationService {
                    return new \OCA\Decidesk\Service\PublicationService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    appManager: $c->get(\OCP\App\IAppManager::class),
                    eligibility: $c->get(\OCA\Decidesk\Service\PublicationEligibilityService::class),
                    payloadService: $c->get(\OCA\Decidesk\Service\PublicationPayloadService::class),
                    configService: $c->get(\OCA\Decidesk\Service\PublicationConfigService::class),
                    catalogPublisher: $c->get(\OCA\Decidesk\Service\OpenCatalogiPublisher::class),
                    auditLogService: $c->get(\OCA\Decidesk\Service\AuditLogService::class),
                    );
                }
                );

        $context->registerService(
                \OCA\Decidesk\Controller\PublicationController::class,
                static function ($c): \OCA\Decidesk\Controller\PublicationController {
                    return new \OCA\Decidesk\Controller\PublicationController(
                    request: $c->get(\OCP\IRequest::class),
                    publicationService: $c->get(\OCA\Decidesk\Service\PublicationService::class),
                    objectService: $c->get(ObjectService::class),
                    participantResolver: $c->get(ParticipantResolver::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
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
                    templateService: $c->get(\OCA\Decidesk\Service\ProcessTemplateService::class),
                    );
                }
                );

        // Register ProcessTemplateService for DI (process-config-v1): template
        // CRUD + state-machine validation + body-template policy resolution.
        // @spec openspec/specs/process-configuration/spec.md.
        $context->registerService(
                \OCA\Decidesk\Service\ProcessTemplateService::class,
                static function ($c): \OCA\Decidesk\Service\ProcessTemplateService {
                    return new \OCA\Decidesk\Service\ProcessTemplateService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    resolver: new \OCA\Decidesk\Lifecycle\ProcessTemplatePolicyResolver(),
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
        //
        // WorkspaceService was retired in migrate-workspaces-to-collectives-leaf
        // (ADR-022): faction/committee/task-group workspaces are now Nextcloud
        // Collectives bound to the governance-body OR object via the ADR-019
        // registry. The collectives leaf is declared in
        // lib/Settings/register.d/41-migrate-workspaces-to-collectives-leaf.json.
        // @spec openspec/changes/migrate-workspaces-to-collectives-leaf/tasks.md#task-4.1.
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
        // WorkspaceController retired alongside WorkspaceService
        // (migrate-workspaces-to-collectives-leaf, ADR-022 / task-4.1).
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
                    participantResolver: $c->get(ParticipantResolver::class),
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
                return new MigrateActionItemsToDeckLeaf();
            }
        );

        // Register DecideskToolProvider as the MCP tool provider for the AI Chat Companion.
        // The alias key 'OCA\OpenRegister\Mcp\IMcpToolProvider::decidesk' is the format
        // that OR's McpToolsService enumerates to discover per-app providers (design D3).
        // The interface ships in openregister PR #1466 (ai-chat-companion-orchestrator).
        // @spec openspec/specs/mcp-tools/spec.md.
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
            \OCA\Decidesk\Service\QuorumVerificationService::class,
            static function ($c): \OCA\Decidesk\Service\QuorumVerificationService {
                return new \OCA\Decidesk\Service\QuorumVerificationService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
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
        $context->registerEventListener(
            event: DeepLinkRegistrationEvent::class,
            listener: 'OCA\\OpenRegister\\AppHost\\Listener\\GenericDeepLinkRegistrationListener'
        );

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
        // Fail-soft Activity publisher (called from the governance services).
        $context->registerService(
            \OCA\Decidesk\Service\ActivityPublisherService::class,
            static function ($c): \OCA\Decidesk\Service\ActivityPublisherService {
                return new \OCA\Decidesk\Service\ActivityPublisherService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        // Unified search over decisions / meetings / resolutions (OR RBAC scoped).
        $context->registerService(
            \OCA\Decidesk\Search\DecideskSearchProvider::class,
            static function ($c): \OCA\Decidesk\Search\DecideskSearchProvider {
                return new \OCA\Decidesk\Search\DecideskSearchProvider(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    urlGenerator: $c->get(\OCP\IURLGenerator::class),
                    l10n: $c->get(\OCP\L10N\IFactory::class)->get(self::APP_ID),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );
        $context->registerSearchProvider(\OCA\Decidesk\Search\DecideskSearchProvider::class);

        // Meeting Files folder tree on meeting creation.
        $context->registerService(
            \OCA\Decidesk\Service\MeetingFolderService::class,
            static function ($c): \OCA\Decidesk\Service\MeetingFolderService {
                return new \OCA\Decidesk\Service\MeetingFolderService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );
        // The MeetingFolderListener declares its schema interest up front and is
        // therefore subscribed from boot(), not here — see boot().
        $context->registerService(
            \OCA\Decidesk\Listener\MeetingFolderListener::class,
            static function ($c): \OCA\Decidesk\Listener\MeetingFolderListener {
                return new \OCA\Decidesk\Listener\MeetingFolderListener(
                    folderService: $c->get(\OCA\Decidesk\Service\MeetingFolderService::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        // Recurring series generation + meeting document package assembly
        // (meeting-agenda-gaps-v1).
        $context->registerService(
            \OCA\Decidesk\Service\MeetingSeriesService::class,
            static function ($c): \OCA\Decidesk\Service\MeetingSeriesService {
                return new \OCA\Decidesk\Service\MeetingSeriesService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    auditLogService: $c->get(\OCA\Decidesk\Service\AuditLogService::class),
                );
            }
        );
        $context->registerService(
            \OCA\Decidesk\Service\MeetingPackageService::class,
            static function ($c): \OCA\Decidesk\Service\MeetingPackageService {
                return new \OCA\Decidesk\Service\MeetingPackageService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    meetingFolderService: $c->get(\OCA\Decidesk\Service\MeetingFolderService::class),
                );
            }
        );

        // Governance role -> OR RBAC scope projection
        // (consume-or-rbac-authorization, REQ-RBAC-001): keep each body's
        // chair/signatory scopes in sync on Participant/Membership writes.
        $context->registerService(
            \OCA\Decidesk\Service\GovernanceRoleScopeProjector::class,
            static function ($c): \OCA\Decidesk\Service\GovernanceRoleScopeProjector {
                return new \OCA\Decidesk\Service\GovernanceRoleScopeProjector(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                    userManager: $c->get(\OCP\IUserManager::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );
        // The GovernanceRoleProjectionListener carries the same narrowing as
        // MeetingFolderListener and is subscribed from boot(), not here.
        $context->registerService(
            \OCA\Decidesk\Listener\GovernanceRoleProjectionListener::class,
            static function ($c): \OCA\Decidesk\Listener\GovernanceRoleProjectionListener {
                return new \OCA\Decidesk\Listener\GovernanceRoleProjectionListener(
                    projector: $c->get(\OCA\Decidesk\Service\GovernanceRoleScopeProjector::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        // Meeting transcription + AI-assisted draft minutes
        // (meeting-transcription-ai-minutes): thin orchestration over the NC
        // SpeechToText + TaskProcessing provider abstractions. All provider
        // resolution is lazy + guarded so absence is a first-class state.
        // @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md.
        $context->registerService(
            \OCA\Decidesk\Service\TranscriptionSourceResolver::class,
            static function ($c): \OCA\Decidesk\Service\TranscriptionSourceResolver {
                return new \OCA\Decidesk\Service\TranscriptionSourceResolver(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    folderService: $c->get(\OCA\Decidesk\Service\MeetingFolderService::class),
                );
            }
        );
        $context->registerService(
            \OCA\Decidesk\Service\TranscriptionService::class,
            static function ($c): \OCA\Decidesk\Service\TranscriptionService {
                return new \OCA\Decidesk\Service\TranscriptionService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    sourceResolver: $c->get(\OCA\Decidesk\Service\TranscriptionSourceResolver::class),
                    folderService: $c->get(\OCA\Decidesk\Service\MeetingFolderService::class),
                );
            }
        );
        $context->registerService(
            \OCA\Decidesk\Service\MinutesDraftService::class,
            static function ($c): \OCA\Decidesk\Service\MinutesDraftService {
                return new \OCA\Decidesk\Service\MinutesDraftService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );
        $context->registerService(
            \OCA\Decidesk\BackgroundJob\TranscriptionJob::class,
            static function ($c): \OCA\Decidesk\BackgroundJob\TranscriptionJob {
                return new \OCA\Decidesk\BackgroundJob\TranscriptionJob(
                    time: $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                    transcriptionService: $c->get(\OCA\Decidesk\Service\TranscriptionService::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );
        $context->registerService(
            \OCA\Decidesk\BackgroundJob\TranscriptRetentionJob::class,
            static function ($c): \OCA\Decidesk\BackgroundJob\TranscriptRetentionJob {
                return new \OCA\Decidesk\BackgroundJob\TranscriptRetentionJob(
                    time: $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );
        $context->registerService(
            \OCA\Decidesk\Controller\TranscriptionController::class,
            static function ($c): \OCA\Decidesk\Controller\TranscriptionController {
                return new \OCA\Decidesk\Controller\TranscriptionController(
                    request: $c->get(\OCP\IRequest::class),
                    transcriptionService: $c->get(\OCA\Decidesk\Service\TranscriptionService::class),
                    minutesDraftService: $c->get(\OCA\Decidesk\Service\MinutesDraftService::class),
                    objectService: $c->get(ObjectService::class),
                    participantResolver: $c->get(ParticipantResolver::class),
                    jobList: $c->get(\OCP\BackgroundJob\IJobList::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                );
            }
        );

        // Submission deadline gate (motion-amendment spec): pre-save hook that
        // rejects motion/amendment creations after the linked meeting's
        // submissionDeadline (OpenRegister converts the stopped event into
        // HTTP 422 at the object API). It declares its schema interest up front
        // and is therefore subscribed from boot(), not here — see boot().
        $context->registerService(
            \OCA\Decidesk\Listener\SubmissionDeadlineListener::class,
            static function ($c): \OCA\Decidesk\Listener\SubmissionDeadlineListener {
                return new \OCA\Decidesk\Listener\SubmissionDeadlineListener(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

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
        $context->registerService(
            \OCA\Decidesk\Listener\PortalCreateOpenParentGuardListener::class,
            static function ($c): \OCA\Decidesk\Listener\PortalCreateOpenParentGuardListener {
                return new \OCA\Decidesk\Listener\PortalCreateOpenParentGuardListener(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );
        $context->registerEventListener(
            event: ObjectCreatingEvent::class,
            listener: \OCA\Decidesk\Listener\PortalCreateOpenParentGuardListener::class
        );

        // Voting deadline reminder sweep (hourly job in appinfo/info.xml).
        $context->registerService(
            \OCA\Decidesk\Service\VotingDeadlineReminderService::class,
            static function ($c): \OCA\Decidesk\Service\VotingDeadlineReminderService {
                return new \OCA\Decidesk\Service\VotingDeadlineReminderService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        // Nextcloud main-dashboard widget (dashboard-iwidget-v1): a per-user
        // "Decidesk" widget showing pending votes count + next meeting on the
        // Nextcloud Hub, deep-linking into the app. Fail-soft, OR-scoped.
        // @spec openspec/specs/dashboard/spec.md.
        $context->registerService(
            \OCA\Decidesk\Service\DashboardWidgetService::class,
            static function ($c): \OCA\Decidesk\Service\DashboardWidgetService {
                return new \OCA\Decidesk\Service\DashboardWidgetService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );
        $context->registerService(
            \OCA\Decidesk\Dashboard\DecideskDashboardWidget::class,
            static function ($c): \OCA\Decidesk\Dashboard\DecideskDashboardWidget {
                return new \OCA\Decidesk\Dashboard\DecideskDashboardWidget(
                    l10n: $c->get(\OCP\L10N\IFactory::class)->get(self::APP_ID),
                    urlGenerator: $c->get(\OCP\IURLGenerator::class),
                    timeFactory: $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                    widgetService: $c->get(\OCA\Decidesk\Service\DashboardWidgetService::class),
                );
            }
        );
        $context->registerDashboardWidget(\OCA\Decidesk\Dashboard\DecideskDashboardWidget::class);

    }//end registerNcPlatformIntegration()

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

        // GovernanceScopeGuard consumes the OpenRegister-projected per-body
        // signatory/chair scopes (consume-or-rbac-authorization). It replaces
        // the retired app-local MinutesAuthorizationService.
        $context->registerService(
            \OCA\Decidesk\Service\GovernanceScopeGuard::class,
            static function ($c): \OCA\Decidesk\Service\GovernanceScopeGuard {
                return new \OCA\Decidesk\Service\GovernanceScopeGuard(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
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
                    scopeGuard: $c->get(\OCA\Decidesk\Service\GovernanceScopeGuard::class),
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
                    participantResolver: $c->get(ParticipantResolver::class),
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
                    groupManager: $c->get(\OCP\IGroupManager::class),
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

        // Dormant default translation adapter — rebind in production to delegate
        // to openconnector's translation source service.
        $context->registerService(
            \OCA\Decidesk\Service\ITranslationAdapter::class,
            static function ($c): \OCA\Decidesk\Service\ITranslationAdapter {
                return new \OCA\Decidesk\Service\LogTranslationAdapter(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Controller\MultilingualReconciliationController::class,
            static function ($c): \OCA\Decidesk\Controller\MultilingualReconciliationController {
                return new \OCA\Decidesk\Controller\MultilingualReconciliationController(
                    request: $c->get(\OCP\IRequest::class),
                    reconciliationService: $c->get(\OCA\Decidesk\Service\MultilingualReconciliationService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\BackgroundJob\TranslationQueueJob::class,
            static function ($c): \OCA\Decidesk\BackgroundJob\TranslationQueueJob {
                return new \OCA\Decidesk\BackgroundJob\TranslationQueueJob(
                    time: $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                    reconciliationService: $c->get(\OCA\Decidesk\Service\MultilingualReconciliationService::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        // Board self-evaluation (board-self-evaluation).
        $context->registerService(
            \OCA\Decidesk\Service\BoardEvaluationScoreService::class,
            static function ($c): \OCA\Decidesk\Service\BoardEvaluationScoreService {
                return new \OCA\Decidesk\Service\BoardEvaluationScoreService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Service\BoardEvaluationResponseService::class,
            static function ($c): \OCA\Decidesk\Service\BoardEvaluationResponseService {
                return new \OCA\Decidesk\Service\BoardEvaluationResponseService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    appConfig: $c->get(\OCP\IAppConfig::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Service\BoardEvaluationReportService::class,
            static function ($c): \OCA\Decidesk\Service\BoardEvaluationReportService {
                return new \OCA\Decidesk\Service\BoardEvaluationReportService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            \OCA\Decidesk\Controller\BoardEvaluationController::class,
            static function ($c): \OCA\Decidesk\Controller\BoardEvaluationController {
                return new \OCA\Decidesk\Controller\BoardEvaluationController(
                    request: $c->get(\OCP\IRequest::class),
                    responseService: $c->get(\OCA\Decidesk\Service\BoardEvaluationResponseService::class),
                    scoreService: $c->get(\OCA\Decidesk\Service\BoardEvaluationScoreService::class),
                    reportService: $c->get(\OCA\Decidesk\Service\BoardEvaluationReportService::class),
                    publicationService: $c->get(\OCA\Decidesk\Service\ParticipationPublicationService::class),
                    votingService: $c->get(\OCA\Decidesk\Service\VotingService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
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
