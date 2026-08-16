<?php

/**
 * Unit tests for the VotingService delegation gate (user-settings-v1).
 *
 * "Delegate cannot vote without explicit proxy": when no formal proxy grant
 * exists on the voting round AND the claimed delegator has an active absence
 * delegation to the caster, castVote must reject with the spec-mandated
 * message (plus a pointer to the proxy-granting process); without a
 * delegation the pre-existing generic rejection is preserved.
 *
 * Kept separate from VotingServiceTest (skipped pending issue #90) because
 * these cases avoid mocking the OpenRegister ObjectService class entirely —
 * the container serves a plain anonymous double, so no stub-vs-real
 * signature mismatch can occur.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/user-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\AmendmentOrderService;
use OCA\Decidesk\Service\MotionService;
use OCA\Decidesk\Service\NotificationPreferenceService;
use OCA\Decidesk\Service\ObjectRelationFilter;
use OCA\Decidesk\Service\OriPublicationService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\Decidesk\Service\ParticipantUuidLookup;
use OCA\Decidesk\Service\ProcessTemplateService;
use OCA\Decidesk\Service\VoteCastingService;
use OCA\Decidesk\Service\VotingOpenedNotifier;
use OCA\Decidesk\Service\VotingRoundCloser;
use OCA\Decidesk\Service\VotingRoundOpener;
use OCA\Decidesk\Service\VotingRoundPreflight;
use OCA\Decidesk\Service\VotingRoundProjection;
use OCA\Decidesk\Service\VotingRoundResults;
use OCA\Decidesk\Service\VotingService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for the castVote delegation-without-proxy gate.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
class VotingServiceDelegationGateTest extends TestCase {

	/**
	 * Build a VotingService whose container serves an open round without proxy notes.
	 *
	 * @param bool $delegationActive Whether the preference service reports an active delegation.
	 *
	 * @return VotingService
	 */
	private function buildService(bool $delegationActive): VotingService {
		// Open voting round, no motion relation (skips the meeting-membership
		// branch), no Proxy notes (so the formal-grant check fails).
		$round = [
			'openedAt' => (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM),
			'closedAt' => null,
			'isSecret' => false,
			'relations' => [],
			'notes' => [],
		];

		// ADR-084: VoteCastingService and its collaborators take
		// ObjectServiceInterface directly, so the round has to be served by a
		// double of that contract. The previous anonymous class had a bare
		// `find(int|string $id, string|int|null $register, string|int|null $schema)`
		// — the contract's find() (ObjectServiceInterface.php:191) has $_extend
		// and $files in positions 2 and 3, so the two are not interchangeable.
		$roundEntity = $this->createMock(ObjectEntity::class);
		$roundEntity->method('jsonSerialize')->willReturn($round);
		$roundEntity->method('getObject')->willReturn($round);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('find')->willReturn($roundEntity);

		$prefService = $this->createMock(NotificationPreferenceService::class);
		$prefService->method('hasActiveDelegationTo')->willReturnCallback(
			function (string $delegatorId, string $delegateId) use ($delegationActive): bool {
				return $delegationActive === true && $delegatorId === 'member-a' && $delegateId === 'caster-uid';
			}
		);

		$services = [
			'OCA\OpenRegister\Service\ObjectService' => $objectService,
			NotificationPreferenceService::class => $prefService,
		];

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($services) {
				return ($services[$id] ?? throw new \RuntimeException('unexpected container id: ' . $id));
			}
		);

		return $this->assembleVotingService(container: $container, objectService: $objectService);
	}//end buildService()

	/**
	 * Assemble the VotingService facade from its collaborators.
	 *
	 * VotingService is a thin facade: every operation is delegated to a
	 * single-purpose collaborator, so the graph has to be built explicitly here
	 * where production relies on Nextcloud's constructor auto-wiring.
	 *
	 * @param ContainerInterface     $container     The (mocked) DI container.
	 * @param ObjectServiceInterface $objectService The object-service double every
	 *                                              collaborator is now handed directly.
	 *
	 * @return VotingService
	 */
	private function assembleVotingService(
		ContainerInterface $container,
		ObjectServiceInterface $objectService,
	): VotingService {
		$logger = new NullLogger();
		$motionService = $this->createMock(MotionService::class);
		$participantResolver = $this->createMock(ParticipantResolver::class);
		$templateService = $this->createMock(ProcessTemplateService::class);
		$amendmentOrder = new AmendmentOrderService(
			motionService: $motionService,
			objectService: $objectService,
		);
		$relationFilter = new ObjectRelationFilter();

		return new VotingService(
			opener: new VotingRoundOpener(
				motionService: $motionService,
				participantResolver: $participantResolver,
				preflight: new VotingRoundPreflight(
					logger: $logger,
					motionService: $motionService,
					participantResolver: $participantResolver,
					templateService: $templateService,
					objectService: $objectService,
				),
				notifier: new VotingOpenedNotifier(
					container: $container,
					logger: $logger,
					participantResolver: $participantResolver,
				),
				objectService: $objectService,
			),
			caster: new VoteCastingService(
				logger: $logger,
				participantResolver: $participantResolver,
				amendmentOrder: $amendmentOrder,
				relationFilter: $relationFilter,
				objectService: $objectService,
				container: $container,
			),
			closer: new VotingRoundCloser(
				logger: $logger,
				oriService: $this->createMock(OriPublicationService::class),
				motionService: $motionService,
				amendmentOrder: $amendmentOrder,
				relationFilter: $relationFilter,
				fileService: $this->createMock(FileService::class),
				objectService: $objectService,
			),
			results: new VotingRoundResults(
				motionService: $motionService,
				participantResolver: $participantResolver,
				objectService: $objectService,
			),
			projection: new VotingRoundProjection(
				objectService: $objectService,
			),
			participants: new ParticipantUuidLookup(
				objectService: $objectService,
			),
		);

	}//end assembleVotingService()

	/**
	 * An absence delegate without a formal proxy gets the spec-mandated rejection
	 * including the pointer to the proxy-granting process.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testDelegateWithoutProxyGetsSpecMandatedRejection(): void {
		$service = $this->buildService(delegationActive: true);

		try {
			$service->castVote(
				votingRoundId: 'round-1',
				participantId: 'participant-b',
				value: 'for',
				isProxy: true,
				delegatorId: 'member-a',
				callerUid: 'caster-uid'
			);
			self::fail('castVote must reject a delegation-only proxy attempt');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString(
				'Delegation does not include voting rights. A formal proxy (volmacht) is required for voting.',
				$e->getMessage()
			);
			self::assertStringContainsString(
				'/api/voting-rounds/{id}/proxy',
				$e->getMessage(),
				'The rejection must point to the proxy-granting process'
			);
		}

	}//end testDelegateWithoutProxyGetsSpecMandatedRejection()

	/**
	 * Without an absence delegation the pre-existing generic rejection is preserved.
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testNoDelegationKeepsGenericProxyRejection(): void {
		$service = $this->buildService(delegationActive: false);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Geen geldige volmacht gevonden');

		$service->castVote(
			votingRoundId: 'round-1',
			participantId: 'participant-b',
			value: 'for',
			isProxy: true,
			delegatorId: 'member-a',
			callerUid: 'caster-uid'
		);

	}//end testNoDelegationKeepsGenericProxyRejection()

	/**
	 * A valid formal proxy grant still casts (the gate never blocks real volmachten).
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 *
	 * @return void
	 */
	public function testFormalProxyGrantIsUntouchedByTheGate(): void {
		// Round WITH a matching Proxy note — the grant check passes and the
		// delegation gate is never consulted. The cast then proceeds into
		// dedup/save, which this double does not implement; reaching that
		// point (instead of the gate's RuntimeException) proves the gate
		// does not interfere. We assert the failure is NOT a gate rejection.
		$round = [
			'openedAt' => (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM),
			'closedAt' => null,
			'isSecret' => false,
			'relations' => [],
			'notes' => [
				[
					'title' => 'Proxy',
					'body' => json_encode(
						[
							'fromParticipantId' => 'member-a',
							'toParticipantId' => 'participant-b',
						]
					),
				],
			],
		];

		// ADR-084 (see buildService()): the store is a double of the contract.
		// The anonymous class this replaces declared
		// saveObject(string|int|null $register, string|int|null $schema, array $object)
		// and returned its own argument — three parameters in an order the
		// contract does not have, so no real call could have reached it.
		$roundEntity = $this->createMock(ObjectEntity::class);
		$roundEntity->method('jsonSerialize')->willReturn($round);
		$roundEntity->method('getObject')->willReturn($round);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('find')->willReturn($roundEntity);
		$objectService->method('findAll')->willReturn([]);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$stored = $this->createMock(ObjectEntity::class);
				$stored->method('jsonSerialize')->willReturn($object);
				$stored->method('getObject')->willReturn($object);
				return $stored;
			}
		);

		$prefService = $this->createMock(NotificationPreferenceService::class);
		$prefService->expects($this->never())->method('hasActiveDelegationTo');

		$services = [
			'OCA\OpenRegister\Service\ObjectService' => $objectService,
			NotificationPreferenceService::class => $prefService,
		];

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($services) {
				return ($services[$id] ?? throw new \RuntimeException('unexpected container id: ' . $id));
			}
		);

		$service = $this->assembleVotingService(container: $container, objectService: $objectService);

		$vote = $service->castVote(
			votingRoundId: 'round-1',
			participantId: 'participant-b',
			value: 'for',
			isProxy: true,
			delegatorId: 'member-a',
			callerUid: 'caster-uid'
		);

		self::assertSame('for', $vote['value']);
		self::assertTrue($vote['isProxy']);

	}//end testFormalProxyGrantIsUntouchedByTheGate()
}//end class
