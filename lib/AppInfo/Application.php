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
use OCA\Decidesk\BackgroundJob\TranscriptRetentionJob;
use OCA\Decidesk\BackgroundJob\TranscriptionJob;
use OCA\Decidesk\BackgroundJob\TranslationQueueJob;
use OCA\Decidesk\Controller\AnalyticsController;
use OCA\Decidesk\Controller\AuditLogController;
use OCA\Decidesk\Controller\BoardEvaluationController;
use OCA\Decidesk\Controller\ConflictOfInterestController;
use OCA\Decidesk\Controller\DecisionController;
use OCA\Decidesk\Controller\EIDASSignatureController;
use OCA\Decidesk\Controller\EngagementController;
use OCA\Decidesk\Controller\GovernanceReportController;
use OCA\Decidesk\Controller\LiveMeetingController;
use OCA\Decidesk\Controller\MinutesController;
use OCA\Decidesk\Controller\MotionCoauthorController;
use OCA\Decidesk\Controller\MotionController;
use OCA\Decidesk\Controller\MultilingualReconciliationController;
use OCA\Decidesk\Controller\NotificationPreferenceController;
use OCA\Decidesk\Controller\ParticipationController;
use OCA\Decidesk\Controller\ProjectionController;
use OCA\Decidesk\Controller\ProxyVoteController;
use OCA\Decidesk\Controller\PublicationController;
use OCA\Decidesk\Controller\RegulatorExportController;
use OCA\Decidesk\Controller\TranscriptionController;
use OCA\Decidesk\Controller\VotingBehaviourController;
use OCA\Decidesk\Controller\VotingController;
use OCA\Decidesk\Dashboard\DecideskDashboardWidget;
use OCA\Decidesk\Event\DecisionRequestedEvent;
use OCA\Decidesk\Lifecycle\DecisionTransitionGuard;
use OCA\Decidesk\Lifecycle\ProcessTemplatePolicyResolver;
use OCA\Decidesk\Lifecycle\QesGuard;
use OCA\Decidesk\Listener\DecisionRequestedListener;
use OCA\Decidesk\Listener\GovernanceRoleProjectionListener;
use OCA\Decidesk\Listener\MeetingFolderListener;
use OCA\Decidesk\Listener\PortalCreateOpenParentGuardListener;
use OCA\Decidesk\Listener\SubmissionDeadlineListener;
use OCA\Decidesk\Mcp\DecideskToolProvider;
use OCA\Decidesk\Migration\MigrateActionItemsToDeckLeaf;
use OCA\Decidesk\Migration\MigrateCommentsToTalkLeaf;
use OCA\Decidesk\Search\DecideskSearchProvider;
use OCA\Decidesk\Service\ALVMinutesService;
use OCA\Decidesk\Service\ActionItemAnalyticsService;
use OCA\Decidesk\Service\ActionItemExtractionService;
use OCA\Decidesk\Service\ActivityPublisherService;
use OCA\Decidesk\Service\AuditLogService;
use OCA\Decidesk\Service\BoardEvaluationReportService;
use OCA\Decidesk\Service\BoardEvaluationResponseService;
use OCA\Decidesk\Service\BoardEvaluationScoreService;
use OCA\Decidesk\Service\BudgetVotingService;
use OCA\Decidesk\Service\ConflictOfInterestService;
use OCA\Decidesk\Service\DashboardWidgetService;
use OCA\Decidesk\Service\DecisionIntegrationService;
use OCA\Decidesk\Service\DecisionLifecycleService;
use OCA\Decidesk\Service\DecisionNotificationService;
use OCA\Decidesk\Service\EIDASSignatureService;
use OCA\Decidesk\Service\EmailReferenceExtractor;
use OCA\Decidesk\Service\EngagementService;
use OCA\Decidesk\Service\GovernanceReportingService;
use OCA\Decidesk\Service\GovernanceRoleScopeProjector;
use OCA\Decidesk\Service\GovernanceScopeGuard;
use OCA\Decidesk\Service\IEIDASSignatureService;
use OCA\Decidesk\Service\ITranslationAdapter;
use OCA\Decidesk\Service\LiveDecisionService;
use OCA\Decidesk\Service\LogEIDASSignatureService;
use OCA\Decidesk\Service\LogTranslationAdapter;
use OCA\Decidesk\Service\MeetingFolderService;
use OCA\Decidesk\Service\MeetingPackageService;
use OCA\Decidesk\Service\MeetingSeriesService;
use OCA\Decidesk\Service\MinutesDocumentService;
use OCA\Decidesk\Service\MinutesDraftService;
use OCA\Decidesk\Service\MinutesGenerationService;
use OCA\Decidesk\Service\MinutesService;
use OCA\Decidesk\Service\MotionCoauthorService;
use OCA\Decidesk\Service\MotionService;
use OCA\Decidesk\Service\MultilingualReconciliationService;
use OCA\Decidesk\Service\NotificationPreferenceService;
use OCA\Decidesk\Service\OpenCatalogiPublisher;
use OCA\Decidesk\Service\OriPublicationService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\Decidesk\Service\ParticipationLifecycleService;
use OCA\Decidesk\Service\ParticipationPublicationService;
use OCA\Decidesk\Service\ProcessTemplateService;
use OCA\Decidesk\Service\ProxyVoteService;
use OCA\Decidesk\Service\PublicationConfigService;
use OCA\Decidesk\Service\PublicationEligibilityService;
use OCA\Decidesk\Service\PublicationPayloadService;
use OCA\Decidesk\Service\PublicationService;
use OCA\Decidesk\Service\QuorumVerificationService;
use OCA\Decidesk\Service\ReactionIntakeService;
use OCA\Decidesk\Service\RegulatorExportService;
use OCA\Decidesk\Service\SettingsService;
use OCA\Decidesk\Service\TranscriptionService;
use OCA\Decidesk\Service\TranscriptionSourceResolver;
use OCA\Decidesk\Service\VotingBehaviourService;
use OCA\Decidesk\Service\VotingDeadlineReminderService;
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
                    minutesDocumentService: $c->get(MinutesDocumentService::class),
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
                    transitionGuard: new DecisionTransitionGuard(),
                    auditLogService: $c->get(AuditLogService::class),
                    templateService: $c->get(ProcessTemplateService::class),
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
                    auditLog: $c->get(AuditLogService::class),
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
                PublicationConfigService::class,
                static function ($c): PublicationConfigService {
                    return new PublicationConfigService(
                    appConfig: $c->get(\OCP\IAppConfig::class),
                    );
                }
                );

        $context->registerService(
                PublicationEligibilityService::class,
                static function ($c): PublicationEligibilityService {
                    return new PublicationEligibilityService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        $context->registerService(
                PublicationPayloadService::class,
                static function ($c): PublicationPayloadService {
                    return new PublicationPayloadService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    configService: $c->get(PublicationConfigService::class),
                    );
                }
                );

        $context->registerService(
                OpenCatalogiPublisher::class,
                static function ($c): OpenCatalogiPublisher {
                    return new OpenCatalogiPublisher(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    appManager: $c->get(\OCP\App\IAppManager::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    );
                }
                );

        $context->registerService(
                PublicationService::class,
                static function ($c): PublicationService {
                    return new PublicationService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    appManager: $c->get(\OCP\App\IAppManager::class),
                    eligibility: $c->get(PublicationEligibilityService::class),
                    payloadService: $c->get(PublicationPayloadService::class),
                    configService: $c->get(PublicationConfigService::class),
                    catalogPublisher: $c->get(OpenCatalogiPublisher::class),
                    auditLogService: $c->get(AuditLogService::class),
                    );
                }
                );

        $context->registerService(
                PublicationController::class,
                static function ($c): PublicationController {
                    return new PublicationController(
                    request: $c->get(\OCP\IRequest::class),
                    publicationService: $c->get(PublicationService::class),
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
                    templateService: $c->get(ProcessTemplateService::class),
                    );
                }
                );

        // Register ProcessTemplateService for DI (process-config-v1): template
        // CRUD + state-machine validation + body-template policy resolution.
        // @spec openspec/specs/process-configuration/spec.md.
        $context->registerService(
                ProcessTemplateService::class,
                static function ($c): ProcessTemplateService {
                    return new ProcessTemplateService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    resolver: new ProcessTemplatePolicyResolver(),
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
                    settingsService: $c->get(SettingsService::class),
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
            AuditLogService::class,
            static function ($c): AuditLogService {
                return new AuditLogService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            ConflictOfInterestService::class,
            static function ($c): ConflictOfInterestService {
                return new ConflictOfInterestService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    auditLogService: $c->get(AuditLogService::class),
                );
            }
        );

        $context->registerService(
            QuorumVerificationService::class,
            static function ($c): QuorumVerificationService {
                return new QuorumVerificationService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            ConflictOfInterestController::class,
            static function ($c): ConflictOfInterestController {
                return new ConflictOfInterestController(
                    request: $c->get(\OCP\IRequest::class),
                    conflictService: $c->get(ConflictOfInterestService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                );
            }
        );

        $context->registerService(
            AuditLogController::class,
            static function ($c): AuditLogController {
                return new AuditLogController(
                    request: $c->get(\OCP\IRequest::class),
                    auditLogService: $c->get(AuditLogService::class),
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
        // Fail-soft Activity publisher (called from the governance services).
        $context->registerService(
            ActivityPublisherService::class,
            static function ($c): ActivityPublisherService {
                return new ActivityPublisherService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        // Unified search over decisions / meetings / resolutions (OR RBAC scoped).
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

        // Meeting Files folder tree on meeting creation.
        $context->registerService(
            MeetingFolderService::class,
            static function ($c): MeetingFolderService {
                return new MeetingFolderService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );
        $context->registerService(
            MeetingFolderListener::class,
            static function ($c): MeetingFolderListener {
                return new MeetingFolderListener(
                    folderService: $c->get(MeetingFolderService::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        // Recurring series generation + meeting document package assembly
        // (meeting-agenda-gaps-v1).
        $context->registerService(
            MeetingSeriesService::class,
            static function ($c): MeetingSeriesService {
                return new MeetingSeriesService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    auditLogService: $c->get(AuditLogService::class),
                );
            }
        );
        $context->registerService(
            MeetingPackageService::class,
            static function ($c): MeetingPackageService {
                return new MeetingPackageService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    meetingFolderService: $c->get(MeetingFolderService::class),
                );
            }
        );
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: MeetingFolderListener::class
        );

        // Governance role -> OR RBAC scope projection
        // (consume-or-rbac-authorization, REQ-RBAC-001): keep each body's
        // chair/signatory scopes in sync on Participant/Membership writes.
        $context->registerService(
            GovernanceRoleScopeProjector::class,
            static function ($c): GovernanceRoleScopeProjector {
                return new GovernanceRoleScopeProjector(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                    userManager: $c->get(\OCP\IUserManager::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );
        $context->registerService(
            GovernanceRoleProjectionListener::class,
            static function ($c): GovernanceRoleProjectionListener {
                return new GovernanceRoleProjectionListener(
                    projector: $c->get(GovernanceRoleScopeProjector::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: GovernanceRoleProjectionListener::class
        );
        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: GovernanceRoleProjectionListener::class
        );
        $context->registerEventListener(
            event: \OCA\OpenRegister\Event\ObjectDeletedEvent::class,
            listener: GovernanceRoleProjectionListener::class
        );

        // Meeting transcription + AI-assisted draft minutes
        // (meeting-transcription-ai-minutes): thin orchestration over the NC
        // SpeechToText + TaskProcessing provider abstractions. All provider
        // resolution is lazy + guarded so absence is a first-class state.
        // @spec openspec/changes/meeting-transcription-ai-minutes/specs/meeting-transcription/spec.md.
        $context->registerService(
            TranscriptionSourceResolver::class,
            static function ($c): TranscriptionSourceResolver {
                return new TranscriptionSourceResolver(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    folderService: $c->get(MeetingFolderService::class),
                );
            }
        );
        $context->registerService(
            TranscriptionService::class,
            static function ($c): TranscriptionService {
                return new TranscriptionService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    sourceResolver: $c->get(TranscriptionSourceResolver::class),
                    folderService: $c->get(MeetingFolderService::class),
                );
            }
        );
        $context->registerService(
            MinutesDraftService::class,
            static function ($c): MinutesDraftService {
                return new MinutesDraftService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );
        $context->registerService(
            TranscriptionJob::class,
            static function ($c): TranscriptionJob {
                return new TranscriptionJob(
                    time: $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                    transcriptionService: $c->get(TranscriptionService::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );
        $context->registerService(
            TranscriptRetentionJob::class,
            static function ($c): TranscriptRetentionJob {
                return new TranscriptRetentionJob(
                    time: $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );
        $context->registerService(
            TranscriptionController::class,
            static function ($c): TranscriptionController {
                return new TranscriptionController(
                    request: $c->get(\OCP\IRequest::class),
                    transcriptionService: $c->get(TranscriptionService::class),
                    minutesDraftService: $c->get(MinutesDraftService::class),
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
        // HTTP 422 at the object API).
        $context->registerService(
            SubmissionDeadlineListener::class,
            static function ($c): SubmissionDeadlineListener {
                return new SubmissionDeadlineListener(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );
        $context->registerEventListener(
            event: ObjectCreatingEvent::class,
            listener: SubmissionDeadlineListener::class
        );

        // Portal citizen create-actions open-parent guard
        // (portal-citizen-create-actions, REQ-DKPCA-001/002): rejects a
        // consultation-reaction/budget-proposal create whose parent
        // consultation/budget round is not open, closing the gap left by
        // portaliq's shared create receiver (which stamps scope + defaults
        // but does not enforce a declared parentConstraint).
        $context->registerService(
            PortalCreateOpenParentGuardListener::class,
            static function ($c): PortalCreateOpenParentGuardListener {
                return new PortalCreateOpenParentGuardListener(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );
        $context->registerEventListener(
            event: ObjectCreatingEvent::class,
            listener: PortalCreateOpenParentGuardListener::class
        );

        // Voting deadline reminder sweep (hourly job in appinfo/info.xml).
        $context->registerService(
            VotingDeadlineReminderService::class,
            static function ($c): VotingDeadlineReminderService {
                return new VotingDeadlineReminderService(
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
            DashboardWidgetService::class,
            static function ($c): DashboardWidgetService {
                return new DashboardWidgetService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );
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

    }//end registerNcPlatformIntegration()

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
        // overrides can pick either side without going through the resolver.
        $context->registerService(
            EIDASSignatureService::class,
            static function ($c): EIDASSignatureService {
                return new EIDASSignatureService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    auditLogService: $c->get(AuditLogService::class),
                );
            }
        );

        $context->registerService(
            LogEIDASSignatureService::class,
            static function ($c): LogEIDASSignatureService {
                return new LogEIDASSignatureService(
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    auditLogService: $c->get(AuditLogService::class),
                );
            }
        );

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

        $context->registerService(
            QesGuard::class,
            static function ($c): QesGuard {
                return new QesGuard(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    signatureService: $c->get(IEIDASSignatureService::class),
                );
            }
        );

        // GovernanceScopeGuard consumes the OpenRegister-projected per-body
        // signatory/chair scopes (consume-or-rbac-authorization). It replaces
        // the retired app-local MinutesAuthorizationService.
        $context->registerService(
            GovernanceScopeGuard::class,
            static function ($c): GovernanceScopeGuard {
                return new GovernanceScopeGuard(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            EIDASSignatureController::class,
            static function ($c): EIDASSignatureController {
                return new EIDASSignatureController(
                    request: $c->get(\OCP\IRequest::class),
                    signatureService: $c->get(IEIDASSignatureService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    scopeGuard: $c->get(GovernanceScopeGuard::class),
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
            ProxyVoteService::class,
            static function ($c): ProxyVoteService {
                return new ProxyVoteService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    auditLogService: $c->get(AuditLogService::class),
                    participantResolver: $c->get(ParticipantResolver::class),
                );
            }
        );

        $context->registerService(
            ProxyVoteController::class,
            static function ($c): ProxyVoteController {
                return new ProxyVoteController(
                    request: $c->get(\OCP\IRequest::class),
                    proxyService: $c->get(ProxyVoteService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                );
            }
        );

        $context->registerService(
            GovernanceReportingService::class,
            static function ($c): GovernanceReportingService {
                return new GovernanceReportingService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            GovernanceReportController::class,
            static function ($c): GovernanceReportController {
                return new GovernanceReportController(
                    request: $c->get(\OCP\IRequest::class),
                    reportingService: $c->get(GovernanceReportingService::class),
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
            RegulatorExportService::class,
            static function ($c): RegulatorExportService {
                return new RegulatorExportService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                    auditLogService: $c->get(AuditLogService::class),
                );
            }
        );

        $context->registerService(
            RegulatorExportController::class,
            static function ($c): RegulatorExportController {
                return new RegulatorExportController(
                    request: $c->get(\OCP\IRequest::class),
                    exportService: $c->get(RegulatorExportService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                );
            }
        );

        $context->registerService(
            MultilingualReconciliationService::class,
            static function ($c): MultilingualReconciliationService {
                return new MultilingualReconciliationService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

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

        $context->registerService(
            MultilingualReconciliationController::class,
            static function ($c): MultilingualReconciliationController {
                return new MultilingualReconciliationController(
                    request: $c->get(\OCP\IRequest::class),
                    reconciliationService: $c->get(MultilingualReconciliationService::class),
                    userSession: $c->get(\OCP\IUserSession::class),
                    groupManager: $c->get(\OCP\IGroupManager::class),
                );
            }
        );

        $context->registerService(
            TranslationQueueJob::class,
            static function ($c): TranslationQueueJob {
                return new TranslationQueueJob(
                    time: $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                    reconciliationService: $c->get(MultilingualReconciliationService::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        // Board self-evaluation (board-self-evaluation).
        $context->registerService(
            BoardEvaluationScoreService::class,
            static function ($c): BoardEvaluationScoreService {
                return new BoardEvaluationScoreService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            BoardEvaluationResponseService::class,
            static function ($c): BoardEvaluationResponseService {
                return new BoardEvaluationResponseService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    appConfig: $c->get(\OCP\IAppConfig::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            BoardEvaluationReportService::class,
            static function ($c): BoardEvaluationReportService {
                return new BoardEvaluationReportService(
                    container: $c->get(\Psr\Container\ContainerInterface::class),
                    logger: $c->get(\Psr\Log\LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            BoardEvaluationController::class,
            static function ($c): BoardEvaluationController {
                return new BoardEvaluationController(
                    request: $c->get(\OCP\IRequest::class),
                    responseService: $c->get(BoardEvaluationResponseService::class),
                    scoreService: $c->get(BoardEvaluationScoreService::class),
                    reportService: $c->get(BoardEvaluationReportService::class),
                    publicationService: $c->get(ParticipationPublicationService::class),
                    votingService: $c->get(VotingService::class),
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
    }//end boot()
}//end class
