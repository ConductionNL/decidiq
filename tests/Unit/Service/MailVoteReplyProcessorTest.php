<?php

/**
 * Unit tests for MailVoteReplyProcessor.
 *
 * Covers what a `_mail` reply entry turns into: a cast vote, a re-prompt, an
 * abandonment, or nothing at all when the entry is processed, unsigned, for an
 * unknown participant, or on a secret ballot.
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
 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-3.2
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MailVoteReplyProcessor;
use OCA\Decidiq\Service\MailVoteSigner;
use OCA\Decidiq\Service\VoteCast;
use OCA\Decidiq\Service\VotingService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Behaviour of the email vote reply processor.
 *
 * @covers \OCA\Decidiq\Service\MailVoteReplyProcessor
 * @uses   \OCA\Decidiq\Service\VoteCast
 */
class MailVoteReplyProcessorTest extends TestCase {

	/**
	 * Voting service double.
	 *
	 * @var VotingService&MockObject
	 */
	private VotingService&MockObject $votingService;

	/**
	 * Signer double.
	 *
	 * @var MailVoteSigner&MockObject
	 */
	private MailVoteSigner&MockObject $signer;

	/**
	 * Container double.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Notification manager double.
	 *
	 * @var IManager&MockObject
	 */
	private IManager&MockObject $manager;

	/**
	 * Subjects of the notifications sent, with their user and parameters.
	 *
	 * @var array<int, array{user: string, subject: string, parameters: array<string, mixed>}>
	 */
	private array $sent = [];

	/**
	 * The notification currently being built.
	 *
	 * @var array{user: string, subject: string, parameters: array<string, mixed>}
	 */
	private array $current = ['user' => '', 'subject' => '', 'parameters' => []];

	/**
	 * The processor under test.
	 *
	 * @var MailVoteReplyProcessor
	 */
	private MailVoteReplyProcessor $processor;

	/**
	 * Set up shared doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->votingService = $this->createMock(VotingService::class);
		$this->signer        = $this->createMock(MailVoteSigner::class);
		$this->container     = $this->createMock(ContainerInterface::class);
		$this->logger        = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->manager       = $this->createMock(IManager::class);
		$this->sent          = [];

		$this->manager->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$this->manager->method('notify')->willReturnCallback(
			function (): void {
				$this->sent[] = $this->current;
			}
		);
		$this->container->method('get')->willReturnCallback(
			fn (string $id): object => ($id === IManager::class) ? $this->manager : throw new RuntimeException('no ' . $id)
		);

		$this->processor = new MailVoteReplyProcessor(
			votingService: $this->votingService,
			signer: $this->signer,
			container: $this->container,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * A notification double that records what it was set up with when sent.
	 *
	 * @return INotification
	 */
	private function notification(): INotification {
		$this->current = ['user' => '', 'subject' => '', 'parameters' => []];
		$notification  = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setUser')->willReturnCallback(
			function (string $user) use ($notification): INotification {
				$this->current['user'] = $user;
				return $notification;
			}
		);
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $parameters) use ($notification): INotification {
				$this->current['subject']    = $subject;
				$this->current['parameters'] = $parameters;
				return $notification;
			}
		);

		return $notification;
	}//end notification()

	/**
	 * Let find() return the given participant (or null for unknown).
	 *
	 * @param array<string, mixed>|null $participant The participant record
	 *
	 * @return void
	 */
	private function participant(?array $participant): void {
		$entity = null;
		if ($participant !== null) {
			$entity = $this->createMock(ObjectEntity::class);
			$entity->method('jsonSerialize')->willReturn($participant);
		}

		$this->objectService->method('find')->with('p-1', [], false, 'decidiq', 'participant')->willReturn($entity);
	}//end participant()

	/**
	 * Run one entry through the processor and return the persisted `_mail` list (null when nothing was saved).
	 *
	 * @param array<string, mixed> $entry The `_mail` entry
	 * @param bool $isSecret Whether the round is a secret ballot
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private function processEntry(array $entry, bool $isSecret = false): ?array {
		$saved = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null) use (&$saved) {
				self::assertSame(['decidiq', 'voting-round'], [$register, $schema]);
				$saved = $object['_mail'];

				return $this->createMock(ObjectEntity::class);
			}
		);

		$this->processor->processRound(
			objectService: $this->objectService,
			round: ['id' => 'round-1', 'isSecret' => $isSecret, '_mail' => [$entry]],
			roundId: 'round-1'
		);

		return $saved;
	}//end processEntry()

	/**
	 * A round without mail entries is not touched.
	 *
	 * @return void
	 */
	public function testRoundWithoutMailIsIgnored(): void {
		$this->objectService->expects(self::never())->method('saveObject');
		$this->signer->expects(self::never())->method('verify');

		$this->processor->processRound(objectService: $this->objectService, round: ['id' => 'round-1'], roundId: 'round-1');
	}//end testRoundWithoutMailIsIgnored()

	/**
	 * Processed entries and entries without participant are skipped without saving.
	 *
	 * @return void
	 */
	public function testProcessedOrAnonymousEntriesAreSkipped(): void {
		$this->signer->expects(self::never())->method('verify');
		$this->objectService->expects(self::never())->method('saveObject');

		$this->processor->processRound(
			objectService: $this->objectService,
			round: ['_mail' => [['participantId' => 'p-1', 'processed' => true], ['replyBody' => 'voor']]],
			roundId: 'round-1'
		);
	}//end testProcessedOrAnonymousEntriesAreSkipped()

	/**
	 * An unsigned entry is rejected with an audit warning.
	 *
	 * @return void
	 */
	public function testUnsignedEntryIsRejected(): void {
		$entry = ['participantId' => 'p-1', 'replyBody' => 'voor'];
		$this->signer->expects(self::once())->method('verify')->with($entry, 'round-1')->willReturn(false);
		$this->objectService->expects(self::never())->method('find');
		$this->votingService->expects(self::never())->method('castVote');
		$this->logger->expects(self::once())->method('warning')->with(
			'Decidiq: MailReplyHandler — _mail entry rejected: missing or invalid HMAC signature',
			['participantId' => 'p-1', 'votingRoundId' => 'round-1']
		);

		self::assertNull($this->processEntry($entry));
	}//end testUnsignedEntryIsRejected()

	/**
	 * An entry for an unknown participant is skipped with an audit warning.
	 *
	 * @return void
	 */
	public function testUnknownParticipantIsSkipped(): void {
		$this->signer->method('verify')->willReturn(true);
		$this->participant(null);
		$this->votingService->expects(self::never())->method('castVote');
		$this->logger->expects(self::once())->method('warning')->with(
			'Decidiq: MailReplyHandler — unknown participantId in _mail metadata, skipping',
			['participantId' => 'p-1', 'votingRoundId' => 'round-1']
		);

		self::assertNull($this->processEntry(['participantId' => 'p-1', 'replyBody' => 'voor']));
	}//end testUnknownParticipantIsSkipped()

	/**
	 * An email vote on a secret round is rejected and marked abandoned.
	 *
	 * @return void
	 */
	public function testSecretBallotEntryIsRejected(): void {
		$this->signer->method('verify')->willReturn(true);
		$this->participant(['nextcloudUserId' => 'uid-7']);
		$this->votingService->expects(self::never())->method('castVote');
		$this->logger->expects(self::once())->method('warning')->with(
			'Decidiq: MailReplyHandler — email vote rejected: round is a secret ballot',
			['participantId' => 'p-1', 'votingRoundId' => 'round-1']
		);

		$saved = $this->processEntry(['participantId' => 'p-1', 'replyBody' => 'voor'], isSecret: true);

		self::assertSame(
			['participantId' => 'p-1', 'replyBody' => 'voor', 'processed' => true, 'abandoned' => true, 'rejectReason' => 'secret-ballot'],
			$saved[0]
		);
		self::assertSame([], $this->sent);
	}//end testSecretBallotEntryIsRejected()

	/**
	 * A recognised reply casts the vote and confirms it to the voter.
	 *
	 * @return void
	 */
	public function testRecognisedReplyCastsTheVoteAndConfirms(): void {
		$this->signer->method('verify')->willReturn(true);
		$this->participant(['nextcloudUserId' => 'uid-7']);
		$this->votingService->expects(self::once())->method('castVote')->with(
			self::callback(
				static fn (VoteCast $cast): bool => $cast->votingRoundId === 'round-1'
					&& $cast->participantId === 'p-1'
					&& $cast->value() === 'against'
			)
		)->willReturn([]);
		$this->logger->expects(self::once())->method('info')->with('Decidiq: email vote processed', ['participant' => 'p-1']);

		$saved = $this->processEntry(['participantId' => 'p-1', 'replyBody' => "\n  TEGEN \nthanks"]);

		self::assertTrue($saved[0]['processed']);
		self::assertSame(
			[['user' => 'uid-7', 'subject' => 'email_vote_confirmed', 'parameters' => ['value' => 'against', 'votingRoundId' => 'round-1']]],
			$this->sent
		);
	}//end testRecognisedReplyCastsTheVoteAndConfirms()

	/**
	 * A failing cast leaves the entry unprocessed and the round unsaved.
	 *
	 * @return void
	 */
	public function testFailedCastLeavesTheEntryUntouched(): void {
		$this->signer->method('verify')->willReturn(true);
		$this->participant(['nextcloudUserId' => 'uid-7']);
		$this->votingService->method('castVote')->willThrowException(new RuntimeException('round closed'));
		$this->logger->expects(self::once())->method('warning')
			->with('Decidiq: email vote cast failed', ['error' => 'round closed']);

		self::assertNull($this->processEntry(['participantId' => 'p-1', 'replyBody' => 'for']));
		self::assertSame([], $this->sent);
	}//end testFailedCastLeavesTheEntryUntouched()

	/**
	 * The third unreadable reply abandons email voting and tells the voter.
	 *
	 * @return void
	 */
	public function testThirdUnreadableReplyAbandons(): void {
		$this->signer->method('verify')->willReturn(true);
		$this->participant(['nextcloudUserId' => 'uid-7']);

		$saved = $this->processEntry(['participantId' => 'p-1', 'replyBody' => 'maybe', 'retries' => 2]);

		self::assertTrue($saved[0]['processed']);
		self::assertTrue($saved[0]['abandoned']);
		self::assertSame(2, $saved[0]['retries']);
		self::assertSame('email_vote_abandoned', $this->sent[0]['subject']);
	}//end testThirdUnreadableReplyAbandons()

	/**
	 * An unreadable reply re-prompts the voter resolved by unique email.
	 *
	 * @return void
	 */
	public function testUnreadableReplyRepromptsVoterResolvedByEmail(): void {
		$this->signer->method('verify')->willReturn(true);
		$this->participant(['email' => 'jan@example.org']);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->expects(self::once())->method('getByEmail')->with('jan@example.org')->willReturn([$user]);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnMap([[IUserManager::class, $userManager], [IManager::class, $this->manager]]);
		$this->processor = new MailVoteReplyProcessor($this->votingService, $this->signer, $this->container, $this->logger);

		$saved = $this->processEntry(['participantId' => 'p-1', 'replyBody' => '']);

		self::assertSame(1, $saved[0]['retries']);
		self::assertArrayNotHasKey('processed', $saved[0]);
		self::assertSame(
			[['user' => 'jan', 'subject' => 'email_vote_reprompt', 'parameters' => ['votingRoundId' => 'round-1', 'attempt' => 1]]],
			$this->sent
		);
	}//end testUnreadableReplyRepromptsVoterResolvedByEmail()

	/**
	 * An ambiguous email resolves to nobody, so no notification goes out.
	 *
	 * @return void
	 */
	public function testAmbiguousEmailSendsNoNotification(): void {
		$this->signer->method('verify')->willReturn(true);
		$this->participant(['email' => 'shared@example.org']);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('getByEmail')->willReturn([$this->createMock(IUser::class), $this->createMock(IUser::class)]);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnMap([[IUserManager::class, $userManager]]);
		$this->processor = new MailVoteReplyProcessor($this->votingService, $this->signer, $this->container, $this->logger);
		$this->manager->expects(self::never())->method('createNotification');

		$saved = $this->processEntry(['participantId' => 'p-1', 'replyBody' => 'hmm']);

		self::assertSame(1, $saved[0]['retries']);
	}//end testAmbiguousEmailSendsNoNotification()

	/**
	 * A participant without UID or email gets no notification; a lookup failure is logged.
	 *
	 * @return void
	 */
	public function testUnresolvableVoterIsNotNotified(): void {
		$this->signer->method('verify')->willReturn(true);
		$this->participant(['email' => 'x@example.org']);
		$this->manager->expects(self::never())->method('createNotification');
		$this->logger->expects(self::once())->method('warning')->with(
			'Decidiq: could not resolve Nextcloud UID for participant',
			['participantId' => 'p-1']
		);

		$saved = $this->processEntry(['participantId' => 'p-1', 'replyBody' => 'hmm']);

		self::assertSame(1, $saved[0]['retries']);
	}//end testUnresolvableVoterIsNotNotified()

	/**
	 * A participant with neither UID nor email is processed without any lookup.
	 *
	 * @return void
	 */
	public function testParticipantWithoutContactIsNotLookedUp(): void {
		$this->signer->method('verify')->willReturn(true);
		$this->participant(['name' => 'Jan']);
		$this->container->expects(self::never())->method('get');
		$this->votingService->expects(self::once())->method('castVote')->willReturn([]);

		$saved = $this->processEntry(['participantId' => 'p-1', 'replyBody' => 'abstention']);

		self::assertTrue($saved[0]['processed']);
	}//end testParticipantWithoutContactIsNotLookedUp()

	/**
	 * A failing notification delivery is logged with the caller's message and the vote still counts.
	 *
	 * @return void
	 */
	public function testNotificationFailureIsLogged(): void {
		$this->signer->method('verify')->willReturn(true);
		$this->participant(['nextcloudUserId' => 'uid-7']);
		$this->votingService->method('castVote')->willReturn([]);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willThrowException(new RuntimeException('no manager'));
		$this->processor = new MailVoteReplyProcessor($this->votingService, $this->signer, $this->container, $this->logger);

		$this->logger->expects(self::once())->method('warning')
			->with('Decidiq: vote confirmation notification failed', ['error' => 'no manager']);

		$saved = $this->processEntry(['participantId' => 'p-1', 'replyBody' => 'voor']);

		self::assertTrue($saved[0]['processed']);
	}//end testNotificationFailureIsLogged()

	/**
	 * The first non-empty line decides the keyword.
	 *
	 * @param string $body The reply body
	 * @param string|null $expected The canonical value, or null
	 *
	 * @return void
	 *
	 * @dataProvider keywordProvider
	 */
	public function testParseVoteKeyword(string $body, ?string $expected): void {
		self::assertSame($expected, $this->processor->parseVoteKeyword(body: $body));
	}//end testParseVoteKeyword()

	/**
	 * Reply bodies and the keyword they carry.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function keywordProvider(): array {
		return [
			'dutch for' => ['Voor', 'for'],
			'english against after blank lines' => ["\n\n against\n", 'against'],
			'onthouding' => ['ONTHOUDING', 'abstain'],
			'abstain' => ['abstain', 'abstain'],
			'unrecognised first line wins' => ["I vote\nvoor", null],
			'empty body' => ["  \n ", null],
		];
	}//end keywordProvider()
}//end class
