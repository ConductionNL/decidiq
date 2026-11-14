<?php

/**
 * Decidiq Proof Package Service
 *
 * Assembles the notarial evidence package for a meeting's decision-making:
 * convocation record, quorum snapshot, votes tally, and the adopted decision
 * (resolution) texts — as structured JSON plus a human-readable markdown
 * rendition, sealed with a SHA-256 integrity hash and stored in the meeting's
 * Files folder.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @spec openspec/specs/resolution-minutes/spec.md
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Kernel\Contracts\ProofPackageServiceInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Notarial proof package assembly (resolution-minutes spec, "Provide proof of
 * proper adoption for notarial deed").
 *
 * Honesty contract: data that was never recorded (e.g. no attendance entries,
 * no convocation notice timestamp) is reported as `recorded: false` — the
 * package never fabricates evidence.
 *
 * @spec openspec/specs/resolution-minutes/spec.md
 */
class ProofPackageService implements ProofPackageServiceInterface {

	/**
	 * Minutes states that count as approved: approval comes before signing and publication.
	 *
	 * @var array<int, string>
	 */
	private const APPROVED_MINUTES_STATES = ['approved', 'signed', 'published'];

	/**
	 * Minutes states that carry the signatures.
	 *
	 * @var array<int, string>
	 */
	private const SIGNED_MINUTES_STATES = ['signed', 'published'];

	/**
	 * Attendance statuses counted as present for the quorum snapshot.
	 *
	 * @var string[]
	 */
	private const PRESENT_STATUSES = [
		'present',
		'in-person',
		'remote',
		'proxy',
	];

	/**
	 * Constructor for ProofPackageService.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param ParticipantResolver $participantResolver Canonical meeting → participants resolver
	 * @param MeetingFolderService $folderService Meeting Files folder writer
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ParticipantResolver $participantResolver,
		private readonly MeetingFolderService $folderService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Assemble the notarial proof package for a meeting and store it.
	 *
	 * @param string $meetingId UUID of the meeting
	 * @param string $generatedBy Display name of the requesting user (server session)
	 *
	 * @throws MissingObjectException When the meeting is not found
	 * @throws RuntimeException When OpenRegister or Files is unavailable
	 *
	 * @return array<string,mixed> Keys: files (string[]), sha256 (string), generatedAt (string).
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	public function assemble(string $meetingId, string $generatedBy): array {
		$objectService = $this->getObjectService();

		$meetingEntity = $objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting');
		if ($meetingEntity === null) {
			throw new MissingObjectException(
				message: sprintf('Meeting "%s" not found.', $meetingId)
			);
		}

		$meeting = $meetingEntity->jsonSerialize();

		$package = $this->buildPackage(objectService: $objectService, meeting: $meeting, meetingId: $meetingId);

		$generatedAt = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$canonical = $this->canonicalJson(data: $package);
		$sha256 = hash('sha256', $canonical);

		$envelope = [
			'package' => $package,
			'integrity' => [
				'algorithm' => 'sha256',
				'hash' => $sha256,
				'canonical' => 'JSON, recursively key-sorted, no whitespace, UTF-8',
				'verification' => 'Recompute sha256 over the canonical JSON of the "package" member and compare.',
			],
			'generatedAt' => $generatedAt,
			'generatedBy' => $generatedBy,
		];

		$files = $this->writePackageFiles(meeting: $meeting, envelope: $envelope);

		return [
			'files' => $files,
			'sha256' => $sha256,
			'generatedAt' => $generatedAt,
		];

	}//end assemble()

	/**
	 * Gather the evidence members of the proof package for a meeting.
	 *
	 * @param object $objectService OpenRegister ObjectService
	 * @param array<string, mixed> $meeting Serialized meeting
	 * @param string $meetingId UUID of the meeting
	 *
	 * @return array<string, mixed> Keys: meeting, convocation, quorum, votes, decisions
	 */
	private function buildPackage(object $objectService, array $meeting, string $meetingId): array {
		$agendaItems = $this->fetchRelated(objectService: $objectService, schema: 'agenda-item', meetingId: $meetingId);
		$votingRounds = $this->fetchRelated(objectService: $objectService, schema: 'voting-round', meetingId: $meetingId);
		$decisions = $this->fetchRelated(objectService: $objectService, schema: 'decision', meetingId: $meetingId);
		$minutes = $this->currentMinutes(rows: $this->fetchRelated(objectService: $objectService, schema: 'minutes', meetingId: $meetingId));
		$participants = $this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId);

		return [
			'meeting' => [
				'id' => ($meeting['id'] ?? ($meeting['@self']['id'] ?? $meetingId)),
				'title' => (string)($meeting['title'] ?? ''),
				'meetingType' => (string)($meeting['meetingType'] ?? ''),
				'scheduledDate' => (string)($meeting['scheduledDate'] ?? ''),
				'location' => (string)($meeting['location'] ?? ''),
				'lifecycle' => (string)($meeting['lifecycle'] ?? ''),
			],
			'convocation' => $this->buildConvocation(meeting: $meeting, agendaItems: $agendaItems),
			'quorum' => $this->buildQuorumSnapshot(meeting: $meeting, participants: $participants),
			'votes' => $this->buildVotesTally(votingRounds: $votingRounds),
			'decisions' => $this->buildDecisionTexts(decisions: $decisions),
			'minutes' => $this->buildMinutes(minutes: $minutes),
			'signatures' => $this->buildSignatures(minutes: $minutes),
		];

	}//end buildPackage()

	/**
	 * The current version of a meeting's minutes, when there are any.
	 *
	 * @param array<int, array<string, mixed>> $rows The minutes recorded for the meeting.
	 *
	 * @return array<string, mixed>|null The highest version, or null when none is recorded.
	 */
	private function currentMinutes(array $rows): ?array {
		$current = null;
		foreach ($rows as $row) {
			if ($current === null || (int)($row['version'] ?? 0) > (int)($current['version'] ?? 0)) {
				$current = $row;
			}
		}

		return $current;

	}//end currentMinutes()

	/**
	 * The minutes as evidence: their text once approved, otherwise their state.
	 *
	 * Minutes that are not approved are named with their current state rather
	 * than left out, so a reader sees why the package carries no minutes text.
	 *
	 * @param array<string, mixed>|null $minutes The current minutes, or null.
	 *
	 * @return array<string, mixed> The minutes member.
	 *
	 * @spec openspec/changes/capability-rows-get-requirements/specs/resolution-minutes/spec.md#requirement-a-meetings-evidence-is-bundled-into-one-proof-package
	 */
	private function buildMinutes(?array $minutes): array {
		if ($minutes === null) {
			return [
				'recorded' => false,
				'approved' => false,
				'state' => 'missing',
				'note' => 'No minutes are recorded for this meeting.',
			];
		}

		$state = (string)($minutes['lifecycle'] ?? 'draft');
		if (in_array($state, self::APPROVED_MINUTES_STATES, true) === false) {
			return [
				'recorded' => true,
				'approved' => false,
				'state' => $state,
				'version' => ($minutes['version'] ?? null),
				'note' => sprintf('The minutes are not approved; their current state is "%s".', $state),
			];
		}

		return [
			'recorded' => true,
			'approved' => true,
			'state' => $state,
			'id' => ($minutes['id'] ?? ($minutes['@self']['id'] ?? null)),
			'title' => (string)($minutes['title'] ?? ''),
			'version' => ($minutes['version'] ?? null),
			'approvedAt' => (string)($minutes['approvedAt'] ?? ''),
			'content' => (string)($minutes['content'] ?? ''),
		];

	}//end buildMinutes()

	/**
	 * The signatures on the minutes.
	 *
	 * @param array<string, mixed>|null $minutes The current minutes, or null.
	 *
	 * @return array{signed: bool, signedBy: array<int, string>} Whether they are signed, and by whom.
	 *
	 * @spec openspec/changes/capability-rows-get-requirements/specs/resolution-minutes/spec.md#requirement-a-meetings-evidence-is-bundled-into-one-proof-package
	 */
	private function buildSignatures(?array $minutes): array {
		$signers = [];
		foreach ((array)($minutes['signedBy'] ?? []) as $signer) {
			if (is_string($signer) === true && trim($signer) !== '') {
				$signers[] = trim($signer);
			}
		}

		return [
			'signed' => in_array((string)($minutes['lifecycle'] ?? ''), self::SIGNED_MINUTES_STATES, true),
			'signedBy' => $signers,
		];

	}//end buildSignatures()

	/**
	 * Write the sealed envelope as a JSON file and a Markdown rendering into
	 * the meeting's Minutes folder.
	 *
	 * @param array<string, mixed> $meeting Serialized meeting
	 * @param array<string, mixed> $envelope The sealed envelope
	 *
	 * @throws RuntimeException When encoding fails (500) or the Files backend is unavailable (503)
	 *
	 * @return string[] The JSON path, then the Markdown path
	 */
	private function writePackageFiles(array $meeting, array $envelope): array {
		$stamp = (new DateTimeImmutable())->format('Y-m-d Hi');
		$baseName = 'Proof package ' . $stamp;

		$jsonContent = json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($jsonContent === false) {
			throw new RuntimeException('Failed to encode the proof package.', 500);
		}

		$jsonPath = $this->folderService->writeMeetingFile(
			meeting: $meeting,
			subfolder: 'Minutes',
			fileName: $baseName . '.json',
			content: $jsonContent
		);

		$mdPath = $this->folderService->writeMeetingFile(
			meeting: $meeting,
			subfolder: 'Minutes',
			fileName: $baseName . '.md',
			content: $this->renderMarkdown(envelope: $envelope)
		);

		if ($jsonPath === null || $mdPath === null) {
			throw new RuntimeException(
				'The proof package could not be stored: the Files backend is unavailable.',
				503
			);
		}

		return [$jsonPath, $mdPath];

	}//end writePackageFiles()

	/**
	 * Build the convocation record — honest about unrecorded notice data.
	 *
	 * @param array<string,mixed> $meeting The meeting payload
	 * @param array<int,array<string,mixed>> $agendaItems The meeting's agenda items
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function buildConvocation(array $meeting, array $agendaItems): array {
		usort(
			$agendaItems,
			static function (array $a, array $b): int {
				return ($a['orderNumber'] ?? 0) <=> ($b['orderNumber'] ?? 0);
			}
		);

		$agenda = [];
		foreach ($agendaItems as $item) {
			$agenda[] = [
				'orderNumber' => ($item['orderNumber'] ?? null),
				'title' => (string)($item['title'] ?? ($item['name'] ?? '')),
				'itemType' => (string)($item['itemType'] ?? ''),
			];
		}

		// The meeting record carries no dedicated notice-sent timestamp; the
		// registration date of the meeting object is the earliest verifiable
		// moment the convocation data existed in the system.
		$registeredAt = (string)($meeting['@self']['created'] ?? ($meeting['created'] ?? ''));

		return [
			'scheduledDate' => (string)($meeting['scheduledDate'] ?? ''),
			'meetingRegisteredAt' => $registeredAt,
			'noticeRecorded' => false,
			'noticeNote' => 'No explicit convocation-notice timestamp is recorded on the meeting object; '
				. 'the meeting registration date and published agenda below are the available convocation evidence.',
			'agenda' => $agenda,
		];

	}//end buildConvocation()

	/**
	 * Build the quorum snapshot from the participant roll.
	 *
	 * @param array<string,mixed> $meeting The meeting payload
	 * @param array<int,array<string,mixed>> $participants The meeting's participants
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function buildQuorumSnapshot(array $meeting, array $participants): array {
		$roll = [];
		$present = 0;
		$attendanceRecorded = false;

		foreach ($participants as $participant) {
			$status = (string)($participant['attendanceStatus'] ?? '');
			if ($status !== '') {
				$attendanceRecorded = true;
			}

			if (in_array($status, self::PRESENT_STATUSES, true) === true) {
				$present++;
			}

			$roll[] = [
				'displayName' => (string)($participant['displayName'] ?? ''),
				'role' => (string)($participant['role'] ?? ''),
				'attendanceStatus' => $status,
				'votingWeight' => ($participant['votingWeight'] ?? null),
			];
		}

		$required = (int)($meeting['quorumRequired'] ?? 0);

		return [
			'total' => count($participants),
			'present' => $present,
			'required' => $required,
			'met' => ($required > 0 && $present >= $required),
			'attendanceRecorded' => $attendanceRecorded,
			'participants' => $roll,
		];

	}//end buildQuorumSnapshot()

	/**
	 * Build the votes tally from the meeting's voting rounds.
	 *
	 * @param array<int,array<string,mixed>> $votingRounds The meeting's voting rounds
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function buildVotesTally(array $votingRounds): array {
		$tally = [];
		foreach ($votingRounds as $round) {
			$tally[] = [
				'id' => ($round['id'] ?? ($round['@self']['id'] ?? null)),
				'votingMethod' => (string)($round['votingMethod'] ?? ''),
				'isSecret' => (bool)($round['isSecret'] ?? false),
				'openedAt' => (string)($round['openedAt'] ?? ''),
				'closedAt' => (string)($round['closedAt'] ?? ''),
				'votesFor' => ($round['votesFor'] ?? null),
				'votesAgainst' => ($round['votesAgainst'] ?? null),
				'votesAbstain' => ($round['votesAbstain'] ?? null),
				'result' => (string)($round['result'] ?? ''),
				'quorumWith' => ($round['quorumWith'] ?? null),
			];
		}

		return $tally;
	}//end buildVotesTally()

	/**
	 * Build the adopted decision (resolution) texts.
	 *
	 * @param array<int,array<string,mixed>> $decisions The meeting's decisions
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function buildDecisionTexts(array $decisions): array {
		$texts = [];
		foreach ($decisions as $decision) {
			$texts[] = [
				'id' => ($decision['id'] ?? ($decision['@self']['id'] ?? null)),
				'title' => (string)($decision['title'] ?? ''),
				'text' => (string)($decision['text'] ?? ''),
				'outcome' => (string)($decision['outcome'] ?? ''),
				'legalBasis' => (string)($decision['legalBasis'] ?? ''),
				'decisionDate' => (string)($decision['decisionDate'] ?? ''),
				'lifecycle' => (string)($decision['lifecycle'] ?? ''),
				'enactedAt' => (string)($decision['enactedAt'] ?? ''),
			];
		}

		return $texts;
	}//end buildDecisionTexts()

	/**
	 * Render the human-readable markdown rendition of the package.
	 *
	 * @param array<string,mixed> $envelope The package envelope
	 *
	 * @return string Markdown text
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function renderMarkdown(array $envelope): string {
		$package = $envelope['package'];
		$lines = [];

		$lines[] = '# Bewijs van rechtsgeldige besluitvorming / Proof of proper adoption';
		$lines[] = '';
		$lines[] = '**Vergadering:** ' . $package['meeting']['title'];
		$lines[] = '**Datum:** ' . $package['meeting']['scheduledDate'];
		$lines[] = '**Gegenereerd:** ' . $envelope['generatedAt'] . ' door ' . $envelope['generatedBy'];
		$lines[] = '**Integriteit (SHA-256):** `' . $envelope['integrity']['hash'] . '`';
		$lines[] = '';
		$lines[] = '## 1. Oproeping (convocatie)';
		$lines[] = '';
		$lines[] = 'Vergadering geregistreerd: ' . $package['convocation']['meetingRegisteredAt'];
		$lines[] = '';
		$lines[] = $package['convocation']['noticeNote'];
		$lines[] = '';
		foreach ($package['convocation']['agenda'] as $item) {
			$lines[] = sprintf('%s. %s', (string)($item['orderNumber'] ?? '-'), $item['title']);
		}

		$lines[] = '';
		$lines[] = '## 2. Quorum';
		$lines[] = '';
		$quorum = $package['quorum'];
		$withLabel = 'NEE / niet vastgesteld';
		if ($quorum['met'] === true) {
			$withLabel = 'JA';
		}

		$lines[] = sprintf(
			'Aanwezig: %d van %d (vereist: %d) — quorum behaald: %s',
			$quorum['present'],
			$quorum['total'],
			$quorum['required'],
			$withLabel
		);
		if ($quorum['attendanceRecorded'] === false) {
			$lines[] = '';
			$lines[] = '_Let op: er is geen aanwezigheidsregistratie vastgelegd voor deze vergadering._';
		}

		$lines = array_merge(
			$lines,
			$this->renderVotesSection(votes: $package['votes']),
			$this->renderDecisionsSection(decisions: $package['decisions']),
			$this->renderMinutesSection(minutes: $package['minutes'], signatures: $package['signatures'])
		);

		$lines[] = '---';
		$lines[] = '';
		$lines[] = '_De machineleesbare versie van dit pakket (JSON, naast dit bestand) bevat de SHA-256 '
			. 'integriteitshash; herbereken de hash over het canonieke JSON van het "package"-element '
			. 'om manipulatie uit te sluiten._';

		return implode("\n", $lines);
	}//end renderMarkdown()

	/**
	 * The minutes and signatures section of the readable package.
	 *
	 * @param array<string, mixed> $minutes    The minutes member.
	 * @param array<string, mixed> $signatures The signatures member.
	 *
	 * @return array<int, string> The lines.
	 */
	private function renderMinutesSection(array $minutes, array $signatures): array {
		$lines = ['## Notulen / Minutes', ''];
		if ($minutes['approved'] === true) {
			$lines[] = sprintf('%s (versie %s), vastgesteld %s', $minutes['title'], (string)($minutes['version'] ?? '-'), $minutes['approvedAt']);
			$lines[] = '';
			$lines[] = $minutes['content'];
		} else {
			$lines[] = '_' . $minutes['note'] . '_';
		}

		$lines[] = '';
		$lines[] = '### Ondertekening / Signatures';
		$lines[] = '';
		if ($signatures['signedBy'] === []) {
			$lines[] = '_Geen ondertekening vastgelegd._';
		}

		foreach ($signatures['signedBy'] as $signer) {
			$lines[] = '- ' . $signer;
		}

		$lines[] = '';

		return $lines;

	}//end renderMinutesSection()

	/**
	 * Render section 3 (Stemmingen) of the markdown rendition.
	 *
	 * @param array<int, array<string, mixed>> $votes The package's voting rounds
	 *
	 * @return string[] Markdown lines
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function renderVotesSection(array $votes): array {
		$lines = [];
		$lines[] = '';
		$lines[] = '## 3. Stemmingen';
		$lines[] = '';
		if (count($votes) === 0) {
			$lines[] = '_Geen stemrondes geregistreerd._';
		}

		foreach ($votes as $round) {
			$lines[] = sprintf(
				'- Methode: %s — voor: %s, tegen: %s, onthouding: %s — uitslag: %s',
				$round['votingMethod'],
				(string)($round['votesFor'] ?? '?'),
				(string)($round['votesAgainst'] ?? '?'),
				(string)($round['votesAbstain'] ?? '?'),
				$round['result']
			);
		}

		return $lines;
	}//end renderVotesSection()

	/**
	 * Render section 4 (Besluiten) of the markdown rendition.
	 *
	 * @param array<int, array<string, mixed>> $decisions The package's decision texts
	 *
	 * @return string[] Markdown lines
	 *
	 * @spec openspec/specs/resolution-minutes/spec.md
	 */
	private function renderDecisionsSection(array $decisions): array {
		$lines = [];
		$lines[] = '';
		$lines[] = '## 4. Besluiten';
		$lines[] = '';
		if (count($decisions) === 0) {
			$lines[] = '_Geen besluiten geregistreerd._';
		}

		foreach ($decisions as $decision) {
			$lines[] = '### ' . $decision['title'];
			if ($decision['legalBasis'] !== '') {
				$lines[] = 'Gelet op: ' . $decision['legalBasis'];
			}

			$lines[] = 'Uitkomst: ' . $decision['outcome'] . ' (' . $decision['decisionDate'] . ')';
			if ($decision['text'] !== '') {
				$lines[] = '';
				$lines[] = $decision['text'];
			}

			$lines[] = '';
		}

		return $lines;
	}//end renderDecisionsSection()

	/**
	 * Canonical JSON: recursively key-sorted, no whitespace — the stable
	 * input for the integrity hash.
	 *
	 * @param mixed $data The data to canonicalise
	 *
	 * @return string Canonical JSON string
	 */
	private function canonicalJson(mixed $data): string {
		$sorted = $this->ksortRecursive(data: $data);
		$encoded = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($encoded === false) {
			throw new RuntimeException('Failed to canonicalise the proof package.', 500);
		}

		return $encoded;
	}//end canonicalJson()

	/**
	 * Recursively sort associative arrays by key (lists keep their order).
	 *
	 * @param mixed $data The data to sort
	 *
	 * @return mixed The key-sorted data
	 */
	private function ksortRecursive(mixed $data): mixed {
		if (is_array($data) === false) {
			return $data;
		}

		foreach ($data as $key => $value) {
			$data[$key] = $this->ksortRecursive(data: $value);
		}

		if (array_is_list($data) === false) {
			ksort($data);
		}

		return $data;
	}//end ksortRecursive()

	/**
	 * Fetch ALL objects of a schema linked to the meeting (paged).
	 *
	 * @param object $objectService OpenRegister ObjectService
	 * @param string $schema The schema slug
	 * @param string $meetingId The meeting UUID
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fetchRelated(object $objectService, string $schema, string $meetingId): array {
		$pageSize = 100;
		$offset = 0;
		$result = [];

		$objectService->setRegister('decidiq');
		$objectService->setSchema($schema);

		do {
			try {
				$entities = $objectService->findAll(
					[
						'filters' => [
							'register' => 'decidiq',
							'schema' => $schema,
							'_relations.meeting' => $meetingId,
						],
						'limit' => $pageSize,
						'offset' => $offset,
					]
				);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Decidiq: failed to fetch related objects for proof package',
					['schema' => $schema, 'meetingId' => $meetingId, 'error' => $e->getMessage()]
				);
				break;
			}

			$page = [];
			foreach ($entities as $entity) {
				if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
					$page[] = (array)$entity->jsonSerialize();
				} elseif (is_array($entity) === true) {
					$page[] = $entity;
				}
			}

			$pageCount = count($page);
			$result = array_merge($result, $page);
			$offset += $pageSize;
		} while ($pageCount === $pageSize);

		return $result;
	}//end fetchRelated()

	/**
	 * Lazy-load the OpenRegister ObjectService from the container.
	 *
	 * @return object The OpenRegister ObjectService instance
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable.
		return $this->objectService;
	}//end getObjectService()
}//end class
