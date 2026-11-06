<?php

/**
 * Decidiq Publication Payload Service
 *
 * Builds derived, immutable public payloads (allow-list construction) from
 * eligible governance objects, mapped to OpenRaadsinformatie record types.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/public-publication/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless payload builder.
 *
 * Every payload is constructed field-by-field from an allow-list — only the
 * named fields are copied across, so NC UIDs, contact details, individual
 * votes, and voter identities can never leak into a published object. Each
 * payload declares its `oriType` and carries the ORI-mapped fields the specs
 * cite (Besluit / Vergadering+AgendaPunt / Verslag).
 *
 * @spec openspec/specs/public-publication/spec.md
 */
class PublicationPayloadService {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (lazy ObjectService).
	 * @param LoggerInterface $logger Logger.
	 * @param PublicationConfigService $configService Publication configuration.
	 * @param AgendaPapers $agendaPapers Confidentiality check and papers of agenda items.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly PublicationConfigService $configService,
		private readonly AgendaPapers $agendaPapers,
	) {
	}//end __construct()

	/**
	 * Build a publication payload for an eligible source object.
	 *
	 * @param string $sourceType One of decision|agenda|minutes.
	 * @param array<string,mixed> $source The resolved source object data.
	 * @param string|null $bodyId UUID of the governance body (for policy lookup).
	 * @param int $version Payload version (incremented on rectify).
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * An agenda payload also carries `_publishedPapers` (agenda item + file of
	 * every paper it made public). That key is internal: PublicationService
	 * moves it onto the publication record before the payload is stored.
	 *
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-001-public-papers-are-published-with-the-agenda
	 *
	 * @return array<string,mixed> The allow-list payload, ready to persist.
	 */
	public function build(string $sourceType, array $source, ?string $bodyId, int $version = 1): array {
		switch ($sourceType) {
			case 'decision':
				$payload = $this->buildDecisionPayload(source: $source, version: $version);
				break;
			case 'agenda':
				$payload = $this->buildAgendaPayload(source: $source, version: $version);
				break;
			case 'minutes':
				$payload = $this->buildMinutesPayload(source: $source, bodyId: $bodyId, version: $version);
				break;
			case 'activity':
				$payload = $this->buildActivityPayload(source: $source, version: $version);
				break;
			default:
				throw new InvalidArgumentException('Unknown publication source type: ' . $sourceType);
		}

		$payload['documentType'] = $sourceType;
		return $payload;

	}//end build()

	/**
	 * Take the papers of a withdrawn publication offline again.
	 *
	 * @param array<int,mixed> $refs The record's publishedPapers.
	 * @param array<int,mixed> $keep Papers a newer version still publishes.
	 *
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-001-public-papers-are-published-with-the-agenda
	 *
	 * @return int The number of papers that could not be taken offline.
	 */
	public function withdrawPapers(array $refs, array $keep=[]): int {
		return $this->agendaPapers->unpublish(refs: $refs, keep: $keep);
	}//end withdrawPapers()

	/**
	 * Build a Besluit (decision) payload — totals only, never voters.
	 *
	 * 🔴 AN ALLOW-LIST OMITS BY DEFAULT, WHICH IS THE POINT AND ALSO THE RISK.
	 * This list carried `legalBasis` and not `legalRemedyClause`, so once the
	 * clause was finally stamped onto the decision the citizen reading the
	 * public publication was still told the legal ground the besluit rests on
	 * and not how to object to it. Nothing failed: the payload was valid, the
	 * publication succeeded, and the one field a person needs in order to
	 * disagree was simply not copied across.
	 *
	 * The clause is safe here by the same construction as the rest: it holds a
	 * remedy kind, a term, a body and a sentence, all of them written to be read
	 * by the public. It carries no identity of any kind.
	 *
	 * @param array<string,mixed> $source Decision object data.
	 * @param int $version Payload version.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
	 *
	 * @return array<string,mixed>
	 */
	private function buildDecisionPayload(array $source, int $version): array {
		$payload = [
			'oriType' => 'Besluit',
			'schemaOrgType' => 'ChooseAction',
			'payloadVersion' => $version,
			'title' => (string)($source['title'] ?? ''),
			'text' => (string)($source['text'] ?? ''),
			'outcome' => (string)($source['outcome'] ?? ''),
			'decisionDate' => ($source['decisionDate'] ?? null),
			'legalBasis' => (string)($source['legalBasis'] ?? ''),
			'bodyName' => $this->resolveBodyName(source: $source),
			'voteTotals' => $this->extractVoteTotals(source: $source),
		];

		$clause = $this->extractRemedyClause(source: $source);
		if ($clause !== null) {
			$payload['legalRemedyClause'] = $clause;
		}

		return $payload;

	}//end buildDecisionPayload()

	/**
	 * The remedy clause to publish, or null when the decision carries none.
	 *
	 * Copied field by field rather than passed through, so a clause that grew
	 * an internal field upstream cannot arrive on the public feed by accident.
	 * Omitted entirely when the decision was never stamped: an empty clause on
	 * a publication would read as "no remedy is open", which is a statement
	 * `geen` exists to make deliberately.
	 *
	 * @param array<string,mixed> $source Decision object data.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
	 *
	 * @return array<string,mixed>|null The clause, or null.
	 */
	private function extractRemedyClause(array $source): ?array {
		$clause = ($source['legalRemedyClause'] ?? null);
		if (is_array($clause) === false || $clause === []) {
			return null;
		}

		$kind = trim((string)($clause['kind'] ?? ''));
		$text = trim((string)($clause['text'] ?? ''));
		if ($kind === '' && $text === '') {
			return null;
		}

		return [
			'kind' => $kind,
			'termDays' => (int)($clause['termDays'] ?? 0),
			'body' => trim((string)($clause['body'] ?? '')),
			'text' => $text,
		];

	}//end extractRemedyClause()

	/**
	 * Build a Vergadering (agenda) payload — confidential items stripped, the
	 * papers of the public items published with them.
	 *
	 * @param array<string,mixed> $source Meeting object data.
	 * @param int $version Payload version.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-001-public-papers-are-published-with-the-agenda
	 *
	 * @throws \OCA\Decidiq\Exception\ConfidentialityUnreadableException When the confidentiality restrictions cannot be read.
	 *
	 * @return array<string,mixed>
	 */
	private function buildAgendaPayload(array $source, int $version): array {
		$items      = $this->resolveAgendaItems(meeting: $source);
		$restricted = $this->agendaPapers->restrictedItemIds();
		$published  = [];
		$paperRefs  = [];
		foreach ($items as $item) {
			$itemId = (string)($item['id'] ?? ($item['@self']['id'] ?? ''));
			if ($this->isConfidentialItem(item: $item) === true || isset($restricted[$itemId]) === true) {
				// Strip the confidential item and ALL of its document references.
				continue;
			}

			$papers      = $this->agendaPapers->publish(itemId: $itemId);
			$paperRefs   = array_merge($paperRefs, $papers['refs']);
			$published[] = [
				'oriType' => 'AgendaPunt',
				'order' => (int)($item['orderNumber'] ?? 0),
				'title' => (string)($item['title'] ?? ''),
				'papers' => $papers['papers'],
			];
		}

		// Preserve agenda order.
		usort(
			$published,
			static function (array $a, array $b): int {
				return ($a['order'] <=> $b['order']);
			}
		);

		return [
			'oriType' => 'Vergadering',
			'schemaOrgType' => 'Event',
			'payloadVersion' => $version,
			'title' => (string)($source['title'] ?? ''),
			'bodyName' => $this->resolveBodyName(source: $source),
			'meetingDate' => ($source['scheduledDate'] ?? null),
			'meetingType' => (string)($source['meetingType'] ?? ''),
			'agendaItems' => $published,
			'_publishedPapers' => $paperRefs,
		];

	}//end buildAgendaPayload()

	/**
	 * Build a calendar entry for a public meeting: when, what, who organises
	 * it, where, and who it is for. Allow-list only: no participant, no chair,
	 * no agenda item, no UID.
	 *
	 * @param array<string,mixed> $source  Meeting object data.
	 * @param int                 $version Payload version.
	 *
	 * @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-004-staff-publish-a-public-meeting-to-the-residents-calendar
	 *
	 * @return array<string,mixed>
	 */
	private function buildActivityPayload(array $source, int $version): array {
		$type      = $this->meetingType(source: $source);
		$audiences = [];
		foreach ((array)($type['audiences'] ?? []) as $audience) {
			if (is_string($audience) === true && $audience !== '') {
				$audiences[] = $audience;
			}
		}

		return [
			'oriType' => 'Vergadering',
			'schemaOrgType' => 'Event',
			'payloadVersion' => $version,
			'title' => (string)($source['title'] ?? ''),
			'bodyName' => $this->resolveBodyName(source: $source),
			'meetingDate' => ($source['scheduledDate'] ?? null),
			'meetingType' => (string)($type['name'] ?? $source['meetingType'] ?? ''),
			'location' => (string)($source['location'] ?? ''),
			'audiences' => $audiences,
		];
	}//end buildActivityPayload()

	/**
	 * The meeting's kind of meeting, or an empty array when it has none or it
	 * cannot be read (the entry is still published, without type and audiences).
	 *
	 * @param array<string,mixed> $source Meeting object data.
	 *
	 * @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-004-staff-publish-a-public-meeting-to-the-residents-calendar
	 *
	 * @return array<string,mixed>
	 */
	private function meetingType(array $source): array {
		$typeId = trim((string)($source['type'] ?? ''));
		if ($typeId === '') {
			return [];
		}

		try {
			$entity = $this->container->get('OCA\OpenRegister\Service\ObjectService')->find(id: $typeId, register: 'decidiq', schema: 'meeting-type');
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq publication: the meeting type could not be read', ['type' => $typeId, 'error' => $e->getMessage()]);
			return [];
		}

		if (is_object($entity) === false || method_exists($entity, 'jsonSerialize') === false) {
			return [];
		}

		return (array)$entity->jsonSerialize();
	}//end meetingType()

	/**
	 * Build a Verslag (minutes) payload — attendance per the body policy.
	 *
	 * @param array<string,mixed> $source Minutes object data.
	 * @param string|null $bodyId UUID of the governance body.
	 * @param int $version Payload version.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return array<string,mixed>
	 */
	private function buildMinutesPayload(array $source, ?string $bodyId, int $version): array {
		$policy = 'counts';
		if ($bodyId !== null && $bodyId !== '') {
			$policy = $this->configService->getForBody($bodyId)['attendance'];
		}

		return [
			'oriType' => 'Verslag',
			'schemaOrgType' => 'CreativeWork',
			'payloadVersion' => $version,
			'title' => (string)($source['title'] ?? ''),
			'bodyName' => $this->resolveBodyName(source: $source),
			'content' => (string)($source['content'] ?? ''),
			'attendance' => $this->renderAttendance(source: $source, policy: $policy),
		];

	}//end buildMinutesPayload()

	/**
	 * Extract for/against/abstain totals from a decision; never per-member votes.
	 *
	 * @param array<string,mixed> $source Decision object data.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return array{for:int,against:int,abstain:int}
	 */
	private function extractVoteTotals(array $source): array {
		// Prefer explicit aggregate fields on the decision; fall back to a
		// nested voteResult object. Per-member vote records are never read.
		$result = ($source['voteResult'] ?? $source['votingResult'] ?? $source);

		return [
			'for' => (int)($result['votesFor'] ?? $result['for'] ?? 0),
			'against' => (int)($result['votesAgainst'] ?? $result['against'] ?? 0),
			'abstain' => (int)($result['votesAbstain'] ?? $result['abstain'] ?? 0),
		];

	}//end extractVoteTotals()

	/**
	 * Render attendance for a minutes payload per the configured policy.
	 *
	 * 'counts' returns only a present count; 'role-holders' returns the names
	 * of role-holders. Neither shape ever contains NC UIDs or contact details.
	 *
	 * @param array<string,mixed> $source Minutes object data.
	 * @param string $policy 'counts' or 'role-holders'.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return array<string,mixed>
	 */
	private function renderAttendance(array $source, string $policy): array {
		$attendees = ($source['attendees'] ?? $source['attendance'] ?? []);
		if (is_array($attendees) === false) {
			$attendees = [];
		}

		if ($policy === 'role-holders') {
			$names = [];
			foreach ($attendees as $attendee) {
				if (is_array($attendee) === false) {
					continue;
				}

				$role = (string)($attendee['role'] ?? '');
				if (in_array($role, ['chair', 'secretary', 'voorzitter', 'griffier'], true) === false) {
					continue;
				}

				$name = (string)($attendee['displayName'] ?? $attendee['name'] ?? '');
				if ($name !== '') {
					$names[] = $name;
				}
			}

			return [
				'policy' => 'role-holders',
				'roleHolders' => $names,
			];
		}//end if

		return [
			'policy' => 'counts',
			'presentCount' => count($attendees),
		];

	}//end renderAttendance()

	/**
	 * Resolve the agenda items linked to a meeting.
	 *
	 * @param array<string,mixed> $meeting Meeting object data.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function resolveAgendaItems(array $meeting): array {
		// Inline agenda items take precedence when present (e.g. in tests).
		$inline = ($meeting['agendaItems'] ?? $meeting['items'] ?? null);
		if (is_array($inline) === true && $inline !== []) {
			return array_values(array_filter($inline, 'is_array'));
		}

		$meetingId = ($meeting['id'] ?? $meeting['uuid'] ?? ($meeting['@self']['id'] ?? null));
		if ($meetingId === null) {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$entities = $objectService->findAll(
				// Register and schema go INSIDE the filters: ObjectService reads
				// them there and ignores them at the top level, so this query
				// found no items and every published agenda was empty.
				[
					'filters' => [
						'register' => 'decidiq',
						'schema' => 'agenda-item',
						'meeting' => $meetingId,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq publication: failed to resolve agenda items', ['exception' => $e->getMessage()]);
			return [];
		}

		$items = [];
		foreach ($entities as $entity) {
			$items[] = $entity->jsonSerialize();
		}

		return $items;
	}//end resolveAgendaItems()

	/**
	 * Determine whether an agenda item is confidential and must be stripped.
	 *
	 * @param array<string,mixed> $item Agenda item data.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return bool
	 */
	private function isConfidentialItem(array $item): bool {
		if (($item['isConfidential'] ?? false) === true || ($item['confidential'] ?? false) === true) {
			return true;
		}

		$classification = strtolower((string)($item['confidentiality'] ?? $item['visibility'] ?? ''));

		return in_array($classification, ['confidential', 'secret', 'restricted', 'closed'], true);
	}//end isConfidentialItem()

	/**
	 * Resolve a public-safe governance body display name (never a UID).
	 *
	 * @param array<string,mixed> $source Source object data.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return string
	 */
	private function resolveBodyName(array $source): string {
		$bodyName = ($source['bodyName'] ?? $source['governanceBodyName'] ?? '');
		if (is_string($bodyName) === true && $bodyName !== '') {
			return $bodyName;
		}

		return '';
	}//end resolveBodyName()
}//end class
