<?php

/**
 * Characterisation test for the nullable entity-to-array normalisation.
 *
 * Six classes turn an ObjectService find/findAll/saveObject result into a plain
 * array, or null, through a private `toArray(mixed $entity)`. Two behaviours
 * exist and both are pinned here:
 *
 * - falls through (ParticipantToPersonMembershipResolver and the two board-member
 *   repair steps): an object whose jsonSerialize() is not an array is still tried
 *   through getObject();
 * - stops at the first accessor (the comment, email-link and legacy-template
 *   migrations): an object with jsonSerialize() answers with that result or null.
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
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Migration\MigrateCommentsToTalkLeaf;
use OCA\Decidiq\Migration\MigrateEmailLinksToRegistry;
use OCA\Decidiq\Migration\MigrateLegacyTemplatesToDecisionTemplate;
use OCA\Decidiq\Repair\MigrateBoardProxyToProxyAuthorization;
use OCA\Decidiq\Repair\RepointConflictOfInterestBoardMember;
use OCA\Decidiq\Service\ParticipantToPersonMembershipResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

/**
 * Pins `toArray(mixed $entity)` of the six classes that carry it.
 */
class EntityToArrayNormalisationTest extends TestCase {

	/**
	 * The classes whose toArray() falls through to getObject().
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function fallingThrough(): array {
		return [
			'membership resolver' => [ParticipantToPersonMembershipResolver::class],
			'board proxy repair' => [MigrateBoardProxyToProxyAuthorization::class],
			'conflict of interest repair' => [RepointConflictOfInterestBoardMember::class],
		];

	}//end fallingThrough()

	/**
	 * The classes whose toArray() stops at the first accessor.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function stoppingAtFirstAccessor(): array {
		return [
			'comments migration' => [MigrateCommentsToTalkLeaf::class],
			'email links migration' => [MigrateEmailLinksToRegistry::class],
			'legacy templates migration' => [MigrateLegacyTemplatesToDecisionTemplate::class],
		];

	}//end stoppingAtFirstAccessor()

	/**
	 * Every class.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function all(): array {
		return array_merge(self::fallingThrough(), self::stoppingAtFirstAccessor());

	}//end all()

	/**
	 * Arrays pass through, entities serialise, getObject() answers, the rest is null.
	 *
	 * @param class-string $class The class under test.
	 *
	 * @return void
	 */
	#[DataProvider('all')]
	public function testSharedOutcomes(string $class): void {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$entity->setObject(['title' => 'Found']);

		self::assertSame(expected: ['a' => 1], actual: $this->normalise(class: $class, entity: ['a' => 1]));
		self::assertSame(expected: [], actual: $this->normalise(class: $class, entity: []));
		self::assertSame(expected: $entity->jsonSerialize(), actual: $this->normalise(class: $class, entity: $entity));
		self::assertSame(expected: ['via' => 'getObject'], actual: $this->normalise(class: $class, entity: new GetObjectOnly()));
		self::assertNull(actual: $this->normalise(class: $class, entity: new GetObjectNotArray()));
		foreach ([null, false, 'row', 3, new stdClass()] as $entity) {
			self::assertNull(actual: $this->normalise(class: $class, entity: $entity));
		}

	}//end testSharedOutcomes()

	/**
	 * A non-array jsonSerialize() falls through to getObject().
	 *
	 * @param class-string $class The class under test.
	 *
	 * @return void
	 */
	#[DataProvider('fallingThrough')]
	public function testNonArraySerialisationFallsThrough(string $class): void {
		self::assertSame(expected: ['via' => 'getObject'], actual: $this->normalise(class: $class, entity: new SerialisesScalar()));
		self::assertNull(actual: $this->normalise(class: $class, entity: new SerialisesScalarOnly()));

	}//end testNonArraySerialisationFallsThrough()

	/**
	 * A non-array jsonSerialize() answers null without trying getObject().
	 *
	 * @param class-string $class The class under test.
	 *
	 * @return void
	 */
	#[DataProvider('stoppingAtFirstAccessor')]
	public function testNonArraySerialisationStops(string $class): void {
		self::assertNull(actual: $this->normalise(class: $class, entity: new SerialisesScalar()));
		self::assertNull(actual: $this->normalise(class: $class, entity: new SerialisesScalarOnly()));

	}//end testNonArraySerialisationStops()

	/**
	 * Call the private `toArray()` of a class.
	 *
	 * @param class-string $class  The class.
	 * @param mixed        $entity The value to normalise.
	 *
	 * @return array<string, mixed>|null The normalised array, or null.
	 */
	private function normalise(string $class, mixed $entity): ?array {
		$reflection = new ReflectionClass($class);

		return $reflection->getMethod('toArray')->invoke($reflection->newInstanceWithoutConstructor(), $entity);

	}//end normalise()
}//end class

/**
 * An object that only offers getObject().
 */
class GetObjectOnly {

	/**
	 * The object payload.
	 *
	 * @return array<string, string>
	 */
	public function getObject(): array {
		return ['via' => 'getObject'];

	}//end getObject()
}//end class

/**
 * An object whose getObject() is not an array.
 */
class GetObjectNotArray {

	/**
	 * A scalar payload.
	 *
	 * @return string
	 */
	public function getObject(): string {
		return 'not an array';

	}//end getObject()
}//end class

/**
 * An object whose jsonSerialize() is a scalar but whose getObject() is an array.
 */
class SerialisesScalar extends GetObjectOnly {

	/**
	 * A scalar serialisation.
	 *
	 * @return string
	 */
	public function jsonSerialize(): string {
		return 'scalar';

	}//end jsonSerialize()
}//end class

/**
 * An object whose jsonSerialize() is a scalar and that has no getObject().
 */
class SerialisesScalarOnly {

	/**
	 * A scalar serialisation.
	 *
	 * @return string
	 */
	public function jsonSerialize(): string {
		return 'scalar';

	}//end jsonSerialize()
}//end class
