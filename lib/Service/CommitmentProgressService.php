<?php

/**
 * Decidiq Commitment Progress Service
 *
 * Adds a dated progress entry to a commitment. The chair or secretary of the
 * meeting the commitment was made in (or an administrator) may add one.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Progress entries on a commitment.
 *
 * @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry
 */
class CommitmentProgressService {

	/**
	 * The longest note an entry may carry.
	 */
	private const MAX_NOTE_LENGTH = 2000;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param MeetingRoleGate        $roleGate      Chair, secretary or admin of a meeting
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly MeetingRoleGate $roleGate,
	) {
	}//end __construct()

	/**
	 * Whether the caller may add progress to this commitment: chair or
	 * secretary of its meeting, or an administrator.
	 *
	 * @param string $commitmentId The commitment UUID
	 * @param string $uid          The caller's Nextcloud UID
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry
	 */
	public function canAddProgress(string $commitmentId, string $uid): bool {
		if ($uid === '') {
			return false;
		}

		$commitment = $this->load(commitmentId: $commitmentId);
		if ($commitment === []) {
			return false;
		}

		$meetingId = (string)($commitment['meeting'] ?? '');

		return $this->roleGate->isChairOrSecretary(meetingId: $meetingId, userId: $uid);
	}//end canAddProgress()

	/**
	 * Append a progress entry dated today and save the commitment.
	 *
	 * The caller's right is checked by canAddProgress() first; OpenRegister's
	 * own update rule (administrators only) is therefore bypassed here.
	 *
	 * @param string $commitmentId The commitment UUID
	 * @param string $note         What happened
	 *
	 * @return list<array{date: string, note: string}> The progress entries after the addition
	 *
	 * @throws InvalidArgumentException When the note is empty or too long
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry
	 */
	public function addEntry(string $commitmentId, string $note): array {
		$note = trim($note);
		if ($note === '') {
			throw new InvalidArgumentException('Write what happened before adding the entry.');
		}

		if (mb_strlen($note) > self::MAX_NOTE_LENGTH) {
			throw new InvalidArgumentException('The note is too long.');
		}

		$progress = [];
		foreach ((array)($this->load(commitmentId: $commitmentId)['progress'] ?? []) as $entry) {
			if (is_array($entry) === true && isset($entry['date'], $entry['note']) === true) {
				$progress[] = ['date' => (string)$entry['date'], 'note' => (string)$entry['note']];
			}
		}

		$progress[] = ['date' => (new DateTimeImmutable())->format('Y-m-d'), 'note' => $note];

		$this->objectService->patchObject(
			objectId: $commitmentId,
			data: ['progress' => $progress],
			register: 'decidiq',
			schema: 'governance-commitment',
			_rbac: false,
		);

		return $progress;
	}//end addEntry()

	/**
	 * Read a commitment, or an empty array when it does not exist.
	 *
	 * @param string $commitmentId The commitment UUID
	 *
	 * @return array<string, mixed>
	 */
	private function load(string $commitmentId): array {
		$entity = $this->objectService->find(id: $commitmentId, register: 'decidiq', schema: 'governance-commitment');
		if ($entity === null) {
			return [];
		}

		return $entity->jsonSerialize();
	}//end load()
}//end class
