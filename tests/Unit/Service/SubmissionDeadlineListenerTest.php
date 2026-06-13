<?php

/**
 * Unit tests for the motion-amendment-v1 submission deadline gate
 * (SubmissionDeadlineListener on OpenRegister's ObjectCreatingEvent):
 *
 * - a motion created after the meeting's submissionDeadline is rejected
 *   (propagation stopped + spec message),
 * - a motion before the deadline is allowed,
 * - no deadline configured = allowed (the gate is opt-in),
 * - an amendment resolves its meeting through the parent motion,
 * - non-motion/amendment schemas are ignored,
 * - an infrastructure failure fails soft (allowed, never throws).
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

use OCA\Decidesk\Listener\SubmissionDeadlineListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Submission-deadline enforcement matrix.
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
class SubmissionDeadlineListenerTest extends TestCase
{


    /**
     * Build a listener over an in-memory object store double.
     *
     * @param array<string, array<string, mixed>> $store Seed objects by id (raw payloads)
     *
     * @return SubmissionDeadlineListener
     */
    private function buildListener(array $store): SubmissionDeadlineListener
    {
        $storeRef = new \ArrayObject($store);

        $objectService = new class($storeRef) {

            /**
             * Constructor.
             *
             * @param \ArrayObject $store In-memory object store
             */
            public function __construct(private \ArrayObject $store)
            {
            }

            /**
             * Find an object by id, returning an entity-like wrapper.
             *
             * @param int|string      $id       Object id
             * @param string|int|null $register Register slug
             * @param string|int|null $schema   Schema slug
             *
             * @return object|null
             */
            public function find(int|string $id, string|int|null $register=null, string|int|null $schema=null): ?object
            {
                $payload = ($this->store[(string) $id] ?? null);
                if ($payload === null) {
                    return null;
                }

                return new class($payload) {

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
                };
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

        return new SubmissionDeadlineListener(
            container: $container,
            logger: new NullLogger(),
        );

    }//end buildListener()


    /**
     * Build an ObjectCreatingEvent carrying an entity that serialises to $row.
     *
     * @param array<string, mixed> $row The object payload being created
     *
     * @return ObjectCreatingEvent
     */
    private function eventFor(array $row): ObjectCreatingEvent
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('getObject')->willReturn($row);
        $entity->method('jsonSerialize')->willReturn($row);

        return new ObjectCreatingEvent($entity);

    }//end eventFor()


    /**
     * A motion created after the meeting's deadline is rejected.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testLateMotionRejected(): void
    {
        $pastDeadline = (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM);
        $listener     = $this->buildListener(
            ['meeting-1' => ['id' => 'meeting-1', 'submissionDeadline' => $pastDeadline]]
        );

        $event = $this->eventFor(['_schemaSlug' => 'motion', 'meeting' => 'meeting-1']);
        $listener->handle($event);

        self::assertTrue($event->isPropagationStopped());
        self::assertSame(SubmissionDeadlineListener::REJECTION_MESSAGE, $event->getErrors()['message']);

    }//end testLateMotionRejected()


    /**
     * A motion created before the deadline is allowed.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testOnTimeMotionAllowed(): void
    {
        $futureDeadline = (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM);
        $listener       = $this->buildListener(
            ['meeting-1' => ['id' => 'meeting-1', 'submissionDeadline' => $futureDeadline]]
        );

        $event = $this->eventFor(['_schemaSlug' => 'motion', 'meeting' => 'meeting-1']);
        $listener->handle($event);

        self::assertFalse($event->isPropagationStopped());

    }//end testOnTimeMotionAllowed()


    /**
     * No deadline configured = the gate is opt-in, so creation is allowed.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testNoDeadlineAllowed(): void
    {
        $listener = $this->buildListener(['meeting-1' => ['id' => 'meeting-1']]);

        $event = $this->eventFor(['_schemaSlug' => 'motion', 'meeting' => 'meeting-1']);
        $listener->handle($event);

        self::assertFalse($event->isPropagationStopped());

    }//end testNoDeadlineAllowed()


    /**
     * An amendment resolves its meeting through the parent motion and is
     * rejected when that meeting's deadline has passed.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testLateAmendmentRejectedViaParentMotion(): void
    {
        $pastDeadline = (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM);
        $listener     = $this->buildListener(
            [
                'meeting-1' => ['id' => 'meeting-1', 'submissionDeadline' => $pastDeadline],
                'motion-1'  => ['id' => 'motion-1', 'meeting' => 'meeting-1'],
            ]
        );

        $event = $this->eventFor(['_schemaSlug' => 'amendment', 'parentMotion' => 'motion-1']);
        $listener->handle($event);

        self::assertTrue($event->isPropagationStopped());

    }//end testLateAmendmentRejectedViaParentMotion()


    /**
     * A non-motion/amendment schema is ignored entirely.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testOtherSchemaIgnored(): void
    {
        $pastDeadline = (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM);
        $listener     = $this->buildListener(
            ['meeting-1' => ['id' => 'meeting-1', 'submissionDeadline' => $pastDeadline]]
        );

        $event = $this->eventFor(['_schemaSlug' => 'decision', 'meeting' => 'meeting-1']);
        $listener->handle($event);

        self::assertFalse($event->isPropagationStopped());

    }//end testOtherSchemaIgnored()


    /**
     * A non-ObjectCreatingEvent is ignored.
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testNonMatchingEventIgnored(): void
    {
        $listener = $this->buildListener([]);
        $event    = new Event();

        $listener->handle($event);

        self::assertFalse($event->isPropagationStopped());

    }//end testNonMatchingEventIgnored()


    /**
     * An infrastructure failure during lookup fails soft (no throw, allowed).
     *
     * @spec openspec/specs/motion-amendment/spec.md
     *
     * @return void
     */
    public function testInfrastructureFailureFailsSoft(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new \RuntimeException('OR unavailable'));

        $listener = new SubmissionDeadlineListener(
            container: $container,
            logger: new NullLogger(),
        );

        $event = $this->eventFor(['_schemaSlug' => 'motion', 'meeting' => 'meeting-1']);
        $listener->handle($event);

        self::assertFalse($event->isPropagationStopped());

    }//end testInfrastructureFailureFailsSoft()
}//end class
