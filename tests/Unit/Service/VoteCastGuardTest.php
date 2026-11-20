<?php

/**
 * Tests for VoteCastGuard.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/voting-system/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\AmendmentOrderService;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\Decidiq\Service\ObjectRelationFilter;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\Decidiq\Service\VoteCastGuard;
use OCA\Decidiq\Service\VoterTokenSecret;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The pre-cast checks: an open round, meeting membership, a proxy grant, and
 * one proxy per delegator per round.
 *
 * @covers \OCA\Decidiq\Service\VoteCastGuard
 * @uses   \OCA\Decidiq\Service\ObjectRelationFilter
 */
class VoteCastGuardTest extends TestCase {

	/**
	 * The object store.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The voter token secret.
	 *
	 * @var VoterTokenSecret&MockObject
	 */
	private VoterTokenSecret&MockObject $tokens;

	/**
	 * The participant resolver.
	 *
	 * @var ParticipantResolver&MockObject
	 */
	private ParticipantResolver&MockObject $participants;

	/**
	 * The amendment order service, which resolves a round's meeting.
	 *
	 * @var AmendmentOrderService&MockObject
	 */
	private AmendmentOrderService&MockObject $amendmentOrder;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects        = $this->createMock(ObjectServiceInterface::class);
		$this->container      = $this->createMock(ContainerInterface::class);
		$this->logger         = $this->createMock(LoggerInterface::class);
		$this->tokens         = $this->createMock(VoterTokenSecret::class);
		$this->participants   = $this->createMock(ParticipantResolver::class);
		$this->amendmentOrder = $this->createMock(AmendmentOrderService::class);
	}//end setUp()

	/**
	 * The guard under test.
	 *
	 * @return VoteCastGuard
	 */
	private function guard(): VoteCastGuard {
		return new VoteCastGuard(
			container: $this->container,
			logger: $this->logger,
			relationFilter: new ObjectRelationFilter(),
			tokens: $this->tokens,
			participantResolver: $this->participants,
			amendmentOrder: $this->amendmentOrder,
			objectService: $this->objects,
		);
	}//end guard()

	/**
	 * An entity serialising to the given array.
	 *
	 * @param array<string, mixed> $data The serialised object
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * Assert the callable throws a RuntimeException with exactly this message.
	 *
	 * @param string   $message The expected message
	 * @param callable $call    The call
	 *
	 * @return void
	 */
	private function assertRefused(string $message, callable $call): void {
		try {
			$call();
		} catch (RuntimeException $e) {
			self::assertSame($message, $e->getMessage());
			return;
		}

		self::fail('Expected a refusal: '.$message);
	}//end assertRefused()

	/**
	 * Only a round that exists, has opened and has not closed is open for votes.
	 *
	 * @return void
	 */
	public function testLoadOpenRoundRefusesUnknownClosedAndUnopenedRounds(): void {
		$rounds = [
			'open'     => ['id' => 'open', 'openedAt' => '2026-03-09T09:00:00+00:00', 'closedAt' => '2999-01-01T00:00:00+00:00'],
			'closed'   => ['id' => 'closed', 'openedAt' => '2026-03-09T09:00:00+00:00', 'closedAt' => '2026-03-09T10:00:00+00:00'],
			'unopened' => ['id' => 'unopened', 'openedAt' => null],
		];
		$this->objects->method('find')->willReturnCallback(
			function (string $id, $options) use ($rounds) {
				self::assertSame('decidiq', $options->register);
				self::assertSame('voting-round', $options->schema);
				return isset($rounds[$id]) === true ? $this->entity($rounds[$id]) : null;
			}
		);
		$guard = $this->guard();

		self::assertSame($rounds['open'], $guard->loadOpenRound(votingRoundId: 'open'));
		$this->assertRefused('VotingRound nope not found', fn () => $guard->loadOpenRound(votingRoundId: 'nope'));
		$this->assertRefused('Stemronde is gesloten', fn () => $guard->loadOpenRound(votingRoundId: 'closed'));
		$this->assertRefused('Stemronde is nog niet geopend', fn () => $guard->loadOpenRound(votingRoundId: 'unopened'));
	}//end testLoadOpenRoundRefusesUnknownClosedAndUnopenedRounds()

	/**
	 * A round with a meeting admits only that meeting's participants; a round
	 * without one admits anyone.
	 *
	 * @return void
	 */
	public function testMeetingMembership(): void {
		$this->amendmentOrder->method('resolveMeetingIdForRound')->willReturnCallback(
			fn (array $round) => ($round['meeting'] ?? null)
		);
		$this->participants->expects(self::exactly(2))
			->method('resolveMeetingParticipants')
			->with('m-1')
			->willReturn([['id' => 'p-1'], ['id' => 'p-2']]);
		$guard = $this->guard();

		$guard->assertMeetingMembership(round: [], participantId: 'outsider');
		$guard->assertMeetingMembership(round: ['meeting' => 'm-1'], participantId: 'p-2');
		$this->assertRefused(
			'Deelnemer is geen lid van de vergadering',
			fn () => $guard->assertMeetingMembership(round: ['meeting' => 'm-1'], participantId: 'outsider')
		);
	}//end testMeetingMembership()

	/**
	 * A Proxy note from the delegator to the participant is a grant; other
	 * notes and other pairs are not.
	 *
	 * @return void
	 */
	public function testAProxyNoteIsAGrant(): void {
		$this->container->expects(self::never())->method('get');
		$round = [
			'notes' => [
				['title' => 'Remark', 'body' => json_encode(['fromParticipantId' => 'd-1', 'toParticipantId' => 'p-1'])],
				['title' => 'Proxy', 'body' => json_encode(['fromParticipantId' => 'd-1', 'toParticipantId' => 'p-1'])],
			],
		];

		$this->guard()->assertGrant(round: $round, participantId: 'p-1', delegatorId: 'd-1', callerUid: null);
		$this->addToAssertionCount(1);
	}//end testAProxyNoteIsAGrant()

	/**
	 * Without a grant, an absence delegation earns the "volmacht required"
	 * explanation, checked for the participant and then for the caller.
	 *
	 * @return void
	 */
	public function testAnAbsenceDelegationIsNotAGrant(): void {
		$prefs = $this->createMock(NotificationPreferenceService::class);
		$prefs->method('hasActiveDelegationTo')->willReturnCallback(
			fn (string $delegatorId, string $delegateId) => ($delegatorId === 'd-1' && $delegateId === 'caller')
		);
		$this->container->method('get')->with(NotificationPreferenceService::class)->willReturn($prefs);
		$round = ['notes' => [['title' => 'Proxy', 'body' => json_encode(['fromParticipantId' => 'd-1', 'toParticipantId' => 'someone-else'])]]];
		$guard = $this->guard();

		try {
			$guard->assertGrant(round: $round, participantId: 'p-1', delegatorId: 'd-1', callerUid: 'caller');
			self::fail('An absence delegation must not grant voting rights.');
		} catch (RuntimeException $e) {
			self::assertStringStartsWith('Delegation does not include voting rights.', $e->getMessage());
		}

		$this->assertRefused(
			'Geen geldige volmacht gevonden: de deelnemer heeft geen volmacht ontvangen van deze volmachtgever',
			fn () => $guard->assertGrant(round: $round, participantId: 'p-1', delegatorId: 'd-1', callerUid: '')
		);
	}//end testAnAbsenceDelegationIsNotAGrant()

	/**
	 * A failing or unexpected preference service falls back to the plain refusal.
	 *
	 * @return void
	 */
	public function testAFailingDelegationLookupStillRefuses(): void {
		$this->container->method('get')->willReturnOnConsecutiveCalls(
			new \stdClass(),
			$this->throwException(new RuntimeException('container down'))
		);
		$this->logger->expects(self::once())->method('debug')->with(
			'Decidiq: delegation consult failed',
			['error' => 'container down']
		);
		$guard = $this->guard();
		$plain = 'Geen geldige volmacht gevonden: de deelnemer heeft geen volmacht ontvangen van deze volmachtgever';

		$this->assertRefused($plain, fn () => $guard->assertGrant(round: [], participantId: 'p-1', delegatorId: 'd-1', callerUid: 'caller'));
		$this->assertRefused($plain, fn () => $guard->assertGrant(round: [], participantId: 'p-1', delegatorId: 'd-1', callerUid: 'caller'));
	}//end testAFailingDelegationLookupStillRefuses()

	/**
	 * In a secret round the delegator's token finds an earlier proxy vote.
	 *
	 * @return void
	 */
	public function testASecretRoundFindsAnEarlierProxyByToken(): void {
		$this->tokens->method('delegatorToken')->with('d-1', 'r-1')->willReturn('tok');
		$this->objects->expects(self::exactly(2))->method('setRegister')->with('decidiq')->willReturnSelf();
		$this->objects->expects(self::exactly(2))->method('setSchema')->with('vote')->willReturnSelf();
		$this->objects->expects(self::exactly(2))
			->method('findAll')
			->with(['filters' => ['_relations.relations' => 'r-1', 'delegatorToken' => 'tok']])
			->willReturnOnConsecutiveCalls(
				[$this->entity(['relations' => [['schema' => 'voting-round', 'id' => 'r-1']]])],
				[$this->entity(['relations' => [['schema' => 'voting-round', 'id' => 'other']]])]
			);
		$guard = $this->guard();

		$this->assertRefused(
			'Er is al een volmacht geregistreerd voor deze deelnemer in deze stemronde',
			fn () => $guard->assertNotAlreadyRegistered(votingRoundId: 'r-1', delegatorId: 'd-1', isSecret: true)
		);
		$guard->assertNotAlreadyRegistered(votingRoundId: 'r-1', delegatorId: 'd-1', isSecret: true);
	}//end testASecretRoundFindsAnEarlierProxyByToken()

	/**
	 * In an open round a proxy vote naming the delegator as delegator blocks a
	 * second one; other relations do not.
	 *
	 * @return void
	 */
	public function testAnOpenRoundFindsAnEarlierProxyByDelegatorRelation(): void {
		$this->tokens->expects(self::never())->method('delegatorToken');
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$round   = ['schema' => 'voting-round', 'id' => 'r-1'];
		$harmless = $this->entity(['relations' => [$round, 'flat', ['schema' => 'participant', 'id' => 'd-1', 'type' => 'voter'], ['schema' => 'participant', 'id' => 'd-2', 'type' => 'delegator']]]);
		$blocking = $this->entity(['relations' => [$round, ['schema' => 'participant', 'id' => 'd-1', 'type' => 'delegator']]]);
		$this->objects->method('findAll')
			->with(['filters' => ['_relations.relations' => 'r-1', 'isProxy' => true]])
			->willReturnOnConsecutiveCalls([$harmless], [$harmless, $blocking]);
		$guard = $this->guard();

		$guard->assertNotAlreadyRegistered(votingRoundId: 'r-1', delegatorId: 'd-1', isSecret: false);
		$this->assertRefused(
			'Er is al een volmacht geregistreerd voor deze deelnemer in deze stemronde',
			fn () => $guard->assertNotAlreadyRegistered(votingRoundId: 'r-1', delegatorId: 'd-1', isSecret: false)
		);
	}//end testAnOpenRoundFindsAnEarlierProxyByDelegatorRelation()
}//end class
