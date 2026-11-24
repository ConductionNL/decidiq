<?php

/**
 * Decidiq Portal Field Sets
 *
 * The field whitelists and relation filters PortalContributionProvider
 * projects onto each portal collection and create action: what a resident
 * reads of their own records, which public fields a collection shows, and
 * which fields a resident may submit.
 *
 * @category Portal
 * @package  OCA\Decidiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-contribution/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Portal;

/**
 * Field whitelists of the portal contribution.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
final class PortalFieldSets {

	/**
	 * What a resident reads of their own consultation reactions.
	 *
	 * @var list<string>
	 */
	public const REACTION_READ_FIELDS = [
		'body',
		'submittedAt',
		'moderationStatus',
		'voteCount',
		'proposalTitle',
		'proposalAmount',
	];

	/**
	 * What a resident reads of their own advisory votes.
	 *
	 * @var list<string>
	 */
	public const VOTE_READ_FIELDS = [
		'voteValue',
		'motionId',
		'proposalId',
		'citizenPanelId',
		'weight',
		'isProxy',
		'castAt',
		'notes',
	];

	/**
	 * What a resident reads of their own budget proposals.
	 *
	 * @var list<string>
	 */
	public const BUDGET_PROPOSAL_READ_FIELDS = [
		'title',
		'description',
		'requestedAmount',
		'category',
		'status',
		'votesFor',
		'votesAgainst',
	];

	/**
	 * What a resident reads of their own notifications.
	 *
	 * @var list<string>
	 */
	public const NOTIFICATION_READ_FIELDS = [
		'type',
		'subject',
		'content',
		'channel',
		'status',
		'sentAt',
		'readAt',
	];

	/**
	 * What the council calendar shows of a publication payload.
	 *
	 * @var list<string>
	 */
	public const CALENDAR_FIELDS = [
		'title',
		'bodyName',
		'meetingDate',
		'meetingType',
		'location',
		'audiences',
		'oriType',
	];

	/**
	 * The calendar's declared labels, so the reader's language applies;
	 * without them portaliq heads the table with the schema's English titles.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public const CALENDAR_FIELD_CONFIGS = [
		'title' => ['label' => 'Meeting'],
		'meetingDate' => ['label' => 'Date'],
		'bodyName' => ['label' => 'Body'],
		'meetingType' => [
			'label' => 'Meeting type',
			'valueLabels' => [
				'regular' => 'Regular meeting',
				'extraordinary' => 'Extraordinary meeting',
				'committee' => 'Committee meeting',
				'public hearing' => 'Public hearing',
				'general_assembly' => 'General assembly',
			],
		],
		'location' => ['label' => 'Location'],
		'oriType' => [
			'label' => 'Kind',
			'valueLabels' => [
				'Vergadering' => 'Meeting',
				'Besluit' => 'Decision',
				'Verslag' => 'Minutes',
			],
		],
	];

	/**
	 * The calendar's table columns.
	 *
	 * @var list<array<string, string>>
	 */
	public const CALENDAR_COLUMNS = [
		['field' => 'meetingDate', 'render' => 'datetime'],
		['field' => 'title'],
		['field' => 'bodyName'],
		['field' => 'meetingType'],
	];

	/**
	 * What the public broadcasts list shows; never a staff field.
	 *
	 * @var list<string>
	 */
	public const BROADCAST_FIELDS = [
		'title',
		'eveningTitle',
		'bodyName',
		'scheduledDate',
		'lifecycle',
		'playerUrl',
		'recordingUrl',
		'captionTracks',
	];

	/**
	 * What a resident reads of a member's ancillary position (REQ-PLB-007).
	 *
	 * @var list<string>
	 */
	public const ANCILLARY_POSITION_FIELDS = [
		'person',
		'organisation',
		'role',
		'remunerated',
		'startDate',
		'endDate',
	];

	/**
	 * What the list of motions open for advice shows; who voted is never listed.
	 *
	 * @var list<string>
	 */
	public const ADVICE_MOTION_FIELDS = [
		'title',
		'text',
		'motionType',
		'citizenVotingStatus',
		'citizenAdviceFor',
		'citizenAdviceAgainst',
		'citizenAdviceAbstain',
	];

	/**
	 * What a resident reads of their own publication subscriptions.
	 *
	 * @var list<string>
	 */
	public const SUBSCRIPTION_READ_FIELDS = [
		'governanceBodies',
		'kinds',
		'frequency',
		'active',
		'lastSentAt',
	];

	/**
	 * What a resident sends to react to a consultation; never the scope
	 * field or a staff-only field.
	 *
	 * @var list<string>
	 */
	public const REACTION_CREATE_FIELDS = [
		'consultation',
		'body',
	];

	/**
	 * What a resident sends to submit a budget proposal.
	 *
	 * @var list<string>
	 */
	public const BUDGET_PROPOSAL_CREATE_FIELDS = [
		'participatoryBudget',
		'title',
		'description',
		'requestedAmount',
		'category',
	];

	/**
	 * What a resident sends to give advice on a motion.
	 *
	 * @var list<string>
	 */
	public const ADVICE_CREATE_FIELDS = [
		'motionId',
		'voteValue',
	];

	/**
	 * What a resident sends to follow publications.
	 *
	 * @var list<string>
	 */
	public const SUBSCRIBE_FIELDS = [
		'governanceBodies',
		'kinds',
		'frequency',
	];

	/**
	 * A reaction may only be created on a consultation that is open.
	 *
	 * @var array<string, string>
	 */
	public const OPEN_CONSULTATION = [
		'field' => 'consultation',
		'parentSchema' => 'public-consultation',
		'statusField' => 'status',
		'statusValue' => 'open',
	];

	/**
	 * A budget proposal may only be submitted while its budget takes submissions.
	 *
	 * @var array<string, string>
	 */
	public const BUDGET_IN_SUBMISSION = [
		'field' => 'participatoryBudget',
		'parentSchema' => 'participatory-budget',
		'statusField' => 'status',
		'statusValue' => 'submission',
	];

	/**
	 * Advice may only be given on a motion whose advisory vote is open.
	 *
	 * @var array<string, string>
	 */
	public const MOTION_OPEN_FOR_ADVICE = [
		'field' => 'motionId',
		'parentSchema' => 'decision',
		'statusField' => 'citizenVotingStatus',
		'statusValue' => 'open',
	];
}//end class
