<?php

/**
 * A closing time preset when a voting round is opened (#1379).
 *
 * The open dialog's optional "Closing time" is stored as the round's closedAt
 * (VoteCastGuard refuses ballots after it). It must also become the round's
 * votingDeadline, which the 24-hour reminder job reads, and closing the round
 * early must stamp the actual close moment instead of keeping the future one.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/voting-system/spec.md
 * @spec openspec/specs/nextcloud-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AmendmentOrderService;
use OCA\Decidiq\Service\MotionService;
use OCA\Decidiq\Service\ObjectRelationFilter;
use OCA\Decidiq\Service\OriPublicationService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\ProcessTemplateService;
use OCA\Decidiq\Service\VotingRoundCloser;
use OCA\Decidiq\Service\VotingRoundPreflight;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Preset closing time → voting deadline, and early close.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingRoundPresetCloseTest extends TestCase {

	/**
	 * Build the payload for a round opened with the given closing time.
	 *
	 * @param string|null $closedAt The preset closing time
	 *
	 * @return array<string, mixed> The voting-round payload
	 */
	private function payload(?string $closedAt): array {
		$preflight = new VotingRoundPreflight(
			logger: new NullLogger(),
			motionService: $this->createMock(MotionService::class),
			participantResolver: $this->createMock(ParticipantResolver::class),
			templateService: $this->createMock(ProcessTemplateService::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		return $preflight->buildRoundPayload(
			motionId: 'mot-1',
			subjectType: 'motion',
			votingMethod: 'for-against-abstain',
			isSecret: false,
			closedAt: $closedAt,
			quorumWith: true,
			rules: ['voteThreshold' => 'simple-majority', 'abstentionHandling' => 'exclude', 'tieBreakRule' => 'rejected'],
			revoteOfRoundId: null,
			participantIds: []
		);
	}//end payload()

	/**
	 * A preset closing time is mirrored to votingDeadline; none sets no deadline.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testPresetClosingTimeBecomesTheVotingDeadline(): void {
		$withClose = $this->payload(closedAt: '2026-10-07T18:00:00+00:00');
		self::assertSame(expected: '2026-10-07T18:00:00+00:00', actual: $withClose['votingDeadline']);
		self::assertSame(expected: '2026-10-07T18:00:00+00:00', actual: $withClose['closedAt']);

		self::assertArrayNotHasKey(key: 'votingDeadline', array: $this->payload(closedAt: null));
	}//end testPresetClosingTimeBecomesTheVotingDeadline()

	/**
	 * Close the given round and return the closedAt it was saved with.
	 *
	 * @param string|null $closedAt The round's closedAt before closing
	 *
	 * @return string|null The saved closedAt, or null when nothing was saved
	 */
	private function closedAtAfterClose(?string $closedAt): ?string {
		$round = ['id' => 'round-1', 'closedAt' => $closedAt];
		$saved = [];

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id) use ($round): ?ObjectEntity {
				if ((string)$id !== 'round-1') {
					return null;
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($round);
				return $entity;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return $this->createMock(ObjectEntity::class);
			}
		);

		$motionService = $this->createMock(MotionService::class);
		$closer = new VotingRoundCloser(
			logger: new NullLogger(),
			oriService: $this->createMock(OriPublicationService::class),
			motionService: $motionService,
			amendmentOrder: new AmendmentOrderService(
				motionService: $motionService,
				objectService: $this->createMock(ObjectServiceInterface::class),
			),
			relationFilter: new ObjectRelationFilter(),
			fileService: $this->createMock(FileService::class),
			objectService: $objectService,
		);

		$closer->close(votingRoundId: 'round-1', anonymise: false, tally: ['result' => 'adopted']);

		if ($saved === [] || is_array($saved[0]) === false) {
			return null;
		}

		return ($saved[0]['closedAt'] ?? null);
	}//end closedAtAfterClose()

	/**
	 * Closing before the preset closing time stamps the actual moment.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testEarlyCloseReplacesAFuturePresetClosingTime(): void {
		$before = time();
		$stamped = $this->closedAtAfterClose(closedAt: gmdate('Y-m-d\TH:i:s\Z', ($before + 86400)));

		self::assertNotNull(actual: $stamped);
		self::assertLessThanOrEqual(expected: time(), actual: strtotime($stamped));
		self::assertGreaterThanOrEqual(expected: ($before - 1), actual: strtotime($stamped));
	}//end testEarlyCloseReplacesAFuturePresetClosingTime()

	/**
	 * A round with no closedAt is stamped; one already closed is left alone.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testOpenRoundIsStampedAndClosedRoundIsKept(): void {
		self::assertNotNull(actual: $this->closedAtAfterClose(closedAt: null));
		self::assertNull(actual: $this->closedAtAfterClose(closedAt: '2026-01-01T10:00:00+00:00'));
	}//end testOpenRoundIsStampedAndClosedRoundIsKept()
}//end class
