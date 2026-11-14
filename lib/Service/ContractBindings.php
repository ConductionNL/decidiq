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
use OCA\Decidiq\Kernel\Contracts\BoardBookServiceInterface;
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
 *
 * @spec exclude Structural composition, no functional requirement.
 */
final class ContractBindings {

	/**
	 * Contract => the class that implements it.
	 *
	 * @var array<class-string, class-string>
	 */
	private const BINDINGS = [
		ALVMinutesServiceInterface::class => ALVMinutesService::class,
		ActionItemAnalyticsServiceInterface::class => ActionItemAnalyticsService::class,
		ActionItemWriterInterface::class => ActionItemWriter::class,
		AgendaAuthorizationGuardInterface::class => AgendaAuthorizationGuard::class,
		AgendaServiceInterface::class => AgendaService::class,
		ApprovalRouteCommandServiceInterface::class => ApprovalRouteCommandService::class,
		ApprovalRouteConclusionAnnouncerInterface::class => ApprovalRouteConclusionAnnouncer::class,
		ApprovalRouteServiceInterface::class => ApprovalRouteService::class,
		ApprovalStageLapseServiceInterface::class => ApprovalStageLapseService::class,
		ApprovalStageTaskProjectorInterface::class => ApprovalStageTaskProjector::class,
		ArchivalDossierServiceInterface::class => ArchivalDossierService::class,
		AuditLogServiceInterface::class => AuditLogService::class,
		BoardBookServiceInterface::class => BoardBookService::class,
		BoardEvaluationAccessGuardInterface::class => BoardEvaluationAccessGuard::class,
		BoardEvaluationReportServiceInterface::class => BoardEvaluationReportService::class,
		BoardEvaluationResponseServiceInterface::class => BoardEvaluationResponseService::class,
		BoardEvaluationScoreServiceInterface::class => BoardEvaluationScoreService::class,
		BroadcastCaptionServiceInterface::class => BroadcastCaptionService::class,
		BudgetVotingServiceInterface::class => BudgetVotingService::class,
		CaseDocumentServiceInterface::class => CaseDocumentService::class,
		CaseExchangeRecordsInterface::class => CaseExchangeRecords::class,
		CaseSystemClientInterface::class => CaseSystemClient::class,
		CaseSystemExchangeServiceInterface::class => CaseSystemExchangeService::class,
		CitizenAdviceServiceInterface::class => CitizenAdviceService::class,
		CommitmentProgressServiceInterface::class => CommitmentProgressService::class,
		ConflictOfInterestServiceInterface::class => ConflictOfInterestService::class,
		ConnectionReportServiceInterface::class => ConnectionReportService::class,
		CurrentAgendaItemServiceInterface::class => CurrentAgendaItemService::class,
		DashboardWidgetServiceInterface::class => DashboardWidgetService::class,
		DecisionAuthorityInterface::class => DecisionAuthority::class,
		DecisionIntegrationAuthorizationGuardInterface::class => DecisionIntegrationAuthorizationGuard::class,
		DecisionIntegrationServiceInterface::class => DecisionIntegrationService::class,
		DecisionLifecycleServiceInterface::class => DecisionLifecycleService::class,
		DecisionListServiceInterface::class => DecisionListService::class,
		DecisionStageLabelRepairInterface::class => DecisionStageLabelRepair::class,
		DecisionTypeRegistryInterface::class => DecisionTypeRegistry::class,
		DestructionCertificateRendererInterface::class => DestructionCertificateRenderer::class,
		DossierCategoryInterface::class => DossierCategory::class,
		DossierDispositionInterface::class => DossierDisposition::class,
		EngagementServiceInterface::class => EngagementService::class,
		ExportBundleServiceInterface::class => ExportBundleService::class,
		ExportBundleWriterInterface::class => ExportBundleWriter::class,
		FlowDecisionServiceInterface::class => FlowDecisionService::class,
		FullExportServiceInterface::class => FullExportService::class,
		GovernanceBodyCommandServiceInterface::class => GovernanceBodyCommandService::class,
		GovernanceBodyQueryServiceInterface::class => GovernanceBodyQueryService::class,
		GovernanceReportingServiceInterface::class => GovernanceReportingService::class,
		GovernanceRoleScopeProjectorInterface::class => GovernanceRoleScopeProjector::class,
		GovernanceScopeGuardInterface::class => GovernanceScopeGuard::class,
		GuestInvitationServiceInterface::class => GuestInvitationService::class,
		ListenerSchemaResolverInterface::class => ListenerSchemaResolver::class,
		LiveDecisionServiceInterface::class => LiveDecisionService::class,
		MailVoteReplyProcessorInterface::class => MailVoteReplyProcessor::class,
		MailVoteSignerInterface::class => MailVoteSigner::class,
		MandateSubstitutionServiceInterface::class => MandateSubstitutionService::class,
		MeetingBroadcastServiceInterface::class => MeetingBroadcastService::class,
		MeetingFolderServiceInterface::class => MeetingFolderService::class,
		MeetingPackageServiceInterface::class => MeetingPackageService::class,
		MeetingReminderServiceInterface::class => MeetingReminderService::class,
		MeetingRoleGateInterface::class => MeetingRoleGate::class,
		MeetingSeriesServiceInterface::class => MeetingSeriesService::class,
		MeetingServiceInterface::class => MeetingService::class,
		MemberCompetenceServiceInterface::class => MemberCompetenceService::class,
		MemberImportServiceInterface::class => MemberImportService::class,
		MinutesAccessGuardInterface::class => MinutesAccessGuard::class,
		MinutesDocumentServiceInterface::class => MinutesDocumentService::class,
		MinutesDraftServiceInterface::class => MinutesDraftService::class,
		MinutesGenerationServiceInterface::class => MinutesGenerationService::class,
		MinutesWorkflowServiceInterface::class => MinutesWorkflowService::class,
		MotionCoauthorServiceInterface::class => MotionCoauthorService::class,
		MotionServiceInterface::class => MotionService::class,
		MotionStagesInterface::class => MotionStages::class,
		MultilingualReconciliationServiceInterface::class => MultilingualReconciliationService::class,
		NotificationPreferenceRequestValidatorInterface::class => NotificationPreferenceRequestValidator::class,
		NotificationPreferenceServiceInterface::class => NotificationPreferenceService::class,
		OriPersonPublicationRuleInterface::class => OriPersonPublicationRule::class,
		OriPublicationServiceInterface::class => OriPublicationService::class,
		OriSerializerInterface::class => OriSerializer::class,
		OriVotePublicationRuleInterface::class => OriVotePublicationRule::class,
		PaperSummaryServiceInterface::class => PaperSummaryService::class,
		PaperVoteRecorderInterface::class => PaperVoteRecorder::class,
		ParticipantResolverInterface::class => ParticipantResolver::class,
		ParticipantToPersonMembershipResolverInterface::class => ParticipantToPersonMembershipResolver::class,
		ParticipationLifecycleServiceInterface::class => ParticipationLifecycleService::class,
		ParticipationPublicationServiceInterface::class => ParticipationPublicationService::class,
		ParticipationResponderInterface::class => ParticipationResponder::class,
		PlanningCycleGeneratorInterface::class => PlanningCycleGenerator::class,
		ProcessTemplateServiceInterface::class => ProcessTemplateService::class,
		ProofPackageServiceInterface::class => ProofPackageService::class,
		ProxyDelegationServiceInterface::class => ProxyDelegationService::class,
		ProxyVoteServiceInterface::class => ProxyVoteService::class,
		PublicMeetingRecordsInterface::class => PublicMeetingRecords::class,
		PublicVotingRecordsInterface::class => PublicVotingRecords::class,
		PublicationConfigServiceInterface::class => PublicationConfigService::class,
		PublicationDigestServiceInterface::class => PublicationDigestService::class,
		PublicationEventRecorderInterface::class => PublicationEventRecorder::class,
		PublicationServiceInterface::class => PublicationService::class,
		PublicationStaffGuardInterface::class => PublicationStaffGuard::class,
		ReactionIntakeServiceInterface::class => ReactionIntakeService::class,
		RecordingRangeInterface::class => RecordingRange::class,
		RegulatorExportServiceInterface::class => RegulatorExportService::class,
		RoleGroupMappingInterface::class => RoleGroupMapping::class,
		SeedProfileServiceInterface::class => SeedProfileService::class,
		SettingsServiceInterface::class => SettingsService::class,
		SigningRoundServiceInterface::class => SigningRoundService::class,
		SubjectClearanceServiceInterface::class => SubjectClearanceService::class,
		SystemWhenNobodySignedInInterface::class => SystemWhenNobodySignedIn::class,
		TranscriptRepositoryInterface::class => TranscriptRepository::class,
		TranscriptionQueueInterface::class => TranscriptionQueue::class,
		TranscriptionServiceInterface::class => TranscriptionService::class,
		TranscriptionStaffGuardInterface::class => TranscriptionStaffGuard::class,
		VoteBreakdownServiceInterface::class => VoteBreakdownService::class,
		VotingBehaviourServiceInterface::class => VotingBehaviourService::class,
		VotingDeadlineReminderServiceInterface::class => VotingDeadlineReminderService::class,
		VotingErrorResponderInterface::class => VotingErrorResponder::class,
		VotingOpenRequestHandlerInterface::class => VotingOpenRequestHandler::class,
		VotingRecordServiceInterface::class => VotingRecordService::class,
		VotingRoundGuardInterface::class => VotingRoundGuard::class,
		VotingServiceInterface::class => VotingService::class,
	];

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
		foreach (self::BINDINGS as $contract => $implementation) {
			$context->registerServiceAlias($contract, $implementation);
		}
	}//end register()
}//end class
