<?php

/**
 * Tests for MotionLinkResolver.
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
 * @spec openspec/specs/motion-amendment/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MotionLinkResolver;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Reading the meeting link of a motion and the parent link of an amendment,
 * in every shape the register has stored them.
 *
 * @covers \OCA\Decidiq\Service\MotionLinkResolver
 */
class MotionLinkResolverTest extends TestCase {

	/**
	 * A resolver whose ObjectService answers find() with the given result.
	 *
	 * @param mixed                    $found   What find() returns, or a Throwable to throw
	 * @param array<int, mixed>|null   $calls   Receives the find() arguments
	 *
	 * @return MotionLinkResolver
	 */
	private function resolver(mixed $found, ?array &$calls=null): MotionLinkResolver {
		$calls         = [];
		$objectService = new class($found, $calls) {
			/**
			 * @param mixed             $found The answer
			 * @param array<int, mixed> $calls The call log
			 */
			public function __construct(private mixed $found, private array &$calls) {
			}

			/**
			 * @param string            $id      The object id
			 * @param ObjectFindOptions $options The find options
			 *
			 * @return mixed
			 */
			public function find(string $id, ObjectFindOptions $options): mixed {
				$this->calls[] = [$id, $options];
				if ($this->found instanceof \Throwable) {
					throw $this->found;
				}
				return $this->found;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);
		return new MotionLinkResolver(container: $container);
	}//end resolver()

	/**
	 * An entity serialising to the given array.
	 *
	 * @param array<string, mixed> $data The serialised object
	 *
	 * @return \JsonSerializable
	 */
	private function entity(array $data): \JsonSerializable {
		return new class($data) implements \JsonSerializable {
			/**
			 * @param array<string, mixed> $data The serialised object
			 */
			public function __construct(private array $data) {
			}

			/**
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return $this->data;
			}
		};
	}//end entity()

	/**
	 * The meeting link is read from the flat string, the {id}/{uuid} object and
	 * the relations list, and the motion is looked up as a decidiq decision.
	 *
	 * @return void
	 */
	public function testResolveMeetingIdReadsEveryShape(): void {
		$calls = [];
		self::assertSame('m-flat', $this->resolver($this->entity(['meeting' => 'm-flat']), $calls)->resolveMeetingId(motionId: 'mo-1'));
		self::assertSame('mo-1', $calls[0][0]);
		self::assertSame('decidiq', $calls[0][1]->register);
		self::assertSame('decision', $calls[0][1]->schema);

		self::assertSame('m-id', $this->resolver($this->entity(['meeting' => ['id' => 'm-id', 'uuid' => 'm-uuid']]))->resolveMeetingId(motionId: 'x'));
		self::assertSame('m-uuid', $this->resolver($this->entity(['meeting' => ['uuid' => 'm-uuid']]))->resolveMeetingId(motionId: 'x'));
		self::assertSame(
			'm-rel',
			$this->resolver($this->entity(['meeting' => '', 'relations' => [['schema' => 'agenda', 'id' => 'a'], ['schema' => 'meeting', 'id' => 'm-rel']]]))->resolveMeetingId(motionId: 'x')
		);
	}//end testResolveMeetingIdReadsEveryShape()

	/**
	 * No meeting context: an unknown motion, an unlinked one, an empty object
	 * link, or a failing store all answer null.
	 *
	 * @return void
	 */
	public function testResolveMeetingIdAnswersNullWithoutAMeeting(): void {
		self::assertNull($this->resolver(null)->resolveMeetingId(motionId: 'x'));
		self::assertNull($this->resolver($this->entity(['title' => 'T']))->resolveMeetingId(motionId: 'x'));
		self::assertNull($this->resolver($this->entity(['meeting' => ['id' => '']]))->resolveMeetingId(motionId: 'x'));
		self::assertNull($this->resolver($this->entity(['relations' => [['schema' => 'meeting']]]))->resolveMeetingId(motionId: 'x'));
		self::assertNull($this->resolver(new RuntimeException('store down'))->resolveMeetingId(motionId: 'x'));
	}//end testResolveMeetingIdAnswersNullWithoutAMeeting()

	/**
	 * serializeAmendment() accepts arrays and serialisable entities only.
	 *
	 * @return void
	 */
	public function testSerializeAmendment(): void {
		$resolver = $this->resolver(null);

		self::assertSame(['id' => 'a'], $resolver->serializeAmendment(entity: ['id' => 'a']));
		self::assertSame(['id' => 'b'], $resolver->serializeAmendment(entity: $this->entity(['id' => 'b'])));
		self::assertNull($resolver->serializeAmendment(entity: 'a-string'));
		self::assertNull($resolver->serializeAmendment(entity: new \stdClass()));
		self::assertNull(
			$resolver->serializeAmendment(
				entity: new class {
					/**
					 * @return string
					 */
					public function jsonSerialize(): string {
						return 'scalar';
					}
				}
			)
		);
	}//end testSerializeAmendment()

	/**
	 * The amends property matches in string and {id}/{uuid} form.
	 *
	 * @return void
	 */
	public function testAmendsPropertyMatchesTheMotion(): void {
		$resolver = $this->resolver(null);

		self::assertTrue($resolver->amendmentReferencesMotion(amendment: ['amends' => 'mo-1'], motionId: 'mo-1'));
		self::assertTrue($resolver->amendmentReferencesMotion(amendment: ['amends' => ['id' => 'mo-1']], motionId: 'mo-1'));
		self::assertTrue($resolver->amendmentReferencesMotion(amendment: ['amends' => ['uuid' => 'mo-1']], motionId: 'mo-1'));
		self::assertFalse($resolver->amendmentReferencesMotion(amendment: ['amends' => 'mo-2'], motionId: 'mo-1'));
		self::assertFalse($resolver->amendmentReferencesMotion(amendment: ['amends' => ['id' => 'mo-2']], motionId: 'mo-1'));
		self::assertFalse($resolver->amendmentReferencesMotion(amendment: ['parentMotion' => 'mo-1'], motionId: 'mo-1'), 'The retired parentMotion property is not read.');
	}//end testAmendsPropertyMatchesTheMotion()

	/**
	 * A relation matches by id or uuid under no schema, decision or motion,
	 * and a bare string relation matches by value.
	 *
	 * @return void
	 */
	public function testRelationsMatchTheMotion(): void {
		$resolver = $this->resolver(null);

		foreach ([['id' => 'mo-1'], ['uuid' => 'mo-1', 'schema' => 'decision'], ['id' => 'mo-1', 'schema' => 'motion']] as $relation) {
			self::assertTrue($resolver->amendmentReferencesMotion(amendment: ['relations' => [$relation]], motionId: 'mo-1'));
		}

		self::assertTrue($resolver->amendmentReferencesMotion(amendment: ['relations' => ['other', 'mo-1']], motionId: 'mo-1'));
		self::assertFalse($resolver->amendmentReferencesMotion(amendment: ['relations' => [['id' => 'mo-1', 'schema' => 'meeting']]], motionId: 'mo-1'));
		self::assertFalse($resolver->amendmentReferencesMotion(amendment: ['relations' => [['id' => 'mo-2'], 'mo-3', 42]], motionId: 'mo-1'));
		self::assertFalse($resolver->amendmentReferencesMotion(amendment: [], motionId: 'mo-1'));
	}//end testRelationsMatchTheMotion()
}//end class
