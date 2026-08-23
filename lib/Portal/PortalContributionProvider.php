<?php

/**
 * Decidiq Portal Contribution Provider
 *
 * Decidiq's contribution to the shared Portaliq external portal (hydra
 * ADR-046, contribution contract v2.2). Portaliq — the ONE shared portal for
 * people WITHOUT a Nextcloud account — discovers this class by convention FQCN
 * (`OCA\{Namespace}\Portal\PortalContributionProvider`) and duck-types it via
 * method_exists(), never instanceof. This class is therefore deliberately
 * PLAIN: no portaliq imports, no `implements` clause, no info.xml dependency,
 * no constructor dependencies. Without portaliq installed it is inert and
 * Decidiq behaves exactly as before (amendment A1).
 *
 * It declares — for the `citizen` audience (a resident participating in a
 * consultation, participatory-budget round or advisory vote WITHOUT a Nextcloud
 * account) — the OpenRegister collections that subject may read and the field
 * whitelists projected onto each. DigiD/eHerkenning is DEFERRED: citizens log
 * in through portaliq's ordinary password/`portalAccount` edge at trust `low`,
 * exactly like pipelinq's `client` / `customer` audiences.
 *
 * Decidiq's citizen data is ALREADY portal-shaped: the scope fields
 * `submitterId` / `voterId` / `submitter` / `recipientId` each hold a Nextcloud
 * UID OR an opaque pseudonymous token. For an accountless portal citizen the
 * value IS the pseudonymous token, which portaliq derives as the subject's
 * `subjectRef`. Every collection therefore scopes by the DEFAULT subjectRef (no
 * `scopeClaim`, no broker, no BSN): `scopeField == subjectRef`. See
 * openspec/changes/portal-contribution/design.md for the scoping rationale, the
 * field-whitelist tables and the deferred creates + public-list surfaces.
 *
 * @category Portal
 * @package  OCA\Decidiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Portal;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Declares what an external portal subject may see and do in Decidiq.
 *
 * The contribution is a declarative manifest (pure data — no I/O, no
 * callbacks). All subject identity (subjectRef, audience, organisation, trust)
 * is derived server-side by portaliq's auth edge and MUST never be trusted from
 * the client (ADR-005). Scoping uses the subject's own pseudonymous token
 * (== subjectRef) as the scope value, because Decidiq's citizen records already
 * carry that token in their scope field — never a Nextcloud user id, because
 * portal citizens have no Nextcloud account by premise (amendment A4).
 *
 * Every read collection ships an explicit `fields` whitelist so portaliq (which
 * whitelist-projects rows AFTER per-row verification — identifiers always
 * survive, a malformed declaration degrades to identifiers-only) never hands a
 * subject a staff/moderation or other-citizen column. `citizenNotifications`
 * is a `kind: 'inbox'` collection scoped by `recipientId`. This wave declares
 * read + inbox collections only: both creates (react to a consultation, submit
 * a budget proposal) are DEFERRED because the parent relation they need has no
 * writable scalar property to whitelist and cannot be constrained to an open
 * parent by the flat create vocabulary — see design.md "Deferred creates".
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 * @spec openspec/specs/portal-citizen-create-actions/spec.md
 */
class PortalContributionProvider {
	/**
	 * The OpenRegister register slug every collection below lives in.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidesk';

	/**
	 * The human label portaliq renders for this app's portal section.
	 *
	 * @var string
	 */
	private const LABEL = 'Decidiq';

	/**
	 * The audiences this provider contributes to (contract v2, preferred).
	 *
	 * The registry probes for this method first. Decidiq serves accountless
	 * residents participating in citizen-participation surfaces (`citizen`).
	 *
	 * @return array<int, string> The audience identifiers.
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 */
	public function getAudiences(): array {
		return ['citizen'];
	}//end getAudiences()

	/**
	 * The primary audience this provider contributes to (contract v1 fallback).
	 *
	 * Kept alongside getAudiences() so the provider also works against a v1
	 * registry that predates multi-audience support.
	 *
	 * @return string The primary audience identifier.
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 */
	public function getAudience(): string {
		return 'citizen';
	}//end getAudience()

	/**
	 * Build the declarative portal manifest for one resolved subject.
	 *
	 * The subject array is server-derived by portaliq (subjectRef token,
	 * audience, organisation, trust level low|substantial|high). Returns null
	 * for any audience Decidiq does not serve (fail-closed; the registry
	 * already filters by audience, but a provider must not rely on that).
	 *
	 * @param array<string, mixed> $subject The resolved portal subject.
	 *
	 * @return array<string, mixed>|null The manifest, or null when not contributing.
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 */
	public function getContribution(array $subject): ?array {
		$audience = ($subject['audience'] ?? '');

		if ($audience === 'citizen') {
			return $this->citizenContribution();
		}

		return null;
	}//end getContribution()

	/**
	 * Manifest for the `citizen` audience (an accountless resident).
	 *
	 * Four read/inbox collections, each scoped by the DEFAULT subjectRef (the
	 * pseudonymous token the record already stores in its scope field), gated at
	 * `minTrust: low` (portaliq's password edge — DigiD/eHerkenning deferred):
	 *
	 * - `citizenReactions` (`consultation-reaction`, scope `submitterId`) — the
	 *   citizen's own consultation reactions, projected to their own content +
	 *   own-submission status; the staff-set `moderationReason` and the WOO/DIWOO
	 *   moderator publication controls (`publicationDate`, `depublicationDate`)
	 *   are dropped.
	 * - `citizenVotes` (`citizen-vote`, scope `voterId`) — the citizen's own
	 *   advisory votes; the schema carries no staff/moderation column.
	 * - `citizenBudgetProposals` (`budget-proposal`, scope `submitter`) — the
	 *   citizen's own participatory-budget proposals, including their own
	 *   proposal's lifecycle status and public vote tallies.
	 * - `citizenNotifications` (`notification`, scope `recipientId`,
	 *   `kind: 'inbox'`) — the citizen's own notification inbox.
	 *
	 * `actions` carries two `type: create` entries (portal-citizen-create-actions,
	 * REQ-DKPCA-001/002/003/004), each `minTrust: low`:
	 *
	 * - `createReaction` (`consultation-reaction`) — client whitelist
	 *   `{consultation, body}`; the scope field `submitterId` is stamped from the
	 *   resolved subjectRef by portaliq's writer (never client-writable), and
	 *   `defaults` stamps the intake state `moderationStatus: 'pending'` plus
	 *   `submittedAt` (now) server-side, over the whitelist. `parentConstraint`
	 *   declares (and Decidiq's `PortalCreateOpenParentGuardListener` enforces,
	 *   fail-closed) that the parent `PublicConsultation` must be `status: 'open'`.
	 * - `createBudgetProposal` (`budget-proposal`) — client whitelist
	 *   `{participatoryBudget, title, description, requestedAmount, category}`;
	 *   scope field `submitter` is stamped from subjectRef; `defaults` stamps
	 *   `status: 'submitted'` (no `submittedAt` — the schema has none).
	 *   `parentConstraint` requires the parent `ParticipatoryBudget` to be
	 *   `status: 'submission'`.
	 *
	 * Neither whitelist EVER carries the scope field or a lifecycle/staff-only
	 * field (`moderationReason`, `publicationDate`, `depublicationDate`,
	 * `voteCount`, `votesFor`, `votesAgainst`) — closing the write-IDOR class
	 * filed as portaliq#16. Portaliq's shared create receiver (contract v2.2)
	 * consumes `scopeField` + `defaults`; it does not read `parentConstraint`, so
	 * the open-parent invariant is ALSO enforced server-side by Decidiq (see
	 * `PortalCreateOpenParentGuardListener`'s docblock for why identification is
	 * field-signature based rather than schema-slug based).
	 *
	 * `notifications` stays empty (the manifest-level dispatch key, distinct
	 * from the inbox collection).
	 *
	 * @return array<string, mixed> The citizen manifest.
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 * @spec openspec/specs/portal-citizen-create-actions/spec.md
	 */
	private function citizenContribution(): array {
		return [
			'label' => self::LABEL,
			'collections' => $this->citizenCollections(),
			'actions' => $this->citizenActions(),
			'notifications' => [],
		];

	}//end citizenContribution()

	/**
	 * The four read/inbox collections on the `citizen` manifest (see
	 * {@see citizenContribution()} for the documented scoping/projection
	 * rationale).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 */
	private function citizenCollections(): array {
		return [
			[
				'id' => 'citizenReactions',
				'register' => self::REGISTER,
				'schema' => 'consultation-reaction',
				'scopeField' => 'submitterId',
				'label' => 'My consultation reactions',
				'listable' => true,
				'minTrust' => 'low',
				'fields' => [
					'body',
					'submittedAt',
					'moderationStatus',
					'voteCount',
					'proposalTitle',
					'proposalAmount',
				],
			],
			[
				'id' => 'citizenVotes',
				'register' => self::REGISTER,
				'schema' => 'citizen-vote',
				'scopeField' => 'voterId',
				'label' => 'My votes',
				'listable' => true,
				'minTrust' => 'low',
				'fields' => [
					'voteValue',
					'motionId',
					'proposalId',
					'citizenPanelId',
					'weight',
					'isProxy',
					'castAt',
					'notes',
				],
			],
			[
				'id' => 'citizenBudgetProposals',
				'register' => self::REGISTER,
				'schema' => 'budget-proposal',
				'scopeField' => 'submitter',
				'label' => 'My budget proposals',
				'listable' => true,
				'minTrust' => 'low',
				'fields' => [
					'title',
					'description',
					'requestedAmount',
					'category',
					'status',
					'votesFor',
					'votesAgainst',
				],
			],
			[
				'id' => 'citizenNotifications',
				'register' => self::REGISTER,
				'schema' => 'notification',
				'scopeField' => 'recipientId',
				'kind' => 'inbox',
				'label' => 'My notifications',
				'listable' => true,
				'minTrust' => 'low',
				'fields' => [
					'type',
					'subject',
					'content',
					'channel',
					'status',
					'sentAt',
					'readAt',
				],
			],
		];

	}//end citizenCollections()

	/**
	 * The two `type: create` actions on the `citizen` manifest (see
	 * {@see citizenContribution()} for the documented whitelist/stamp/
	 * parentConstraint rationale, portal-citizen-create-actions
	 * REQ-DKPCA-001/002).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/portal-citizen-create-actions/spec.md
	 */
	private function citizenActions(): array {
		return [
			[
				'id' => 'createReaction',
				'type' => 'create',
				'label' => 'React to this consultation',
				'register' => self::REGISTER,
				'schema' => 'consultation-reaction',
				'scopeField' => 'submitterId',
				'minTrust' => 'low',
				'fields' => [
					'consultation',
					'body',
				],
				'defaults' => [
					'moderationStatus' => 'pending',
					'submittedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
				],
				'parentConstraint' => [
					'field' => 'consultation',
					'parentSchema' => 'public-consultation',
					'statusField' => 'status',
					'statusValue' => 'open',
				],
			],
			[
				'id' => 'createBudgetProposal',
				'type' => 'create',
				'label' => 'Submit a budget proposal',
				'register' => self::REGISTER,
				'schema' => 'budget-proposal',
				'scopeField' => 'submitter',
				'minTrust' => 'low',
				'fields' => [
					'participatoryBudget',
					'title',
					'description',
					'requestedAmount',
					'category',
				],
				'defaults' => [
					'status' => 'submitted',
				],
				'parentConstraint' => [
					'field' => 'participatoryBudget',
					'parentSchema' => 'participatory-budget',
					'statusField' => 'status',
					'statusValue' => 'submission',
				],
			],
		];

	}//end citizenActions()
}//end class
