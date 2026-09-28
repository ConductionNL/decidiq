<?php

/**
 * Decidiq Citizen Advice Service
 *
 * Opens and closes the advisory vote residents give on a motion, and counts
 * it (participation-citizen-advisory-vote-on-motions, issue #1418).
 *
 * A clerk could set `citizenVotingAllowed` on a motion, but nothing let a
 * resident vote. Residents vote through decidiq's portal contribution
 * (`castMotionAdvice`), and PortalCreateOpenParentGuardListener refuses a vote
 * on a motion whose advisory vote is not open. This service is the other half:
 * it is the only writer of `citizenVotingStatus`, and on closing it stores the
 * three counts on the motion. Citizen votes are never read by the statutory
 * voting round tally.
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
 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\NotFoundException;
use OCA\Decidiq\Exception\ParticipationValidationException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * The griffie's side of a residents' advisory vote on a motion.
 *
 * The caller authorises: CitizenAdviceController admits the secretariat, an
 * admin, or the chair or secretary of the motion's meeting before calling in.
 * Reads and writes here therefore run without the caller's register RBAC,
 * because the decision schema grants update to administrators only and a
 * griffier who is allowed to open the vote is not one.
 *
 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md
 */
class CitizenAdviceService {

	/**
	 * The register every object here lives in.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The advisory vote has not been opened.
	 *
	 * @var string
	 */
	public const STATUS_NOT_OPEN = 'not-open';

	/**
	 * Residents can give their advice.
	 *
	 * @var string
	 */
	public const STATUS_OPEN = 'open';

	/**
	 * The advisory vote has closed and the counts are stored.
	 *
	 * @var string
	 */
	public const STATUS_CLOSED = 'closed';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Open the advisory vote on a motion.
	 *
	 * Refused unless the motion is published, allows citizen voting, uses the
	 * simple method, and has not been opened before.
	 *
	 * @param string $motionId The motion's uuid.
	 *
	 * @return array<string, mixed> The motion after the change.
	 *
	 * @throws NotFoundException When no motion has this id.
	 * @throws ParticipationValidationException When the motion cannot be opened, with the reason.
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
	 */
	public function open(string $motionId): array {
		$motion = $this->motion(motionId: $motionId);

		if (($motion['isPublished'] ?? null) !== 'public') {
			throw new ParticipationValidationException('The motion must be published before residents can give their advice');
		}

		if (($motion['citizenVotingAllowed'] ?? false) !== true) {
			throw new ParticipationValidationException('Citizen voting is not allowed on this motion');
		}

		if ((string)($motion['citizenVotingMethod'] ?? 'simple') !== 'simple') {
			throw new ParticipationValidationException('An advisory vote by residents supports the simple method only');
		}

		$status = $this->status(motion: $motion);
		if ($status === self::STATUS_OPEN) {
			throw new ParticipationValidationException('The advisory vote on this motion is already open');
		}

		if ($status === self::STATUS_CLOSED) {
			throw new ParticipationValidationException('The advisory vote on this motion has closed and cannot open again');
		}

		return $this->patch(motionId: $motionId, data: ['citizenVotingStatus' => self::STATUS_OPEN]);
	}//end open()

	/**
	 * Close the advisory vote on a motion and store its counts.
	 *
	 * @param string $motionId The motion's uuid.
	 *
	 * @return array<string, mixed> The motion after the change.
	 *
	 * @throws NotFoundException When no motion has this id.
	 * @throws ParticipationValidationException When the advisory vote is not open.
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-003-the-advisory-result-shows-apart-from-the-councils-vote
	 */
	public function close(string $motionId): array {
		$motion = $this->motion(motionId: $motionId);
		if ($this->status(motion: $motion) !== self::STATUS_OPEN) {
			throw new ParticipationValidationException('The advisory vote on this motion is not open');
		}

		$tally = $this->tally(motionId: $motionId);

		return $this->patch(
			motionId: $motionId,
			data: [
				'citizenVotingStatus' => self::STATUS_CLOSED,
				'citizenAdviceFor' => $tally['voor'],
				'citizenAdviceAgainst' => $tally['tegen'],
				'citizenAdviceAbstain' => $tally['onthoud'],
			]
		);
	}//end close()

	/**
	 * Count the residents' advice on a motion.
	 *
	 * Reads every citizen vote with this motionId, without RBAC: a count that
	 * could only see the caller's own rows would close the vote at zero.
	 *
	 * @param string $motionId The motion's uuid.
	 *
	 * @return array{voor: int, tegen: int, onthoud: int} The counts.
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-003-the-advisory-result-shows-apart-from-the-councils-vote
	 */
	public function tally(string $motionId): array {
		$tally = ['voor' => 0, 'tegen' => 0, 'onthoud' => 0];

		$votes = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => 'citizen-vote',
					'motionId' => $motionId,
				],
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach ($votes as $entity) {
			$vote = $this->normalise(row: $entity);
			if ((string)($vote['motionId'] ?? '') !== $motionId) {
				continue;
			}

			$value = (string)($vote['voteValue'] ?? '');
			if (array_key_exists($value, $tally) === true) {
				$tally[$value]++;
			}
		}

		return $tally;
	}//end tally()

	/**
	 * Load a motion, refusing anything that is not one.
	 *
	 * @param string $motionId The motion's uuid.
	 *
	 * @return array<string, mixed> The motion.
	 *
	 * @throws NotFoundException When no motion has this id.
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
	 */
	private function motion(string $motionId): array {
		$entity = $this->objectService->find(
			id: $motionId,
			register: self::REGISTER,
			schema: 'decision',
			_rbac: false,
			_multitenancy: false
		);
		$motion = [];
		if ($entity !== null) {
			$motion = $this->normalise(row: $entity);
		}

		if (($motion['decisionType'] ?? null) !== 'motion') {
			throw new NotFoundException('Motion ' . $motionId . ' not found');
		}

		return $motion;
	}//end motion()

	/**
	 * The motion's advisory vote status, `not-open` when it was never set.
	 *
	 * @param array<string, mixed> $motion The motion.
	 *
	 * @return string The status.
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
	 */
	private function status(array $motion): string {
		$status = (string)($motion['citizenVotingStatus'] ?? '');
		if ($status === '') {
			return self::STATUS_NOT_OPEN;
		}

		return $status;
	}//end status()

	/**
	 * Merge fields into the motion.
	 *
	 * @param string $motionId The motion's uuid.
	 * @param array<string, mixed> $data The fields to set.
	 *
	 * @return array<string, mixed> The motion after the change.
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
	 */
	private function patch(string $motionId, array $data): array {
		$stored = $this->objectService->patchObject(
			objectId: $motionId,
			data: $data,
			register: self::REGISTER,
			schema: 'decision',
			_rbac: false,
			_multitenancy: false
		);

		return $this->normalise(row: $stored);
	}//end patch()

	/**
	 * Collapse OpenRegister's entity-or-array shape into an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed> The array form.
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md
	 */
	private function normalise(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			return (array)$row->getObject();
		}

		return (array)$row;
	}//end normalise()
}//end class
