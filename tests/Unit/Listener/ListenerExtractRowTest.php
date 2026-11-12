<?php

/**
 * Characterisation test for extractRow() of the object-event listeners.
 *
 * Six listeners read the stored payload of an OpenRegister object entity the
 * same way: getObject() first, jsonSerialize() when that is empty, and an empty
 * array when neither yields anything. This pins those outcomes for each of them.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\AgendaItemChangeListener;
use OCA\Decidiq\Listener\GovernanceRoleProjectionListener;
use OCA\Decidiq\Listener\MeetingFolderListener;
use OCA\Decidiq\Listener\PlanningCycleCreatedListener;
use OCA\Decidiq\Listener\SubmissionDeadlineListener;
use OCA\Decidiq\Listener\TechnicalQuestionListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

/**
 * Pins `extractRow()` of every listener that carries it.
 */
class ListenerExtractRowTest extends TestCase {

	/**
	 * The listeners whose extractRow() is pinned.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function listeners(): array {
		return [
			'agenda item change' => [AgendaItemChangeListener::class],
			'governance role projection' => [GovernanceRoleProjectionListener::class],
			'meeting folder' => [MeetingFolderListener::class],
			'planning cycle created' => [PlanningCycleCreatedListener::class],
			'submission deadline' => [SubmissionDeadlineListener::class],
			'technical question' => [TechnicalQuestionListener::class],
		];

	}//end listeners()

	/**
	 * getObject() wins, jsonSerialize() fills an empty payload, nothing is [].
	 *
	 * @param class-string $class The listener under test.
	 *
	 * @return void
	 */
	#[DataProvider('listeners')]
	public function testPayloadOutcomes(string $class): void {
		$both = new ObjectAndSerialised(object: ['from' => 'object']);
		self::assertSame(expected: ['from' => 'object'], actual: $this->extract(class: $class, entity: $both));
		self::assertSame(expected: ['from' => 'json'], actual: $this->extract(class: $class, entity: new ObjectAndSerialised(object: [])));
		self::assertSame(expected: ['from' => 'object'], actual: $this->extract(class: $class, entity: new ObjectOnly(object: ['from' => 'object'])));
		self::assertSame(expected: [], actual: $this->extract(class: $class, entity: new ObjectOnly(object: [])));
		self::assertSame(expected: ['from' => 'json'], actual: $this->extract(class: $class, entity: new SerialisedOnly()));
		self::assertSame(expected: [], actual: $this->extract(class: $class, entity: new stdClass()));

	}//end testPayloadOutcomes()

	/**
	 * Call the private `extractRow()` of a listener.
	 *
	 * @param class-string $class  The listener.
	 * @param object       $entity The entity to read.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function extract(string $class, object $entity): array {
		$reflection = new ReflectionClass($class);

		return $reflection->getMethod('extractRow')->invoke($reflection->newInstanceWithoutConstructor(), $entity);

	}//end extract()
}//end class

/**
 * An entity offering only getObject().
 */
class ObjectOnly {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $object The payload getObject() returns.
	 */
	public function __construct(private readonly array $object) {
	}//end __construct()

	/**
	 * The payload.
	 *
	 * @return array<string, mixed>
	 */
	public function getObject(): array {
		return $this->object;

	}//end getObject()
}//end class

/**
 * An entity offering getObject() and jsonSerialize().
 */
class ObjectAndSerialised extends ObjectOnly {

	/**
	 * The serialised form.
	 *
	 * @return array<string, string>
	 */
	public function jsonSerialize(): array {
		return ['from' => 'json'];

	}//end jsonSerialize()
}//end class

/**
 * An entity offering only jsonSerialize().
 */
class SerialisedOnly {

	/**
	 * The serialised form.
	 *
	 * @return array<string, string>
	 */
	public function jsonSerialize(): array {
		return ['from' => 'json'];

	}//end jsonSerialize()
}//end class
