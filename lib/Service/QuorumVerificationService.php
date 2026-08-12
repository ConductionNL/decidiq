<?php

/**
 * Decidesk Quorum Verification Service
 *
 * Computes meeting quorum from meeting attendance + active proxies and
 * exposes a structured report per member (in-person, remote, proxy,
 * absent).
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless service that walks a meeting's attendance map and the active
 * proxies to compute whether quorum is met. Threshold defaults to the body's
 * `quorumRule` (e.g. "simple-majority" = ceil(n/2)).
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
 */
class QuorumVerificationService {

	/**
	 * Allowed participant-type tokens.
	 *
	 * @var string[]
	 */
	public const PARTICIPANT_TYPES = ['in-person', 'remote', 'proxy', 'absent'];

	/**
	 * Constructor for QuorumVerificationService.
	 *
	 * @param ContainerInterface $container The DI container (used to resolve ObjectService)
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute the quorum status of a meeting. Returns an associative array
	 * with the present count, the threshold, and a boolean met flag.
	 *
	 * @param string $meetingId UUID of the board meeting
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
	 *
	 * @return array{total: int, present: int, threshold: int, met: bool}
	 */
	public function computeQuorum(string $meetingId): array {
		$report = $this->getAttendanceReport(meetingId: $meetingId);
		if ($report === []) {
			return [
				'total' => 0,
				'present' => 0,
				'threshold' => 0,
				'met' => false,
			];
		}

		$present = 0;
		foreach ($report['members'] as $member) {
			if (in_array($member['status'], ['in-person', 'remote', 'proxy'], true) === true) {
				$present++;
			}
		}

		$threshold = $report['threshold'];
		return [
			'total' => $report['total'],
			'present' => $present,
			'threshold' => $threshold,
			'met' => ($threshold > 0 && $present >= $threshold),
		];

	}//end computeQuorum()

	/**
	 * Build an attendance report for a meeting: list of board members with
	 * their resolved attendance status plus the per-board total and threshold.
	 *
	 * The meeting object is expected to carry:
	 *  - `boardKoppeling` (board UUID)
	 *  - `attendance` (array of { boardMemberKoppeling, mode })
	 *  - `quorumRequired` (optional, overrides board-level computation)
	 *
	 * Returns an empty array when the meeting cannot be resolved or the
	 * OpenRegister lookup fails; callers must treat `[]` as "unknown".
	 *
	 * @param string $meetingId UUID of the board meeting
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
	 *
	 * @return array{}|array{total: int, threshold: int, members: array<int, array{boardMemberKoppeling: string, status: string}>}
	 */
	public function getAttendanceReport(string $meetingId): array {
		$context = $this->loadAttendanceContext(meetingId: $meetingId);
		if ($context === null) {
			return [];
		}

		$members = $this->buildMemberRows(
			rows: $context['members'],
			boardId: $context['boardId'],
			attendanceMap: $this->buildAttendanceMap(meetingData: $context['meeting'])
		);

		$total = count($members);

		return [
			'total' => $total,
			'threshold' => $this->resolveThreshold(meetingData: $context['meeting'], total: $total),
			'members' => $members,
		];

	}//end getAttendanceReport()

	/**
	 * Load the meeting and its board's membership rows from OpenRegister.
	 *
	 * Returns null when the meeting cannot be resolved or the lookup fails —
	 * callers translate that into the "unknown" empty report.
	 *
	 * @param string $meetingId UUID of the board meeting
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
	 *
	 * @return array{meeting: array<string, mixed>, boardId: string, members: mixed}|null
	 */
	private function loadAttendanceContext(string $meetingId): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

			$meeting = $objectService->find(id: $meetingId, register: 'decidesk', schema: 'meeting');
			if ($meeting === null) {
				return null;
			}

			$meetingData = $this->toArray(row: $meeting);
			$boardId = (string)($meetingData['boardKoppeling'] ?? '');

			return [
				'meeting' => $meetingData,
				'boardId' => $boardId,
				'members' => $objectService->findAll(
					[
						'register' => 'decidesk',
						'schema' => 'membership',
						'filters' => ['boardKoppeling' => $boardId],
						'limit' => 1000,
					]
				),
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Decidesk: failed to build attendance report',
				['meetingId' => $meetingId, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end loadAttendanceContext()

	/**
	 * Index the meeting's attendance entries by board-member uuid.
	 *
	 * @param array<string, mixed> $meetingData Meeting object data
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
	 *
	 * @return array<string, string> Board-member uuid => attendance mode
	 */
	private function buildAttendanceMap(array $meetingData): array {
		$attendanceMap = [];
		foreach ((array)($meetingData['attendance'] ?? []) as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$uid = (string)($entry['boardMemberKoppeling'] ?? '');
			if ($uid !== '') {
				$attendanceMap[$uid] = (string)($entry['mode'] ?? 'absent');
			}
		}

		return $attendanceMap;
	}//end buildAttendanceMap()

	/**
	 * Project the board's membership rows onto the attendance report shape.
	 *
	 * @param mixed $rows Membership rows as returned by findAll()
	 * @param string $boardId Board uuid the meeting belongs to
	 * @param array<string, string> $attendanceMap Board-member uuid => attendance mode
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-2.4
	 *
	 * @return array<int, array{boardMemberKoppeling: string, status: string}>
	 */
	private function buildMemberRows(mixed $rows, string $boardId, array $attendanceMap): array {
		$members = [];
		foreach ((array)$rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = (array)$row->jsonSerialize();
			}

			if (is_array($row) === false) {
				continue;
			}

			// Honour the boardKoppeling filter client-side in case the underlying
			// findAll() implementation ignores the filters config (older stubs).
			if ($boardId !== '' && isset($row['boardKoppeling']) === true && (string)$row['boardKoppeling'] !== $boardId) {
				continue;
			}

			$memberId = (string)($row['id'] ?? $row['uuid'] ?? '');
			$status = ($attendanceMap[$memberId] ?? 'absent');
			if (in_array($status, self::PARTICIPANT_TYPES, true) === false) {
				$status = 'absent';
			}

			$members[] = [
				'boardMemberKoppeling' => $memberId,
				'status' => $status,
			];
		}//end foreach

		return $members;
	}//end buildMemberRows()

	/**
	 * Resolve the integer quorum threshold for the meeting.
	 *
	 * @param array<string, mixed> $meetingData Meeting object data
	 * @param int $total Total members in the board
	 *
	 * @return int
	 */
	private function resolveThreshold(array $meetingData, int $total): int {
		$explicit = ($meetingData['quorumRequired'] ?? null);
		if (is_int($explicit) === true && $explicit > 0) {
			return $explicit;
		}

		$rule = (string)($meetingData['quorumRule'] ?? 'simple-majority');
		return $this->thresholdForRule(rule: $rule, total: $total);
	}//end resolveThreshold()

	/**
	 * Translate a textual quorum rule to an integer threshold.
	 *
	 * @param string $rule Rule label (simple-majority, qualified-majority-two-thirds, ...)
	 * @param int $total Total members
	 *
	 * @return int
	 */
	private function thresholdForRule(string $rule, int $total): int {
		if ($total <= 0) {
			return 0;
		}

		switch ($rule) {
			case 'qualified-majority-two-thirds':
				return (int)ceil(($total * 2) / 3);
			case 'qualified-majority-three-quarters':
				return (int)ceil(($total * 3) / 4);
			case 'unanimous':
				return $total;
			case 'simple-majority':
			default:
				return (int)ceil(($total + 1) / 2);
		}

	}//end thresholdForRule()

	/**
	 * Convert an ObjectService row (object or array) to a plain array.
	 *
	 * @param mixed $row Row to convert
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		if (is_array($row) === true) {
			return $row;
		}

		return [];
	}//end toArray()
}//end class
