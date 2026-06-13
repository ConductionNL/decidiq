<?php

/**
 * Unit tests for the motion-amendment-v1 amendment resolution + chair-set
 * voting order in MotionService:
 *
 * - getAmendmentsForMotion() resolves BOTH the flat parentMotion property and
 *   the structured relations shape, deduped,
 * - setAmendmentVotingOrder() validates ids belong to the motion, rejects
 *   duplicates / unknown ids / empty input, and persists votingOrder 1..N.
 *
 * Uses the anonymous-double container pattern from
 * VotingServiceAmendmentOrderTest so the OpenRegister ObjectService class is
 * never mocked directly (avoids the stub-vs-real signature mismatch of #90).
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
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Amendment resolution + chair-set voting-order persistence.
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
class MotionServiceAmendmentOrderTest extends TestCase
{

    /**
     * Captured saveObject() payloads.
     *
     * @var \ArrayObject<int, array{object: array<string, mixed>, uuid: ?string}>
     */
    private \ArrayObject $saves;


    /**
     * Build a MotionService over an in-memory object store double whose
     * findAll() honours plain-field filters (parentMotion) and the
     * relations-presence filter (_relations.motion).
     *
     * @param array<string, array{schema: string, object: array<string, mixed>}> $store Seed objects by id
     *
     * @return MotionService
     */
    private function buildService(array $store): MotionService
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

                return $this->wrap($row['object']);
            }

            /**
             * Find all objects of the selected schema matching the filters.
             *
             * Plain-field filters match the object property exactly. The
             * `_relations.<schema>` filter is presence-only (matches any object
             * that carries a relation of that schema), mirroring OpenRegister.
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
                            $relSchema = substr((string) $key, strlen('_relations.'));
                            $present   = false;
                            foreach (($row['object']['relations'] ?? []) as $relation) {
                                if (is_array($relation) === true && ($relation['schema'] ?? '') === $relSchema) {
                                    $present = true;
                                    break;
                                }
                            }

                            if ($present === false) {
                                $matches = false;
                                break;
                            }

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
             * @param array<string, mixed> $object   Payload
             * @param string               $register Register slug
             * @param string               $schema   Schema slug
             * @param string|null          $uuid     Target uuid for updates
             *
             * @return array<string, mixed>
             */
            public function saveObject(array $object=[], string $register='', string $schema='', ?string $uuid=null): array
            {
                $this->saves->append(['object' => $object, 'uuid' => $uuid]);
                $id = (string) ($uuid ?? $object['id'] ?? $object['uuid'] ?? ('new-'.count($this->saves)));
                $this->store[$id] = ['schema' => $schema, 'object' => $object];
                return $object;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($objectService): object {
                if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                    return $objectService;
                }

                throw new \RuntimeException('not wired in test: '.$id);
            }
        );

        return new MotionService(
            container: $container,
            logger: new NullLogger(),
            userManager: $this->createMock(IUserManager::class),
        );

    }//end buildService()


    /**
     * Resolution honours both the flat parentMotion property and the
     * structured relations shape, deduped.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testGetAmendmentsResolvesBothShapes(): void
    {
        $store = [
            // Flat-property shape.
            'amendment-flat'     => [
                'schema' => 'amendment',
                'object' => ['id' => 'amendment-flat', 'parentMotion' => 'motion-1'],
            ],
            // Structured-relations shape.
            'amendment-relation' => [
                'schema' => 'amendment',
                'object' => [
                    'id'        => 'amendment-relation',
                    'relations' => [['schema' => 'motion', 'id' => 'motion-1']],
                ],
            ],
            // Belongs to a different motion — excluded.
            'amendment-other'    => [
                'schema' => 'amendment',
                'object' => ['id' => 'amendment-other', 'parentMotion' => 'motion-2'],
            ],
        ];

        $service    = $this->buildService($store);
        $amendments = $service->getAmendmentsForMotion(motionId: 'motion-1');

        $ids = array_map(static fn(array $a): string => (string) $a['id'], $amendments);
        sort($ids);

        self::assertSame(['amendment-flat', 'amendment-relation'], $ids);

    }//end testGetAmendmentsResolvesBothShapes()


    /**
     * setAmendmentVotingOrder stamps votingOrder 1..N in the supplied order.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testSetVotingOrderPersists1ToN(): void
    {
        $store = [
            'amendment-a' => ['schema' => 'amendment', 'object' => ['id' => 'amendment-a', 'parentMotion' => 'motion-1']],
            'amendment-b' => ['schema' => 'amendment', 'object' => ['id' => 'amendment-b', 'parentMotion' => 'motion-1']],
            'amendment-c' => ['schema' => 'amendment', 'object' => ['id' => 'amendment-c', 'parentMotion' => 'motion-1']],
        ];

        $service = $this->buildService($store);
        $updated = $service->setAmendmentVotingOrder(
            motionId: 'motion-1',
            orderedAmendmentIds: ['amendment-c', 'amendment-a', 'amendment-b'],
            actorId: 'chair-1',
        );

        $orderById = [];
        foreach ($updated as $amendment) {
            $orderById[(string) $amendment['id']] = $amendment['votingOrder'];
        }

        self::assertSame(1, $orderById['amendment-c']);
        self::assertSame(2, $orderById['amendment-a']);
        self::assertSame(3, $orderById['amendment-b']);
        self::assertCount(3, $this->saves);

    }//end testSetVotingOrderPersists1ToN()


    /**
     * An id that does not belong to the motion is rejected.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testUnknownAmendmentRejected(): void
    {
        $store = [
            'amendment-a' => ['schema' => 'amendment', 'object' => ['id' => 'amendment-a', 'parentMotion' => 'motion-1']],
        ];

        $service = $this->buildService($store);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not belong to motion/');

        $service->setAmendmentVotingOrder(
            motionId: 'motion-1',
            orderedAmendmentIds: ['amendment-a', 'amendment-x'],
            actorId: 'chair-1',
        );

    }//end testUnknownAmendmentRejected()


    /**
     * Duplicate ids are rejected.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testDuplicateIdsRejected(): void
    {
        $store = [
            'amendment-a' => ['schema' => 'amendment', 'object' => ['id' => 'amendment-a', 'parentMotion' => 'motion-1']],
        ];

        $service = $this->buildService($store);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/duplicates/');

        $service->setAmendmentVotingOrder(
            motionId: 'motion-1',
            orderedAmendmentIds: ['amendment-a', 'amendment-a'],
            actorId: 'chair-1',
        );

    }//end testDuplicateIdsRejected()


    /**
     * Ordering a motion with no amendments raises a RuntimeException.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testNoAmendmentsRejected(): void
    {
        $service = $this->buildService([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no amendments to order/');

        $service->setAmendmentVotingOrder(
            motionId: 'motion-1',
            orderedAmendmentIds: ['amendment-a'],
            actorId: 'chair-1',
        );

    }//end testNoAmendmentsRejected()


    /**
     * An empty actorId is rejected (the #317 guard).
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testEmptyActorRejected(): void
    {
        $store = [
            'amendment-a' => ['schema' => 'amendment', 'object' => ['id' => 'amendment-a', 'parentMotion' => 'motion-1']],
        ];

        $service = $this->buildService($store);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/actorId/');

        $service->setAmendmentVotingOrder(
            motionId: 'motion-1',
            orderedAmendmentIds: ['amendment-a'],
            actorId: '',
        );

    }//end testEmptyActorRejected()
}//end class
