<?php

/**
 * Unit tests for the motion-amendment-v1 co-signer minimum threshold in
 * MotionService::transitionLifecycle():
 *
 * - the proposed -> deliberating edge is blocked when the motion carries fewer
 *   than motion_min_cosigners co-signers (the rejection message names the
 *   minimum, the current count and the shortfall),
 * - the edge is allowed once the threshold is met,
 * - the threshold is disabled (default 0) when unset,
 * - the threshold never applies to amendments or to other motion edges.
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
use OCP\IAppConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Co-signer threshold enforcement matrix for the proposed -> deliberating edge.
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
class MotionServiceCosignerThresholdTest extends TestCase
{

    /**
     * Captured saveObject() payloads.
     *
     * @var \ArrayObject<int, array<string, mixed>>
     */
    private \ArrayObject $saves;


    /**
     * Build a MotionService over an in-memory object store double.
     *
     * @param array<string, array{schema: string, object: array<string, mixed>}> $store        Seed objects by id
     * @param int                                                                 $minCoSigners The motion_min_cosigners app-config value
     *
     * @return MotionService
     */
    private function buildService(array $store, int $minCoSigners): MotionService
    {
        $this->saves = new \ArrayObject();
        $saves       = $this->saves;
        $storeRef    = new \ArrayObject($store);

        $objectService = new class($storeRef, $saves) {

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
                     * Raw payload like an ObjectEntity.
                     *
                     * @return array<string, mixed>
                     */
                    public function getObject(): array
                    {
                        return $this->object;
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
             * Select schema (fluent no-op).
             *
             * @param string $schema Schema slug
             *
             * @return static
             */
            public function setSchema(string $schema): static
            {
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
                $this->saves->append($object);
                $id = (string) ($uuid ?? $object['id'] ?? $object['uuid'] ?? ('new-'.count($this->saves)));
                $this->store[$id] = ['schema' => $schema, 'object' => $object];
                return $object;
            }
        };

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default='') use ($minCoSigners): string {
                if ($key === 'motion_min_cosigners') {
                    return (string) $minCoSigners;
                }

                return $default;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($objectService, $appConfig): object {
                if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                    return $objectService;
                }

                if ($id === IAppConfig::class) {
                    return $appConfig;
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
     * Seed a motion with the given lifecycle and co-signer count.
     *
     * @param string $lifecycle    Current lifecycle state
     * @param int    $coSignerCount Number of co-signers
     *
     * @return array<string, array{schema: string, object: array<string, mixed>}>
     */
    private static function motionStore(string $lifecycle, int $coSignerCount): array
    {
        $coSigners = [];
        for ($i = 0; $i < $coSignerCount; $i++) {
            $coSigners[] = 'cosigner-'.$i;
        }

        return [
            'motion-1' => [
                // ADR-005: a motion is a `decision` carrying decisionType=motion.
                'schema' => 'decision',
                'object' => [
                    'id'           => 'motion-1',
                    'decisionType' => 'motion',
                    'title'        => 'Duurzaamheidsbeleid',
                    'lifecycle'    => $lifecycle,
                    'coSigners'    => $coSigners,
                ],
            ],
        ];

    }//end motionStore()


    /**
     * The proposed -> deliberating edge is blocked below the threshold, naming
     * the minimum, the current count and the shortfall.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testBelowThresholdRejected(): void
    {
        $service = $this->buildService(self::motionStore('proposed', 1), 2);

        try {
            $service->transitionLifecycle(
                objectId: 'motion-1',
                objectType: 'motion',
                newState: 'deliberating',
                actorId: 'alice',
            );
            self::fail('A motion below the co-signer threshold must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('at least 2', $e->getMessage());
            self::assertStringContainsString('currently has 1', $e->getMessage());
            self::assertStringContainsString('1 more needed', $e->getMessage());
        }

        self::assertCount(0, $this->saves, 'No save should occur on rejection');

    }//end testBelowThresholdRejected()


    /**
     * The edge is allowed once the threshold is met.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testThresholdMetAllowed(): void
    {
        $service = $this->buildService(self::motionStore('proposed', 2), 2);

        $service->transitionLifecycle(
            objectId: 'motion-1',
            objectType: 'motion',
            newState: 'deliberating',
            actorId: 'alice',
        );

        self::assertCount(1, $this->saves);
        self::assertSame('deliberating', $this->saves[0]['lifecycle']);

    }//end testThresholdMetAllowed()


    /**
     * The default threshold (0) disables the check entirely.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testDefaultThresholdDisablesCheck(): void
    {
        $service = $this->buildService(self::motionStore('proposed', 0), 0);

        $service->transitionLifecycle(
            objectId: 'motion-1',
            objectType: 'motion',
            newState: 'deliberating',
            actorId: 'alice',
        );

        self::assertCount(1, $this->saves);

    }//end testDefaultThresholdDisablesCheck()


    /**
     * The threshold only gates the proposed -> deliberating edge, not other edges.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testOtherEdgeNotGated(): void
    {
        // deliberating -> voting with zero co-signers and a threshold of 2 must succeed.
        $service = $this->buildService(self::motionStore('deliberating', 0), 2);

        $service->transitionLifecycle(
            objectId: 'motion-1',
            objectType: 'motion',
            newState: 'voting',
            actorId: 'alice',
        );

        self::assertCount(1, $this->saves);
        self::assertSame('voting', $this->saves[0]['lifecycle']);

    }//end testOtherEdgeNotGated()


    /**
     * Amendments are never gated by the motion co-signer threshold.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testAmendmentNotGated(): void
    {
        $store = [
            'amendment-1' => [
                // ADR-005: an amendment is a `decision` carrying decisionType=amendment.
                'schema' => 'decision',
                'object' => [
                    'id'           => 'amendment-1',
                    'decisionType' => 'amendment',
                    'lifecycle'    => 'proposed',
                ],
            ],
        ];

        $service = $this->buildService($store, 2);

        $service->transitionLifecycle(
            objectId: 'amendment-1',
            objectType: 'amendment',
            newState: 'deliberating',
            actorId: 'alice',
        );

        self::assertCount(1, $this->saves);

    }//end testAmendmentNotGated()


    /**
     * An empty actorId is rejected before any threshold check (the #317 guard).
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testEmptyActorRejected(): void
    {
        $service = $this->buildService(self::motionStore('proposed', 5), 2);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/actorId/');

        $service->transitionLifecycle(
            objectId: 'motion-1',
            objectType: 'motion',
            newState: 'deliberating',
            actorId: '',
        );

    }//end testEmptyActorRejected()
}//end class
