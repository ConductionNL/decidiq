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

use OCA\Decidesk\Service\MotionService;
use OCA\Decidesk\Service\NotificationPreferenceService;
use OCA\Decidesk\Service\OriPublicationService;
use OCA\Decidesk\Service\ParticipantResolver;
use OCA\Decidesk\Service\VotingService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for the castVote delegation-without-proxy gate.
 *
 * @spec openspec/specs/user-settings/spec.md
 */
class VotingServiceDelegationGateTest extends TestCase
{

    /**
     * Build a VotingService whose container serves an open round without proxy notes.
     *
     * @param bool $delegationActive Whether the preference service reports an active delegation.
     *
     * @return VotingService
     */
    private function buildService(bool $delegationActive): VotingService
    {
        // Open voting round, no motion relation (skips the meeting-membership
        // branch), no Proxy notes (so the formal-grant check fails).
        $round = [
            'openedAt'  => (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM),
            'closedAt'  => null,
            'isSecret'  => false,
            'relations' => [],
            'notes'     => [],
        ];

        $roundEntity = new class($round) {

            /**
             * Constructor.
             *
             * @param array<string, mixed> $round The round payload.
             */
            public function __construct(private array $round)
            {
            }

            /**
             * Serialize like an ObjectEntity.
             *
             * @return array<string, mixed>
             */
            public function jsonSerialize(): array
            {
                return $this->round;
            }
        };

        $objectService = new class($roundEntity) {

            /**
             * Constructor.
             *
             * @param object $roundEntity The round entity double.
             */
            public function __construct(private object $roundEntity)
            {
            }

            /**
             * Find returning the round entity for any id.
             *
             * @param int|string      $id       Object id.
             * @param string|int|null $register Register slug.
             * @param string|int|null $schema   Schema slug.
             *
             * @return object|null
             */
            public function find(int|string $id, string|int|null $register=null, string|int|null $schema=null): ?object
            {
                return $this->roundEntity;
            }
        };

        $prefService = $this->createMock(NotificationPreferenceService::class);
        $prefService->method('hasActiveDelegationTo')->willReturnCallback(
            function (string $delegatorId, string $delegateId) use ($delegationActive): bool {
                return $delegationActive === true && $delegatorId === 'member-a' && $delegateId === 'caster-uid';
            }
        );

        $services = [
            'OCA\OpenRegister\Service\ObjectService' => $objectService,
            NotificationPreferenceService::class     => $prefService,
        ];

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) use ($services) {
                return ($services[$id] ?? throw new \RuntimeException('unexpected container id: '.$id));
            }
        );

        return new VotingService(
            container: $container,
            logger: new NullLogger(),
            oriPublicationService: $this->createMock(OriPublicationService::class),
            motionService: $this->createMock(MotionService::class),
            participantResolver: $this->createMock(ParticipantResolver::class),
        );

    }//end buildService()

    /**
     * An absence delegate without a formal proxy gets the spec-mandated rejection
     * including the pointer to the proxy-granting process.
     *
     * @spec openspec/specs/user-settings/spec.md
     *
     * @return void
     */
    public function testDelegateWithoutProxyGetsSpecMandatedRejection(): void
    {
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
    public function testNoDelegationKeepsGenericProxyRejection(): void
    {
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
    public function testFormalProxyGrantIsUntouchedByTheGate(): void
    {
        // Round WITH a matching Proxy note — the grant check passes and the
        // delegation gate is never consulted. The cast then proceeds into
        // dedup/save, which this double does not implement; reaching that
        // point (instead of the gate's RuntimeException) proves the gate
        // does not interfere. We assert the failure is NOT a gate rejection.
        $round = [
            'openedAt'  => (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM),
            'closedAt'  => null,
            'isSecret'  => false,
            'relations' => [],
            'notes'     => [
                [
                    'title' => 'Proxy',
                    'body'  => json_encode(
                        [
                            'fromParticipantId' => 'member-a',
                            'toParticipantId'   => 'participant-b',
                        ]
                    ),
                ],
            ],
        ];

        $roundEntity = new class($round) {

            /**
             * Constructor.
             *
             * @param array<string, mixed> $round The round payload.
             */
            public function __construct(private array $round)
            {
            }

            /**
             * Serialize like an ObjectEntity.
             *
             * @return array<string, mixed>
             */
            public function jsonSerialize(): array
            {
                return $this->round;
            }
        };

        $objectService = new class($roundEntity) {

            /**
             * Constructor.
             *
             * @param object $roundEntity The round entity double.
             */
            public function __construct(private object $roundEntity)
            {
            }

            /**
             * Find returning the round entity.
             *
             * @param int|string      $id       Object id.
             * @param string|int|null $register Register slug.
             * @param string|int|null $schema   Schema slug.
             *
             * @return object|null
             */
            public function find(int|string $id, string|int|null $register=null, string|int|null $schema=null): ?object
            {
                return $this->roundEntity;
            }

            /**
             * Fluent register setter.
             *
             * @param string $register Register slug.
             *
             * @return static
             */
            public function setRegister(string $register): static
            {
                return $this;
            }

            /**
             * Fluent schema setter.
             *
             * @param string $schema Schema slug.
             *
             * @return static
             */
            public function setSchema(string $schema): static
            {
                return $this;
            }

            /**
             * Empty result set for the dedup queries.
             *
             * @param array<string, mixed> $config Query config.
             *
             * @return array<int, object>
             */
            public function findAll(array $config=[]): array
            {
                return [];
            }

            /**
             * Echoing save.
             *
             * @param string|int|null      $register Register slug.
             * @param string|int|null      $schema   Schema slug.
             * @param array<string, mixed> $object   The object payload.
             *
             * @return array<string, mixed>
             */
            public function saveObject(string|int|null $register=null, string|int|null $schema=null, array $object=[]): array
            {
                return $object;
            }
        };

        $prefService = $this->createMock(NotificationPreferenceService::class);
        $prefService->expects($this->never())->method('hasActiveDelegationTo');

        $services = [
            'OCA\OpenRegister\Service\ObjectService' => $objectService,
            NotificationPreferenceService::class     => $prefService,
        ];

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) use ($services) {
                return ($services[$id] ?? throw new \RuntimeException('unexpected container id: '.$id));
            }
        );

        $service = new VotingService(
            container: $container,
            logger: new NullLogger(),
            oriPublicationService: $this->createMock(OriPublicationService::class),
            motionService: $this->createMock(MotionService::class),
            participantResolver: $this->createMock(ParticipantResolver::class),
        );

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
