<?php

/**
 * Unit tests for the motion-amendment-v1 parliamentary ordering rules in
 * VotingService::openVotingRound() / closeVotingRound():
 *
 * - a MOTION round is rejected while any amendment is undecided,
 * - an AMENDMENT round is rejected out of the chair-configured order,
 * - amendment rounds relate to the `amendment` schema and transition the
 *   amendment lifecycle,
 * - an adopted amendment is incorporated into the parent motion text.
 *
 * Uses the anonymous-double container pattern from VotingServiceCastAsTest —
 * the OpenRegister ObjectService class is never mocked directly, so the
 * stub-vs-real signature mismatch of issue #90 cannot occur.
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
 * @spec openspec/specs/motion-amendment/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Service;

use OCA\Decidesk\Service\MotionService;
use OCA\Decidesk\Service\OriPublicationService;
use OCA\Decidesk\Service\AmendmentOrderService;
use OCA\Decidesk\Service\ObjectRelationFilter;
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
use OCA\Decidesk\Service\VotingRoundRules;
use OCA\Decidesk\Service\VotingService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Ordering-enforcement matrix for amendment-before-motion voting.
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
class VotingServiceAmendmentOrderTest extends TestCase
{

    /**
     * Captured saveObject() payloads keyed by schema slug.
     *
     * @var \ArrayObject<int, array{schema: string, object: array<string, mixed>}>
     */
    private \ArrayObject $saves;

    /**
     * Mock MotionService (amendment resolution + lifecycle assertions).
     *
     * @var MotionService&\PHPUnit\Framework\MockObject\MockObject
     */
    private MotionService $motionService;

    /**
     * Build a VotingService over an in-memory object store double.
     *
     * @param array<string, array{schema: string, object: array<string, mixed>}> $store Seed objects by id
     *
     * @return VotingService
     */
    private function buildService(array $store): VotingService
    {
        $this->saves = new \ArrayObject();
        $saves       = $this->saves;
        $storeRef    = new \ArrayObject($store);

        $objectService = new class($storeRef, $saves) {

            /**
             * Schema selected via setSchema().
             *
             * @var string
             */
            private string $schema = '';

            /**
             * Constructor.
             *
             * @param \ArrayObject $store In-memory object store
             * @param \ArrayObject $saves Captured saves
             */
            public function __construct(private \ArrayObject $store, private \ArrayObject $saves)
            {
            }

            /**
             * Entity-like wrapper around an array payload.
             *
             * @param array<string, mixed> $object The payload
             *
             * @return object
             */
            private function wrap(array $object): object
            {
                return new class($object) {

                    /**
                     * Constructor.
                     *
                     * @param array<string, mixed> $object The payload
                     */
                    public function __construct(private array $object)
                    {
                    }

                    /**
                     * Serialize like an ObjectEntity.
                     *
                     * @return array<string, mixed>
                     */
                    public function jsonSerialize(): array
                    {
                        return $this->object;
                    }

                    /**
                     * Raw payload like an ObjectEntity.
                     *
                     * @return array<string, mixed>
                     */
                    public function getObject(): array
                    {
                        return $this->object;
                    }
                };
            }

            /**
             * Select register (fluent no-op).
             *
             * @param string $register Register slug
             *
             * @return static
             */
            public function setRegister(string $register): static
            {
                return $this;
            }

            /**
             * Select schema for findAll().
             *
             * @param string $schema Schema slug
             *
             * @return static
             */
            public function setSchema(string $schema): static
            {
                $this->schema = $schema;
                return $this;
            }

            /**
             * Find an object by id.
             *
             * @param int|string      $id       Object id
             * @param string|int|null $register Register slug
             * @param string|int|null $schema   Schema slug
             *
             * @return object|null
             */
            public function find(int|string $id, string|int|null $register=null, string|int|null $schema=null): ?object
            {
                $row = ($this->store[(string) $id] ?? null);
                if ($row === null) {
                    return null;
                }

                if ($schema !== null && $row['schema'] !== $schema) {
                    return null;
                }

                return $this->wrap($row['object']);
            }

            /**
             * Find all objects of the selected schema matching plain-field filters.
             *
             * @param array<string, mixed> $config Query config
             *
             * @return array<int, object>
             */
            public function findAll(array $config=[]): array
            {
                $out = [];
                foreach ($this->store as $row) {
                    if ($row['schema'] !== $this->schema) {
                        continue;
                    }

                    $matches = true;
                    foreach (($config['filters'] ?? []) as $key => $value) {
                        if (str_starts_with((string) $key, '_relations.') === true) {
                            continue;
                        }

                        if (($row['object'][$key] ?? null) !== $value) {
                            $matches = false;
                            break;
                        }
                    }

                    if ($matches === true) {
                        $out[] = $this->wrap($row['object']);
                    }
                }

                return $out;
            }

            /**
             * Record the save and upsert the store.
             *
             * @param string               $register Register slug
             * @param string               $schema   Schema slug
             * @param array<string, mixed> $object   Payload
             * @param string|null          $uuid     Target uuid for updates
             *
             * @return array<string, mixed>
             */
            public function saveObject(string $register='', string $schema='', array $object=[], ?string $uuid=null): array
            {
                $this->saves->append(['schema' => $schema, 'object' => $object]);
                $id = (string) ($uuid ?? $object['id'] ?? $object['uuid'] ?? ('new-'.count($this->saves)));
                $this->store[$id] = ['schema' => $schema, 'object' => $object];
                return $object;
            }
        };

        $this->motionService = $this->createMock(MotionService::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($objectService): object {
                if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                    return $objectService;
                }

                // Notification/activity lookups are fail-soft in the service.
                throw new \RuntimeException('not wired in test: '.$id);
            }
        );

        $participantResolver = $this->createMock(ParticipantResolver::class);
        $participantResolver->method('resolveMeetingParticipants')->willReturn([]);

        // VotingService is a thin facade: every operation is delegated to a
        // single-purpose collaborator, so the graph is built explicitly here
        // where production relies on Nextcloud's constructor auto-wiring.
        $logger         = new NullLogger();
        $amendmentOrder = new AmendmentOrderService(container: $container, motionService: $this->motionService);
        $relationFilter = new ObjectRelationFilter();

        return new VotingService(
            opener: new VotingRoundOpener(
                container: $container,
                motionService: $this->motionService,
                participantResolver: $participantResolver,
                preflight: new VotingRoundPreflight(
                    container: $container,
                    logger: $logger,
                    motionService: $this->motionService,
                    participantResolver: $participantResolver,
                    templateService: $this->createMock(ProcessTemplateService::class)
                ),
                notifier: new VotingOpenedNotifier(
                    container: $container,
                    logger: $logger,
                    participantResolver: $participantResolver
                )
            ),
            caster: new VoteCastingService(
                container: $container,
                logger: $logger,
                participantResolver: $participantResolver,
                amendmentOrder: $amendmentOrder,
                relationFilter: $relationFilter
            ),
            closer: new VotingRoundCloser(
                container: $container,
                logger: $logger,
                oriService: $this->createMock(OriPublicationService::class),
                motionService: $this->motionService,
                amendmentOrder: $amendmentOrder,
                relationFilter: $relationFilter
            ),
            results: new VotingRoundResults(
                container: $container,
                motionService: $this->motionService,
                participantResolver: $participantResolver
            ),
            projection: new VotingRoundProjection(container: $container),
            participants: new ParticipantUuidLookup(container: $container),
        );

    }//end buildService()

    /**
     * Base store: a meeting without quorum requirement plus a motion.
     *
     * @return array<string, array{schema: string, object: array<string, mixed>}>
     */
    private static function baseStore(): array
    {
        return [
            'meeting-1' => [
                'schema' => 'meeting',
                'object' => [
                    'id'             => 'meeting-1',
                    'quorumRequired' => 0,
                ],
            ],
            'motion-1'  => [
                // ADR-005: a motion is a `decision` carrying decisionType=motion.
                'schema' => 'decision',
                'object' => [
                    'id'           => 'motion-1',
                    'decisionType' => 'motion',
                    'title'        => 'Hoofdmotie',
                    'lifecycle'    => 'debating',
                    'meeting'      => 'meeting-1',
                ],
            ],
        ];

    }//end baseStore()

    /**
     * Amendment fixture helper.
     *
     * @param string   $id          Amendment id
     * @param string   $lifecycle   Lifecycle state
     * @param int|null $votingOrder Chair-set order or null
     * @param string   $submittedAt Submission timestamp
     *
     * @return array<string, mixed>
     */
    private static function amendment(string $id, string $lifecycle, ?int $votingOrder, string $submittedAt='2026-06-01T10:00:00+00:00'): array
    {
        // ADR-005: an amendment is a `decision` carrying decisionType=amendment,
        // and its parent link is the `amends` relation that replaced `parentMotion`.
        $amendment = [
            'id'           => $id,
            'decisionType' => 'amendment',
            'title'        => 'Amendement '.$id,
            'lifecycle'    => $lifecycle,
            'amends'       => 'motion-1',
            'submittedAt'  => $submittedAt,
        ];
        if ($votingOrder !== null) {
            $amendment['votingOrder'] = $votingOrder;
        }

        return $amendment;

    }//end amendment()

    /**
     * Opening a round on a MOTION with an undecided amendment is rejected.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testMotionRoundRejectedWhileAmendmentUndecided(): void
    {
        $service = $this->buildService(self::baseStore());
        $this->motionService->method('getAmendmentsForMotion')->willReturn(
            [self::amendment('amendment-1', 'submitted', null)]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/amendment\(s\) must be decided first/');

        $service->openVotingRound(
            motionId: 'motion-1',
            meetingId: 'meeting-1',
            votingMethod: 'for-against-abstain',
            isSecret: false,
            closedAt: null,
        );

    }//end testMotionRoundRejectedWhileAmendmentUndecided()

    /**
     * Each pending lifecycle (submitted/debating/voting) blocks the motion round.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testMotionRoundRejectedForEveryPendingLifecycle(): void
    {
        foreach (['submitted', 'debating', 'voting'] as $lifecycle) {
            $service = $this->buildService(self::baseStore());
            $this->motionService->method('getAmendmentsForMotion')->willReturn(
                [self::amendment('amendment-1', $lifecycle, 1)]
            );

            try {
                $service->openVotingRound(
                    motionId: 'motion-1',
                    meetingId: 'meeting-1',
                    votingMethod: 'for-against-abstain',
                    isSecret: false,
                    closedAt: null,
                );
                self::fail("Pending lifecycle '{$lifecycle}' must block the motion round");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('must be decided first', $e->getMessage());
            }
        }

    }//end testMotionRoundRejectedForEveryPendingLifecycle()

    /**
     * A motion round opens normally once every amendment is decided.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testMotionRoundOpensWhenAllAmendmentsDecided(): void
    {
        $service = $this->buildService(self::baseStore());
        $this->motionService->method('getAmendmentsForMotion')->willReturn(
            [
                self::amendment('amendment-1', 'adopted', 1),
                self::amendment('amendment-2', 'rejected', 2),
            ]
        );
        $this->motionService->expects(self::once())
            ->method('transitionLifecycle')
            ->with(
                self::anything(),
                self::callback(fn(string $type): bool => $type === 'motion'),
                self::anything(),
                self::anything(),
            );

        $round = $service->openVotingRound(
            motionId: 'motion-1',
            meetingId: 'meeting-1',
            votingMethod: 'for-against-abstain',
            isSecret: false,
            closedAt: null,
        );

        self::assertSame('motion', $round['relations'][0]['schema']);
        self::assertSame('motion-1', $round['relations'][0]['id']);

    }//end testMotionRoundOpensWhenAllAmendmentsDecided()

    /**
     * Unknown subjectType is rejected (fail closed).
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testUnknownSubjectTypeRejected(): void
    {
        $service = $this->buildService(self::baseStore());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/subjectType/');

        $service->openVotingRound(
            motionId: 'motion-1',
            meetingId: 'meeting-1',
            votingMethod: 'for-against-abstain',
            isSecret: false,
            closedAt: null,
            roundRules: new VotingRoundRules(subjectType: 'resolution'),
        );

    }//end testUnknownSubjectTypeRejected()

    /**
     * Opening a round on the next amendment in the configured order succeeds,
     * relates the round to the amendment schema, and transitions the AMENDMENT
     * lifecycle.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testAmendmentRoundInOrderOpens(): void
    {
        $store                = self::baseStore();
        $store['amendment-1'] = ['schema' => 'decision', 'object' => self::amendment('amendment-1', 'debating', 1)];
        $store['amendment-2'] = ['schema' => 'decision', 'object' => self::amendment('amendment-2', 'submitted', 2)];

        $service = $this->buildService($store);
        $this->motionService->method('getAmendmentsForMotion')->willReturn(
            [
                self::amendment('amendment-1', 'debating', 1),
                self::amendment('amendment-2', 'submitted', 2),
            ]
        );
        $this->motionService->expects(self::once())
            ->method('transitionLifecycle')
            ->with(
                self::callback(fn(string $id): bool => $id === 'amendment-1'),
                self::callback(fn(string $type): bool => $type === 'amendment'),
                self::callback(fn(string $state): bool => $state === 'voting'),
                self::anything(),
            );

        $round = $service->openVotingRound(
            motionId: 'amendment-1',
            meetingId: 'meeting-1',
            votingMethod: 'for-against-abstain',
            isSecret: false,
            closedAt: null,
            roundRules: new VotingRoundRules(subjectType: 'amendment'),
        );

        self::assertSame('amendment', $round['relations'][0]['schema']);
        self::assertSame('amendment-1', $round['relations'][0]['id']);

    }//end testAmendmentRoundInOrderOpens()

    /**
     * Opening a round on an amendment OUT of the configured order is rejected.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testAmendmentRoundOutOfOrderRejected(): void
    {
        $store                = self::baseStore();
        $store['amendment-1'] = ['schema' => 'decision', 'object' => self::amendment('amendment-1', 'debating', 1)];
        $store['amendment-2'] = ['schema' => 'decision', 'object' => self::amendment('amendment-2', 'debating', 2)];

        $service = $this->buildService($store);
        $this->motionService->method('getAmendmentsForMotion')->willReturn(
            [
                self::amendment('amendment-1', 'debating', 1),
                self::amendment('amendment-2', 'debating', 2),
            ]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must be voted first/');

        $service->openVotingRound(
            motionId: 'amendment-2',
            meetingId: 'meeting-1',
            votingMethod: 'for-against-abstain',
            isSecret: false,
            closedAt: null,
            roundRules: new VotingRoundRules(subjectType: 'amendment'),
        );

    }//end testAmendmentRoundOutOfOrderRejected()

    /**
     * Once the earlier amendment is decided, the next one becomes votable.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testAmendmentRoundNextAfterDecisionOpens(): void
    {
        $store                = self::baseStore();
        $store['amendment-1'] = ['schema' => 'decision', 'object' => self::amendment('amendment-1', 'adopted', 1)];
        $store['amendment-2'] = ['schema' => 'decision', 'object' => self::amendment('amendment-2', 'debating', 2)];

        $service = $this->buildService($store);
        $this->motionService->method('getAmendmentsForMotion')->willReturn(
            [
                self::amendment('amendment-1', 'adopted', 1),
                self::amendment('amendment-2', 'debating', 2),
            ]
        );

        $round = $service->openVotingRound(
            motionId: 'amendment-2',
            meetingId: 'meeting-1',
            votingMethod: 'for-against-abstain',
            isSecret: false,
            closedAt: null,
            roundRules: new VotingRoundRules(subjectType: 'amendment'),
        );

        self::assertSame('amendment-2', $round['relations'][0]['id']);

    }//end testAmendmentRoundNextAfterDecisionOpens()

    /**
     * Unordered amendments queue after ordered ones (votingOrder wins over
     * submission age).
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testVotingOrderBeatsSubmissionAge(): void
    {
        // amendment-old was submitted first but carries no votingOrder;
        // amendment-new has votingOrder 1, so it must be voted first.
        $old = self::amendment('amendment-old', 'debating', null, '2026-05-01T10:00:00+00:00');
        $new = self::amendment('amendment-new', 'debating', 1, '2026-06-01T10:00:00+00:00');

        $store                  = self::baseStore();
        $store['amendment-old'] = ['schema' => 'decision', 'object' => $old];
        $store['amendment-new'] = ['schema' => 'decision', 'object' => $new];

        $service = $this->buildService($store);
        $this->motionService->method('getAmendmentsForMotion')->willReturn([$old, $new]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must be voted first/');

        $service->openVotingRound(
            motionId: 'amendment-old',
            meetingId: 'meeting-1',
            votingMethod: 'for-against-abstain',
            isSecret: false,
            closedAt: null,
            roundRules: new VotingRoundRules(subjectType: 'amendment'),
        );

    }//end testVotingOrderBeatsSubmissionAge()

    /**
     * Opening a round on an amendment that is already decided is rejected.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testDecidedAmendmentCannotBeReopened(): void
    {
        $store                = self::baseStore();
        $store['amendment-1'] = ['schema' => 'decision', 'object' => self::amendment('amendment-1', 'adopted', 1)];

        $service = $this->buildService($store);
        $this->motionService->method('getAmendmentsForMotion')->willReturn(
            [self::amendment('amendment-1', 'adopted', 1)]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already been decided/');

        $service->openVotingRound(
            motionId: 'amendment-1',
            meetingId: 'meeting-1',
            votingMethod: 'for-against-abstain',
            isSecret: false,
            closedAt: null,
            roundRules: new VotingRoundRules(subjectType: 'amendment'),
        );

    }//end testDecidedAmendmentCannotBeReopened()

    /**
     * Closing an adopted amendment round transitions the AMENDMENT lifecycle
     * and incorporates it into the parent motion via applyAmendment().
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testCloseAmendmentRoundAdoptsAndIncorporates(): void
    {
        $store                = self::baseStore();
        $store['amendment-1'] = ['schema' => 'decision', 'object' => self::amendment('amendment-1', 'voting', 1)];
        $store['round-1']     = [
            'schema' => 'voting-round',
            'object' => [
                'id'        => 'round-1',
                'openedAt'  => '2026-06-12T10:00:00+00:00',
                'closedAt'  => null,
                'isSecret'  => false,
                'relations' => [
                    ['register' => 'decidesk', 'schema' => 'amendment', 'id' => 'amendment-1'],
                ],
            ],
        ];
        $store['vote-1']      = [
            'schema' => 'vote',
            'object' => [
                'id'        => 'vote-1',
                'value'     => 'for',
                'weight'    => 1,
                'relations' => [
                    ['register' => 'decidesk', 'schema' => 'voting-round', 'id' => 'round-1'],
                ],
            ],
        ];

        $service = $this->buildService($store);

        $this->motionService->expects(self::once())
            ->method('transitionLifecycle')
            ->with(
                self::callback(fn(string $id): bool => $id === 'amendment-1'),
                self::callback(fn(string $type): bool => $type === 'amendment'),
                self::callback(fn(string $state): bool => $state === 'adopted'),
                self::anything(),
            );
        $this->motionService->expects(self::once())
            ->method('applyAmendment')
            ->with(
                self::callback(fn(string $motionId): bool => $motionId === 'motion-1'),
                self::callback(fn(string $amendmentId): bool => $amendmentId === 'amendment-1'),
            );

        $service->closeVotingRound(votingRoundId: 'round-1');

    }//end testCloseAmendmentRoundAdoptsAndIncorporates()

    /**
     * A revote round skips the ordering guard (the question was already in
     * order when first voted).
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testRevoteRoundSkipsOrderingGuard(): void
    {
        $store            = self::baseStore();
        $store['round-0'] = [
            'schema' => 'voting-round',
            'object' => [
                'id'           => 'round-0',
                'result'       => 'tied',
                'tieBreakRule' => 'revote',
                'relations'    => [
                    ['register' => 'decidesk', 'schema' => 'motion', 'id' => 'motion-1'],
                ],
            ],
        ];

        $service = $this->buildService($store);
        // An undecided amendment exists — but the revote guard must NOT consult it.
        $this->motionService->expects(self::never())->method('getAmendmentsForMotion');

        $round = $service->openVotingRound(
            motionId: 'motion-1',
            meetingId: 'meeting-1',
            votingMethod: 'for-against-abstain',
            isSecret: false,
            closedAt: null,
            revoteOfRoundId: 'round-0',
        );

        self::assertSame('motion-1', $round['relations'][0]['id']);

    }//end testRevoteRoundSkipsOrderingGuard()
}//end class
