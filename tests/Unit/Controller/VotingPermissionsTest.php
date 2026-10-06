<?php

/**
 * Tests for the voting permissions read and the per-meeting tally and publish
 * guards (change voting-chair-close-and-amendment-rounds).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\VotingController;
use OCA\Decidiq\Controller\VotingRoundLiveController;
use OCA\Decidiq\Service\OriPublicationService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\ProxyDelegationService;
use OCA\Decidiq\Service\VotingErrorResponder;
use OCA\Decidiq\Service\VotingOpenRequestHandler;
use OCA\Decidiq\Service\VotingRoundGuard;
use OCA\Decidiq\Service\VotingRoundResults;
use OCA\Decidiq\Service\VotingService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The page asks the server which voting controls a user may use, and the
 * server answers through the same VotingRoundGuard the endpoints enforce.
 *
 * The guard is REAL. So is ParticipantResolver::hasRole(): only the
 * participant lookup is replaced. Meetings: M has chair1 (chair), griffier1
 * (secretary) and member1 (member); N has griffier2 (secretary).
 *
 * @spec openspec/specs/voting-round-management/spec.md
 */
class VotingPermissionsTest extends TestCase {

	/**
	 * Voting service double.
	 *
	 * @var VotingService&MockObject
	 */
	private VotingService&MockObject $votingService;

	/**
	 * ORI publication double.
	 *
	 * @var OriPublicationService&MockObject
	 */
	private OriPublicationService&MockObject $oriService;

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
		$this->votingService = $this->createMock(VotingService::class);
		$this->oriService = $this->createMock(OriPublicationService::class);
		$this->results = $this->createMock(VotingRoundResults::class);

	}//end setUp()

	/**
	 * Build the controller for a signed-in user.
	 *
	 * @param string|null $uid     Signed-in uid, null for anonymous
	 * @param bool        $isAdmin Whether the uid is a Nextcloud admin
	 *
	 * @return VotingController
	 */
	private function controllerFor(?string $uid, bool $isAdmin=false): VotingController {
		[$session, $guard, $container] = $this->wiringFor(uid: $uid, isAdmin: $isAdmin);

		return new VotingController(
			request: $this->createMock(IRequest::class),
			votingService: $this->votingService,
			oriService: $this->oriService,
			userSession: $session,
			guard: $guard,
			openHandler: new VotingOpenRequestHandler(votingService: $this->votingService),
			proxyService: new ProxyDelegationService(
				container: $container,
				logger: new NullLogger(),
				objectService: $this->createMock(ObjectServiceInterface::class),
			),
			errors: new VotingErrorResponder(logger: new NullLogger()),
		);

	}//end controllerFor()

	/**
	 * Build the live (read-only) voting round controller for a signed-in user.
	 *
	 * @param string|null $uid     Signed-in uid, null for anonymous
	 * @param bool        $isAdmin Whether the uid is a Nextcloud admin
	 *
	 * @return VotingRoundLiveController
	 */
	private function liveControllerFor(?string $uid, bool $isAdmin=false): VotingRoundLiveController {
		[$session, $guard, $container] = $this->wiringFor(uid: $uid, isAdmin: $isAdmin);

		return new VotingRoundLiveController(
			request: $this->createMock(IRequest::class),
			votingService: $this->votingService,
			results: $this->results,
			userSession: $session,
			guard: $guard,
			proxyService: new ProxyDelegationService(
				container: $container,
				logger: new NullLogger(),
				objectService: $this->createMock(ObjectServiceInterface::class),
			),
			errors: new VotingErrorResponder(logger: new NullLogger()),
		);

	}//end liveControllerFor()

	/**
	 * Build the session, the REAL guard and the object container for a user.
	 *
	 * @param string|null $uid     Signed-in uid, null for anonymous
	 * @param bool        $isAdmin Whether the uid is a Nextcloud admin
	 *
	 * @return array{0: IUserSession, 1: VotingRoundGuard, 2: ContainerInterface}
	 */
	private function wiringFor(?string $uid, bool $isAdmin): array {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		$appConfig = $this->createMock(IAppConfig::class);
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
		$resolver = $this->getMockBuilder(ParticipantResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['resolveMeetingParticipants'])
			->getMock();
		$resolver->method('resolveMeetingParticipants')->willReturnCallback(
			fn (string $meetingId): array => ($participants[$meetingId] ?? [])
		);

		// Rounds: round-M belongs to motion-M in meeting M; round-orphan has no motion.
		$objects = [
			'round-M'      => ['id' => 'round-M', 'relations' => [['schema' => 'motion', 'id' => 'motion-M']]],
			'motion-M'     => ['id' => 'motion-M', 'meeting' => 'M'],
			'round-orphan' => ['id' => 'round-orphan', 'relations' => []],
		];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (mixed $id) use ($objects): ?ObjectEntity {
				if (isset($objects[(string)$id]) === false) {
					return null;
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($objects[(string)$id]);
				return $entity;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$guard = new VotingRoundGuard(
			userSession: $session,
			groupManager: $groupManager,
			appConfig: $appConfig,
			participantResolver: $resolver,
			container: $container,
		);

		return [$session, $guard, $container];

	}//end wiringFor()

	/**
	 * The chair may use every control.
	 *
	 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-002-the-server-says-which-voting-controls-a-user-may-use-in-a-meeting
	 *
	 * @return void
	 */
	public function testChairMayUseEveryControl(): void {
		$response = $this->controllerFor(uid: 'chair1')->permissions(meetingId: 'M');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			['canOpen' => true, 'canClose' => true, 'canEnterTally' => true, 'canCastChairVote' => true],
			$response->getData()
		);

	}//end testChairMayUseEveryControl()

	/**
	 * A secretary may close but may not cast the chair's vote.
	 *
	 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-002-the-server-says-which-voting-controls-a-user-may-use-in-a-meeting
	 *
	 * @return void
	 */
	public function testSecretaryMayCloseButNotCast(): void {
		$response = $this->controllerFor(uid: 'griffier1')->permissions(meetingId: 'M');

		self::assertSame(
			['canOpen' => true, 'canClose' => true, 'canEnterTally' => true, 'canCastChairVote' => false],
			$response->getData()
		);

	}//end testSecretaryMayCloseButNotCast()

	/**
	 * A member, an admin without a role in the meeting, and anyone asking about
	 * an unknown meeting get four false values and a 200: the per-meeting guard
	 * has no admin fallback, so the page must not offer admins what close() refuses.
	 *
	 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-002-the-server-says-which-voting-controls-a-user-may-use-in-a-meeting
	 *
	 * @return void
	 */
	public function testMemberAdminWithoutRoleAndUnknownMeetingGetNothing(): void {
		$none = ['canOpen' => false, 'canClose' => false, 'canEnterTally' => false, 'canCastChairVote' => false];
		foreach ([['member1', false, 'M'], ['admin', true, 'M'], ['chair1', false, 'no-such-meeting']] as [$uid, $admin, $meeting]) {
			$response = $this->controllerFor(uid: $uid, isAdmin: $admin)->permissions(meetingId: $meeting);
			self::assertSame(Http::STATUS_OK, $response->getStatus(), $uid);
			self::assertSame($none, $response->getData(), $uid);
		}

	}//end testMemberAdminWithoutRoleAndUnknownMeetingGetNothing()

	/**
	 * Without a meeting the global fallback answers: an admin keeps every control.
	 *
	 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-001-the-meetings-chair-and-secretary-see-the-voting-controls
	 *
	 * @return void
	 */
	public function testWithoutAMeetingTheGlobalFallbackAnswers(): void {
		self::assertSame(
			['canOpen' => true, 'canClose' => true, 'canEnterTally' => true, 'canCastChairVote' => true],
			$this->controllerFor(uid: 'admin', isAdmin: true)->globalPermissions()->getData()
		);
		self::assertSame(
			['canOpen' => false, 'canClose' => false, 'canEnterTally' => false, 'canCastChairVote' => false],
			$this->controllerFor(uid: 'member1')->globalPermissions()->getData()
		);

	}//end testWithoutAMeetingTheGlobalFallbackAnswers()

	/**
	 * Anonymous callers get 401 from both reads.
	 *
	 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-002-the-server-says-which-voting-controls-a-user-may-use-in-a-meeting
	 *
	 * @return void
	 */
	public function testAnonymousCallersAreRefused(): void {
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controllerFor(uid: null)->permissions(meetingId: 'M')->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controllerFor(uid: null)->globalPermissions()->getStatus());

	}//end testAnonymousCallersAreRefused()

	/**
	 * A secretary of the round's meeting who is not an admin enters a tally.
	 *
	 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-003-tally-entry-and-publication-check-the-role-in-the-rounds-own-meeting
	 *
	 * @return void
	 */
	public function testMeetingSecretaryEntersATally(): void {
		$this->votingService->expects($this->once())->method('saveShowOfHandsTally')->willReturn(['votesFor' => 14]);

		$response = $this->controllerFor(uid: 'griffier1')->tally(id: 'round-M');

		self::assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testMeetingSecretaryEntersATally()

	/**
	 * The secretary of another meeting is refused on tally and publish, and
	 * nothing is saved or published.
	 *
	 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-003-tally-entry-and-publication-check-the-role-in-the-rounds-own-meeting
	 *
	 * @return void
	 */
	public function testSecretaryOfAnotherMeetingIsRefused(): void {
		$this->votingService->expects($this->never())->method('saveShowOfHandsTally');
		$this->oriService->expects($this->never())->method('publish');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controllerFor(uid: 'griffier2', isAdmin: true)->tally(id: 'round-M')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controllerFor(uid: 'griffier2', isAdmin: true)->publish(id: 'round-M')->getStatus());

	}//end testSecretaryOfAnotherMeetingIsRefused()

	/**
	 * A round with no resolvable meeting still falls back to the global check.
	 *
	 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-003-tally-entry-and-publication-check-the-role-in-the-rounds-own-meeting
	 *
	 * @return void
	 */
	public function testRoundWithoutAMeetingFallsBackToTheGlobalCheck(): void {
		$this->votingService->method('saveShowOfHandsTally')->willReturn([]);

		self::assertSame(Http::STATUS_OK, $this->controllerFor(uid: 'admin', isAdmin: true)->tally(id: 'round-orphan')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controllerFor(uid: 'member1')->tally(id: 'round-orphan')->getStatus());

	}//end testRoundWithoutAMeetingFallsBackToTheGlobalCheck()

	/**
	 * Both reads carry #[NoAdminRequired], or no member could ever ask.
	 *
	 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-002-the-server-says-which-voting-controls-a-user-may-use-in-a-meeting
	 *
	 * @return void
	 */
	public function testReadsCarryNoAdminRequired(): void {
		$ref = new \ReflectionClass(VotingController::class);
		foreach (['permissions', 'globalPermissions'] as $name) {
			self::assertNotEmpty($ref->getMethod($name)->getAttributes(NoAdminRequired::class), $name);
		}

		$live = new \ReflectionClass(VotingRoundLiveController::class);
		self::assertNotEmpty($live->getMethod('liveTally')->getAttributes(NoAdminRequired::class), 'liveTally');

	}//end testReadsCarryNoAdminRequired()

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
			self::assertSame(Http::STATUS_OK, $response->getStatus(), $uid);
			self::assertSame($counts, $response->getData(), $uid);
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

		self::assertSame(['cast' => 5], $this->liveControllerFor(uid: 'member1')->liveTally(id: 'round-M')->getData());
		self::assertSame(['cast' => 5], $this->liveControllerFor(uid: 'griffier2', isAdmin: true)->liveTally(id: 'round-M')->getData());

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

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->liveControllerFor(uid: null)->liveTally(id: 'round-M')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->liveControllerFor(uid: 'chair1')->liveTally(id: 'round-x')->getStatus());

	}//end testLiveTallyRefusesAnonymousAndUnreadableRounds()
}//end class
