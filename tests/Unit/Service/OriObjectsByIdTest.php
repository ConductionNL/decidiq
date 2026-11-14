<?php

/**
 * Characterisation test for all() of the ORI publication rules.
 *
 * OriPersonPublicationRule and OriVotePublicationRule each read one schema of
 * the decidiq register, unscoped and capped at the rule's own limit, into a map
 * keyed by object id through a private `all()`. This pins the query and the
 * keying for both.
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

use OCA\Decidiq\Service\OriPersonPublicationRule;
use OCA\Decidiq\Service\OriVotePublicationRule;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Pins `all()` of both ORI publication rules.
 */
class OriObjectsByIdTest extends TestCase {

	/**
	 * The rules and their limits.
	 *
	 * @return array<string, array{0: class-string, 1: int}>
	 */
	public static function rules(): array {
		return [
			'person rule' => [OriPersonPublicationRule::class, 500],
			'vote rule' => [OriVotePublicationRule::class, 100],
		];

	}//end rules()

	/**
	 * The query is unscoped and capped; rows key by id, @self.id, then uuid.
	 *
	 * @param class-string $class The rule under test.
	 * @param int          $limit The rule's limit.
	 *
	 * @return void
	 */
	#[DataProvider('rules')]
	public function testAll(string $class, int $limit): void {
		$rows = [
			['id' => 'a', 'n' => 1],
			['@self' => ['id' => 'b'], 'n' => 2],
			['uuid' => 'c', 'n' => 3],
			['id' => '', 'uuid' => 'ignored-because-id-is-set', 'n' => 4],
			['id' => 7, 'n' => 5],
			['n' => 6],
			['id' => 'a', 'n' => 7],
		];
		$entities = [];
		foreach ($rows as $row) {
			$entity = $this->createMock(originalClassName: ObjectEntity::class);
			$entity->method('jsonSerialize')->willReturn($row);
			$entities[] = $entity;
		}

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'decidiq', 'schema' => 'person'], 'limit' => $limit], false, false)
			->willReturn($entities);

		$rule = $this->rule(class: $class, objectService: $objectService);
		$all = (new ReflectionClass($class))->getMethod('all')->invoke($rule, 'person');

		self::assertSame(
			expected: [
				'a' => ['id' => 'a', 'n' => 7],
				'b' => ['@self' => ['id' => 'b'], 'n' => 2],
				'c' => ['uuid' => 'c', 'n' => 3],
			],
			actual: $all
		);

	}//end testAll()

	/**
	 * Build a rule, every other constructor dependency a mock.
	 *
	 * @param class-string           $class         The rule.
	 * @param ObjectServiceInterface $objectService The object service.
	 *
	 * @return object The rule.
	 */
	private function rule(string $class, ObjectServiceInterface $objectService): object {
		$arguments = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			assert($type instanceof ReflectionNamedType);
			$arguments[$parameter->getName()] = $objectService;
			if ($type->getName() !== ObjectServiceInterface::class) {
				$arguments[$parameter->getName()] = $this->createMock(originalClassName: $type->getName());
			}
		}

		return new $class(...$arguments);

	}//end rule()
}//end class
