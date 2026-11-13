<?php

/**
 * Bindings of the Kernel contracts to their Service implementations
 *
 * @category  Service
 * @package   OCA\Decidiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural composition, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Kernel\Contracts\ActionItemAnalyticsServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ActionItemWriterInterface;
use OCA\Decidiq\Kernel\Contracts\AgendaAuthorizationGuardInterface;
use OCA\Decidiq\Kernel\Contracts\AgendaServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ALVMinutesServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ApprovalRouteCommandServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ApprovalRouteConclusionAnnouncerInterface;
use OCA\Decidiq\Kernel\Contracts\ApprovalRouteServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ApprovalStageLapseServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ApprovalStageTaskProjectorInterface;
use OCA\Decidiq\Kernel\Contracts\ArchivalDossierServiceInterface;
use OCA\Decidiq\Kernel\Contracts\AuditLogServiceInterface;
use OCA\Decidiq\Kernel\Contracts\BoardEvaluationAccessGuardInterface;
use OCA\Decidiq\Kernel\Contracts\BoardEvaluationReportServiceInterface;
use OCA\Decidiq\Kernel\Contracts\BoardEvaluationResponseServiceInterface;
use OCA\Decidiq\Kernel\Contracts\BoardEvaluationScoreServiceInterface;
use OCA\Decidiq\Kernel\Contracts\BroadcastCaptionServiceInterface;
use OCA\Decidiq\Kernel\Contracts\BudgetVotingServiceInterface;
use OCA\Decidiq\Kernel\Contracts\CaseDocumentServiceInterface;
use OCA\Decidiq\Kernel\Contracts\CaseExchangeRecordsInterface;
use OCA\Decidiq\Kernel\Contracts\CaseSystemClientInterface;
use OCA\Decidiq\Kernel\Contracts\CaseSystemExchangeServiceInterface;
use OCA\Decidiq\Kernel\Contracts\CitizenAdviceServiceInterface;
use OCA\Decidiq\Kernel\Contracts\CommitmentProgressServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ConflictOfInterestServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ConnectionReportServiceInterface;
use OCA\Decidiq\Kernel\Contracts\CurrentAgendaItemServiceInterface;
use OCA\Decidiq\Kernel\Contracts\DashboardWidgetServiceInterface;
use OCA\Decidiq\Kernel\Contracts\DecisionAuthorityInterface;
use OCA\Decidiq\Kernel\Contracts\DecisionIntegrationAuthorizationGuardInterface;
use OCA\Decidiq\Kernel\Contracts\DecisionIntegrationServiceInterface;
use OCA\Decidiq\Kernel\Contracts\DecisionLifecycleServiceInterface;
use OCA\Decidiq\Kernel\Contracts\DecisionListServiceInterface;
use OCA\Decidiq\Kernel\Contracts\DecisionStageLabelRepairInterface;
use OCA\Decidiq\Kernel\Contracts\DecisionTypeRegistryInterface;
use OCA\Decidiq\Kernel\Contracts\DestructionCertificateRendererInterface;
use OCA\Decidiq\Kernel\Contracts\DossierCategoryInterface;
use OCA\Decidiq\Kernel\Contracts\DossierDispositionInterface;
use OCA\Decidiq\Kernel\Contracts\EngagementServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ExportBundleServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ExportBundleWriterInterface;
use OCA\Decidiq\Kernel\Contracts\FlowDecisionServiceInterface;
use OCA\Decidiq\Kernel\Contracts\FullExportServiceInterface;
use OCA\Decidiq\Kernel\Contracts\GovernanceBodyCommandServiceInterface;
use OCA\Decidiq\Kernel\Contracts\GovernanceBodyQueryServiceInterface;
use OCA\Decidiq\Kernel\Contracts\GovernanceReportingServiceInterface;
use OCA\Decidiq\Kernel\Contracts\GovernanceRoleScopeProjectorInterface;
use OCA\Decidiq\Kernel\Contracts\GovernanceScopeGuardInterface;
use OCA\Decidiq\Kernel\Contracts\GuestInvitationServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Decidiq\Kernel\Contracts\LiveDecisionServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MailVoteReplyProcessorInterface;
use OCA\Decidiq\Kernel\Contracts\MailVoteSignerInterface;
use OCA\Decidiq\Kernel\Contracts\MandateSubstitutionServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MeetingBroadcastServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MeetingFolderServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MeetingPackageServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MeetingReminderServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MeetingRoleGateInterface;
use OCA\Decidiq\Kernel\Contracts\MeetingSeriesServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MeetingServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MemberCompetenceServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MemberImportServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MinutesAccessGuardInterface;
use OCA\Decidiq\Kernel\Contracts\MinutesDocumentServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MinutesDraftServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MinutesGenerationServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MinutesWorkflowServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MotionCoauthorServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MotionServiceInterface;
use OCA\Decidiq\Kernel\Contracts\MotionStagesInterface;
use OCA\Decidiq\Kernel\Contracts\MultilingualReconciliationServiceInterface;
use OCA\Decidiq\Kernel\Contracts\NotificationPreferenceRequestValidatorInterface;
use OCA\Decidiq\Kernel\Contracts\NotificationPreferenceServiceInterface;
use OCA\Decidiq\Kernel\Contracts\OriPersonPublicationRuleInterface;
use OCA\Decidiq\Kernel\Contracts\OriPublicationServiceInterface;
use OCA\Decidiq\Kernel\Contracts\OriSerializerInterface;
use OCA\Decidiq\Kernel\Contracts\OriVotePublicationRuleInterface;
use OCA\Decidiq\Kernel\Contracts\PaperSummaryServiceInterface;
use OCA\Decidiq\Kernel\Contracts\PaperVoteRecorderInterface;
use OCA\Decidiq\Kernel\Contracts\ParticipantResolverInterface;
use OCA\Decidiq\Kernel\Contracts\ParticipantToPersonMembershipResolverInterface;
use OCA\Decidiq\Kernel\Contracts\ParticipationLifecycleServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ParticipationPublicationServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ParticipationResponderInterface;
use OCA\Decidiq\Kernel\Contracts\PlanningCycleGeneratorInterface;
use OCA\Decidiq\Kernel\Contracts\ProcessTemplateServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ProofPackageServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ProxyDelegationServiceInterface;
use OCA\Decidiq\Kernel\Contracts\ProxyVoteServiceInterface;
use OCA\Decidiq\Kernel\Contracts\PublicationConfigServiceInterface;
use OCA\Decidiq\Kernel\Contracts\PublicationDigestServiceInterface;
use OCA\Decidiq\Kernel\Contracts\PublicationEventRecorderInterface;
use OCA\Decidiq\Kernel\Contracts\PublicationServiceInterface;
use OCA\Decidiq\Kernel\Contracts\PublicationStaffGuardInterface;
use OCA\Decidiq\Kernel\Contracts\PublicMeetingRecordsInterface;
use OCA\Decidiq\Kernel\Contracts\PublicVotingRecordsInterface;
use OCA\Decidiq\Kernel\Contracts\ReactionIntakeServiceInterface;
use OCA\Decidiq\Kernel\Contracts\RecordingRangeInterface;
use OCA\Decidiq\Kernel\Contracts\RegulatorExportServiceInterface;
use OCA\Decidiq\Kernel\Contracts\RoleGroupMappingInterface;
use OCA\Decidiq\Kernel\Contracts\SeedProfileServiceInterface;
use OCA\Decidiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Decidiq\Kernel\Contracts\SigningRoundServiceInterface;
use OCA\Decidiq\Kernel\Contracts\SubjectClearanceServiceInterface;
use OCA\Decidiq\Kernel\Contracts\SystemWhenNobodySignedInInterface;
use OCA\Decidiq\Kernel\Contracts\TranscriptionQueueInterface;
use OCA\Decidiq\Kernel\Contracts\TranscriptionServiceInterface;
use OCA\Decidiq\Kernel\Contracts\TranscriptionStaffGuardInterface;
use OCA\Decidiq\Kernel\Contracts\TranscriptRepositoryInterface;
use OCA\Decidiq\Kernel\Contracts\VoteBreakdownServiceInterface;
use OCA\Decidiq\Kernel\Contracts\VotingBehaviourServiceInterface;
use OCA\Decidiq\Kernel\Contracts\VotingDeadlineReminderServiceInterface;
use OCA\Decidiq\Kernel\Contracts\VotingErrorResponderInterface;
use OCA\Decidiq\Kernel\Contracts\VotingOpenRequestHandlerInterface;
use OCA\Decidiq\Kernel\Contracts\VotingRecordServiceInterface;
use OCA\Decidiq\Kernel\Contracts\VotingRoundGuardInterface;
use OCA\Decidiq\Kernel\Contracts\VotingServiceInterface;
use OCA\Decidiq\Service\Records\DestructionCertificateRenderer;
use OCA\Decidiq\Service\Records\DossierCategory;
use OCA\Decidiq\Service\Records\DossierDisposition;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Decidiq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec exclude Structural composition, no functional requirement.
 */
final class ContractBindings {

	/**
	 * Alias every contract to its implementation.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec exclude Structural composition, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(ALVMinutesServiceInterface::class, ALVMinutesService::class);
		$context->registerServiceAlias(ActionItemAnalyticsServiceInterface::class, ActionItemAnalyticsService::class);
		$context->registerServiceAlias(ActionItemWriterInterface::class, ActionItemWriter::class);
		$context->registerServiceAlias(AgendaAuthorizationGuardInterface::class, AgendaAuthorizationGuard::class);
		$context->registerServiceAlias(AgendaServiceInterface::class, AgendaService::class);
		$context->registerServiceAlias(ApprovalRouteCommandServiceInterface::class, ApprovalRouteCommandService::class);
		$context->registerServiceAlias(ApprovalRouteConclusionAnnouncerInterface::class, ApprovalRouteConclusionAnnouncer::class);
		$context->registerServiceAlias(ApprovalRouteServiceInterface::class, ApprovalRouteService::class);
		$context->registerServiceAlias(ApprovalStageLapseServiceInterface::class, ApprovalStageLapseService::class);
		$context->registerServiceAlias(ApprovalStageTaskProjectorInterface::class, ApprovalStageTaskProjector::class);
		$context->registerServiceAlias(ArchivalDossierServiceInterface::class, ArchivalDossierService::class);
		$context->registerServiceAlias(AuditLogServiceInterface::class, AuditLogService::class);
		$context->registerServiceAlias(BoardEvaluationAccessGuardInterface::class, BoardEvaluationAccessGuard::class);
		$context->registerServiceAlias(BoardEvaluationReportServiceInterface::class, BoardEvaluationReportService::class);
		$context->registerServiceAlias(BoardEvaluationResponseServiceInterface::class, BoardEvaluationResponseService::class);
		$context->registerServiceAlias(BoardEvaluationScoreServiceInterface::class, BoardEvaluationScoreService::class);
		$context->registerServiceAlias(BroadcastCaptionServiceInterface::class, BroadcastCaptionService::class);
		$context->registerServiceAlias(BudgetVotingServiceInterface::class, BudgetVotingService::class);
		$context->registerServiceAlias(CaseDocumentServiceInterface::class, CaseDocumentService::class);
		$context->registerServiceAlias(CaseExchangeRecordsInterface::class, CaseExchangeRecords::class);
		$context->registerServiceAlias(CaseSystemClientInterface::class, CaseSystemClient::class);
		$context->registerServiceAlias(CaseSystemExchangeServiceInterface::class, CaseSystemExchangeService::class);
		$context->registerServiceAlias(CitizenAdviceServiceInterface::class, CitizenAdviceService::class);
		$context->registerServiceAlias(CommitmentProgressServiceInterface::class, CommitmentProgressService::class);
		$context->registerServiceAlias(ConflictOfInterestServiceInterface::class, ConflictOfInterestService::class);
		$context->registerServiceAlias(ConnectionReportServiceInterface::class, ConnectionReportService::class);
		$context->registerServiceAlias(CurrentAgendaItemServiceInterface::class, CurrentAgendaItemService::class);
		$context->registerServiceAlias(DashboardWidgetServiceInterface::class, DashboardWidgetService::class);
		$context->registerServiceAlias(DecisionAuthorityInterface::class, DecisionAuthority::class);
		$context->registerServiceAlias(DecisionIntegrationAuthorizationGuardInterface::class, DecisionIntegrationAuthorizationGuard::class);
		$context->registerServiceAlias(DecisionIntegrationServiceInterface::class, DecisionIntegrationService::class);
		$context->registerServiceAlias(DecisionLifecycleServiceInterface::class, DecisionLifecycleService::class);
		$context->registerServiceAlias(DecisionListServiceInterface::class, DecisionListService::class);
		$context->registerServiceAlias(DecisionStageLabelRepairInterface::class, DecisionStageLabelRepair::class);
		$context->registerServiceAlias(DecisionTypeRegistryInterface::class, DecisionTypeRegistry::class);
		$context->registerServiceAlias(DestructionCertificateRendererInterface::class, DestructionCertificateRenderer::class);
		$context->registerServiceAlias(DossierCategoryInterface::class, DossierCategory::class);
		$context->registerServiceAlias(DossierDispositionInterface::class, DossierDisposition::class);
		$context->registerServiceAlias(EngagementServiceInterface::class, EngagementService::class);
		$context->registerServiceAlias(ExportBundleServiceInterface::class, ExportBundleService::class);
		$context->registerServiceAlias(ExportBundleWriterInterface::class, ExportBundleWriter::class);
		$context->registerServiceAlias(FlowDecisionServiceInterface::class, FlowDecisionService::class);
		$context->registerServiceAlias(FullExportServiceInterface::class, FullExportService::class);
		$context->registerServiceAlias(GovernanceBodyCommandServiceInterface::class, GovernanceBodyCommandService::class);
		$context->registerServiceAlias(GovernanceBodyQueryServiceInterface::class, GovernanceBodyQueryService::class);
		$context->registerServiceAlias(GovernanceReportingServiceInterface::class, GovernanceReportingService::class);
		$context->registerServiceAlias(GovernanceRoleScopeProjectorInterface::class, GovernanceRoleScopeProjector::class);
		$context->registerServiceAlias(GovernanceScopeGuardInterface::class, GovernanceScopeGuard::class);
		$context->registerServiceAlias(GuestInvitationServiceInterface::class, GuestInvitationService::class);
		$context->registerServiceAlias(ListenerSchemaResolverInterface::class, ListenerSchemaResolver::class);
		$context->registerServiceAlias(LiveDecisionServiceInterface::class, LiveDecisionService::class);
		$context->registerServiceAlias(MailVoteReplyProcessorInterface::class, MailVoteReplyProcessor::class);
		$context->registerServiceAlias(MailVoteSignerInterface::class, MailVoteSigner::class);
		$context->registerServiceAlias(MandateSubstitutionServiceInterface::class, MandateSubstitutionService::class);
		$context->registerServiceAlias(MeetingBroadcastServiceInterface::class, MeetingBroadcastService::class);
		$context->registerServiceAlias(MeetingFolderServiceInterface::class, MeetingFolderService::class);
		$context->registerServiceAlias(MeetingPackageServiceInterface::class, MeetingPackageService::class);
		$context->registerServiceAlias(MeetingReminderServiceInterface::class, MeetingReminderService::class);
		$context->registerServiceAlias(MeetingRoleGateInterface::class, MeetingRoleGate::class);
		$context->registerServiceAlias(MeetingSeriesServiceInterface::class, MeetingSeriesService::class);
		$context->registerServiceAlias(MeetingServiceInterface::class, MeetingService::class);
		$context->registerServiceAlias(MemberCompetenceServiceInterface::class, MemberCompetenceService::class);
		$context->registerServiceAlias(MemberImportServiceInterface::class, MemberImportService::class);
		$context->registerServiceAlias(MinutesAccessGuardInterface::class, MinutesAccessGuard::class);
		$context->registerServiceAlias(MinutesDocumentServiceInterface::class, MinutesDocumentService::class);
		$context->registerServiceAlias(MinutesDraftServiceInterface::class, MinutesDraftService::class);
		$context->registerServiceAlias(MinutesGenerationServiceInterface::class, MinutesGenerationService::class);
		$context->registerServiceAlias(MinutesWorkflowServiceInterface::class, MinutesWorkflowService::class);
		$context->registerServiceAlias(MotionCoauthorServiceInterface::class, MotionCoauthorService::class);
		$context->registerServiceAlias(MotionServiceInterface::class, MotionService::class);
		$context->registerServiceAlias(MotionStagesInterface::class, MotionStages::class);
		$context->registerServiceAlias(MultilingualReconciliationServiceInterface::class, MultilingualReconciliationService::class);
		$context->registerServiceAlias(NotificationPreferenceRequestValidatorInterface::class, NotificationPreferenceRequestValidator::class);
		$context->registerServiceAlias(NotificationPreferenceServiceInterface::class, NotificationPreferenceService::class);
		$context->registerServiceAlias(OriPersonPublicationRuleInterface::class, OriPersonPublicationRule::class);
		$context->registerServiceAlias(OriPublicationServiceInterface::class, OriPublicationService::class);
		$context->registerServiceAlias(OriSerializerInterface::class, OriSerializer::class);
		$context->registerServiceAlias(OriVotePublicationRuleInterface::class, OriVotePublicationRule::class);
		$context->registerServiceAlias(PaperSummaryServiceInterface::class, PaperSummaryService::class);
		$context->registerServiceAlias(PaperVoteRecorderInterface::class, PaperVoteRecorder::class);
		$context->registerServiceAlias(ParticipantResolverInterface::class, ParticipantResolver::class);
		$context->registerServiceAlias(ParticipantToPersonMembershipResolverInterface::class, ParticipantToPersonMembershipResolver::class);
		$context->registerServiceAlias(ParticipationLifecycleServiceInterface::class, ParticipationLifecycleService::class);
		$context->registerServiceAlias(ParticipationPublicationServiceInterface::class, ParticipationPublicationService::class);
		$context->registerServiceAlias(ParticipationResponderInterface::class, ParticipationResponder::class);
		$context->registerServiceAlias(PlanningCycleGeneratorInterface::class, PlanningCycleGenerator::class);
		$context->registerServiceAlias(ProcessTemplateServiceInterface::class, ProcessTemplateService::class);
		$context->registerServiceAlias(ProofPackageServiceInterface::class, ProofPackageService::class);
		$context->registerServiceAlias(ProxyDelegationServiceInterface::class, ProxyDelegationService::class);
		$context->registerServiceAlias(ProxyVoteServiceInterface::class, ProxyVoteService::class);
		$context->registerServiceAlias(PublicMeetingRecordsInterface::class, PublicMeetingRecords::class);
		$context->registerServiceAlias(PublicVotingRecordsInterface::class, PublicVotingRecords::class);
		$context->registerServiceAlias(PublicationConfigServiceInterface::class, PublicationConfigService::class);
		$context->registerServiceAlias(PublicationDigestServiceInterface::class, PublicationDigestService::class);
		$context->registerServiceAlias(PublicationEventRecorderInterface::class, PublicationEventRecorder::class);
		$context->registerServiceAlias(PublicationServiceInterface::class, PublicationService::class);
		$context->registerServiceAlias(PublicationStaffGuardInterface::class, PublicationStaffGuard::class);
		$context->registerServiceAlias(ReactionIntakeServiceInterface::class, ReactionIntakeService::class);
		$context->registerServiceAlias(RecordingRangeInterface::class, RecordingRange::class);
		$context->registerServiceAlias(RegulatorExportServiceInterface::class, RegulatorExportService::class);
		$context->registerServiceAlias(RoleGroupMappingInterface::class, RoleGroupMapping::class);
		$context->registerServiceAlias(SeedProfileServiceInterface::class, SeedProfileService::class);
		$context->registerServiceAlias(SettingsServiceInterface::class, SettingsService::class);
		$context->registerServiceAlias(SigningRoundServiceInterface::class, SigningRoundService::class);
		$context->registerServiceAlias(SubjectClearanceServiceInterface::class, SubjectClearanceService::class);
		$context->registerServiceAlias(SystemWhenNobodySignedInInterface::class, SystemWhenNobodySignedIn::class);
		$context->registerServiceAlias(TranscriptRepositoryInterface::class, TranscriptRepository::class);
		$context->registerServiceAlias(TranscriptionQueueInterface::class, TranscriptionQueue::class);
		$context->registerServiceAlias(TranscriptionServiceInterface::class, TranscriptionService::class);
		$context->registerServiceAlias(TranscriptionStaffGuardInterface::class, TranscriptionStaffGuard::class);
		$context->registerServiceAlias(VoteBreakdownServiceInterface::class, VoteBreakdownService::class);
		$context->registerServiceAlias(VotingBehaviourServiceInterface::class, VotingBehaviourService::class);
		$context->registerServiceAlias(VotingDeadlineReminderServiceInterface::class, VotingDeadlineReminderService::class);
		$context->registerServiceAlias(VotingErrorResponderInterface::class, VotingErrorResponder::class);
		$context->registerServiceAlias(VotingOpenRequestHandlerInterface::class, VotingOpenRequestHandler::class);
		$context->registerServiceAlias(VotingRecordServiceInterface::class, VotingRecordService::class);
		$context->registerServiceAlias(VotingRoundGuardInterface::class, VotingRoundGuard::class);
		$context->registerServiceAlias(VotingServiceInterface::class, VotingService::class);
	}//end register()
}//end class
