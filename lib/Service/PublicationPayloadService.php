<?php

/**
 * Decidesk Publication Payload Service
 *
 * Builds derived, immutable public payloads (allow-list construction) from
 * eligible governance objects, mapped to OpenRaadsinformatie record types.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
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

namespace OCA\Decidesk\Service;

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
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly PublicationConfigService $configService,
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
	 * @return array<string,mixed> The allow-list payload, ready to persist.
	 */
	public function build(string $sourceType, array $source, ?string $bodyId, int $version = 1): array {
		switch ($sourceType) {
			case 'decision':
				return $this->buildDecisionPayload(source: $source, version: $version);
			case 'agenda':
				return $this->buildAgendaPayload(source: $source, version: $version);
			case 'minutes':
				return $this->buildMinutesPayload(source: $source, bodyId: $bodyId, version: $version);
			default:
				throw new InvalidArgumentException('Unknown publication source type: ' . $sourceType);
		}

	}//end build()

	/**
	 * Build a Besluit (decision) payload — totals only, never voters.
	 *
	 * @param array<string,mixed> $source Decision object data.
	 * @param int $version Payload version.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return array<string,mixed>
	 */
	private function buildDecisionPayload(array $source, int $version): array {
		return [
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

	}//end buildDecisionPayload()

	/**
	 * Build a Vergadering (agenda) payload — confidential items stripped.
	 *
	 * @param array<string,mixed> $source Meeting object data.
	 * @param int $version Payload version.
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 *
	 * @return array<string,mixed>
	 */
	private function buildAgendaPayload(array $source, int $version): array {
		$items = $this->resolveAgendaItems(meeting: $source);
		$published = [];
		foreach ($items as $item) {
			if ($this->isConfidentialItem(item: $item) === true) {
				// Strip the confidential item and ALL of its document references.
				continue;
			}

			$published[] = [
				'oriType' => 'AgendaPunt',
				'order' => (int)($item['orderNumber'] ?? 0),
				'title' => (string)($item['title'] ?? ''),
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
		];

	}//end buildAgendaPayload()

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
				[
					'register' => 'decidesk',
					'schema' => 'agenda-item',
					'filters' => ['meeting' => $meetingId],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidesk publication: failed to resolve agenda items', ['exception' => $e->getMessage()]);
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
