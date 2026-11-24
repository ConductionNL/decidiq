<?php

/**
 * Who sees what of the running tally of an open voting round (vot-14, #1375).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/voting-system/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\VotingRoundLiveController;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\VotingRoundGuard;
use OCA\Decidiq\Service\VotingRoundResults;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The live tally answers through the same REAL VotingRoundGuard the voting
 * endpoints enforce: everyone who can read the round sees how many votes are
 * cast, only the meeting's chair and secretary see the for / against /
 * abstain split. Meetings: M has chair1 (chair), griffier1 (secretary) and
 * member1 (member); N has griffier2 (secretary).
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class VotingRoundLiveTallyPermissionsTest extends TestCase {

	/**
	 * Voting round results double (live counts).
	 *
	 * @var VotingRoundResults&MockObject
	 */
	private VotingRoundResults&MockObject $results;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->results = $this->createMock(originalClassName: VotingRoundResults::class);

	}//end setUp()

	/**
	 * Build the live voting round controller for a signed-in user.
	 *
	 * @param string|null $uid     Signed-in uid, null for anonymous
	 * @param bool        $isAdmin Whether the uid is a Nextcloud admin
	 *
	 * @return VotingRoundLiveController
	 */
	private function liveControllerFor(?string $uid, bool $isAdmin=false): VotingRoundLiveController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$participants = [
			'M' => [
				['nextcloudUserId' => 'chair1', 'role' => 'chair'],
				['nextcloudUserId' => 'griffier1', 'role' => 'secretary'],
				['nextcloudUserId' => 'member1', 'role' => 'member'],
			],
			'N' => [
				['nextcloudUserId' => 'griffier2', 'role' => 'secretary'],
			],
		];
		$resolver = $this->getMockBuilder(className: ParticipantResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['resolveMeetingParticipants'])
			->getMock();
		$resolver->method('resolveMeetingParticipants')->willReturnCallback(
			fn (string $meetingId): array => ($participants[$meetingId] ?? [])
		);

		// Round-M belongs to motion-M in meeting M.
		$objects = [
			'round-M'  => ['id' => 'round-M', 'relations' => [['schema' => 'motion', 'id' => 'motion-M']]],
			'motion-M' => ['id' => 'motion-M', 'decisionType' => 'motion', 'meeting' => 'M'],
		];
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (mixed $id) use ($objects): ?ObjectEntity {
				if (isset($objects[(string)$id]) === false) {
					return null;
				}

				$entity = $this->createMock(originalClassName: ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($objects[(string)$id]);
				return $entity;
			}
		);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new VotingRoundLiveController(
			request: $this->createMock(originalClassName: IRequest::class),
			results: $this->results,
			userSession: $session,
			guard: new VotingRoundGuard(
				userSession: $session,
				groupManager: $groupManager,
				appConfig: $appConfig,
				participantResolver: $resolver,
				container: $container,
			),
		);

	}//end liveControllerFor()

	/**
	 * The chair and secretary see the running for / against / abstain split
	 * of an open round (vot-14, #1375).
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testLiveTallyShowsTheSplitToChairAndSecretary(): void {
		$counts = ['votesFor' => 3, 'votesAgainst' => 1, 'votesAbstain' => 1, 'cast' => 5];
		$this->results->method('liveCounts')->willReturn($counts);

		foreach (['chair1', 'griffier1'] as $uid) {
			$response = $this->liveControllerFor(uid: $uid)->liveTally(id: 'round-M');
			self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus(), message: $uid);
			self::assertSame(expected: $counts, actual: $response->getData(), message: $uid);
		}

	}//end testLiveTallyShowsTheSplitToChairAndSecretary()

	/**
	 * A member sees only how many votes are cast, never the split, and an
	 * admin who is secretary of another meeting is no exception.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testLiveTallyShowsAMemberOnlyTheCastCount(): void {
		$this->results->method('liveCounts')->willReturn(
			['votesFor' => 3, 'votesAgainst' => 1, 'votesAbstain' => 1, 'cast' => 5]
		);

		self::assertSame(
			expected: ['cast' => 5],
			actual: $this->liveControllerFor(uid: 'member1')->liveTally(id: 'round-M')->getData()
		);
		self::assertSame(
			expected: ['cast' => 5],
			actual: $this->liveControllerFor(uid: 'griffier2', isAdmin: true)->liveTally(id: 'round-M')->getData()
		);

	}//end testLiveTallyShowsAMemberOnlyTheCastCount()

	/**
	 * Anonymous callers are refused, and a round the user cannot read is a 404.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return void
	 */
	public function testLiveTallyRefusesAnonymousAndUnreadableRounds(): void {
		$this->results->method('liveCounts')->willReturn(null);

		self::assertSame(
			expected: Http::STATUS_UNAUTHORIZED,
			actual: $this->liveControllerFor(uid: null)->liveTally(id: 'round-M')->getStatus()
		);
		self::assertSame(
			expected: Http::STATUS_NOT_FOUND,
			actual: $this->liveControllerFor(uid: 'chair1')->liveTally(id: 'round-x')->getStatus()
		);

	}//end testLiveTallyRefusesAnonymousAndUnreadableRounds()
}//end class
