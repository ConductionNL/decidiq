<?php

/**
 * Characterisation test for the saved-object normalisation of the participation services.
 *
 * AdvisoryVoteService, BoardEvaluationResponseService, BoardEvaluationScoreService,
 * BudgetVotingService, ParticipationLifecycleService, ParticipationPublicationService
 * and ReactionIntakeService each turn the value `ObjectService::saveObject()`
 * returns into an array through a private `normaliseSaved()`. This pins the three
 * outcomes for every one of them: an ObjectEntity serialises, an array passes
 * through unchanged, and anything else yields the fallback.
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

use OCA\Decidiq\Service\AdvisoryVoteService;
use OCA\Decidiq\Service\BoardEvaluationResponseService;
use OCA\Decidiq\Service\BoardEvaluationScoreService;
use OCA\Decidiq\Service\BudgetVotingService;
use OCA\Decidiq\Service\ParticipationLifecycleService;
use OCA\Decidiq\Service\ParticipationPublicationService;
use OCA\Decidiq\Service\ReactionIntakeService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

/**
 * Pins `normaliseSaved()` of every participation service.
 */
class SavedObjectNormalisationTest extends TestCase {

	/**
	 * The services whose saved-object normalisation is pinned.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function services(): array {
		return [
			'advisory vote' => [AdvisoryVoteService::class],
			'board evaluation response' => [BoardEvaluationResponseService::class],
			'board evaluation score' => [BoardEvaluationScoreService::class],
			'budget voting' => [BudgetVotingService::class],
			'participation lifecycle' => [ParticipationLifecycleService::class],
			'participation publication' => [ParticipationPublicationService::class],
			'reaction intake' => [ReactionIntakeService::class],
		];

	}//end services()

	/**
	 * An ObjectEntity normalises to its serialised form.
	 *
	 * @param class-string $class The service under test.
	 *
	 * @return void
	 */
	#[DataProvider('services')]
	public function testEntitySerialises(string $class): void {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$entity->setObject(['title' => 'Saved']);

		self::assertSame(
			expected: $entity->jsonSerialize(),
			actual: $this->normalise(class: $class, saved: $entity)
		);

	}//end testEntitySerialises()

	/**
	 * An array passes through unchanged, the fallback ignored.
	 *
	 * @param class-string $class The service under test.
	 *
	 * @return void
	 */
	#[DataProvider('services')]
	public function testArrayPassesThrough(string $class): void {
		self::assertSame(expected: ['id' => 'a', 'n' => 1], actual: $this->normalise(class: $class, saved: ['id' => 'a', 'n' => 1]));
		self::assertSame(expected: [], actual: $this->normalise(class: $class, saved: []));

	}//end testArrayPassesThrough()

	/**
	 * Anything else yields the fallback.
	 *
	 * @param class-string $class The service under test.
	 *
	 * @return void
	 */
	#[DataProvider('services')]
	public function testOtherValuesYieldTheFallback(string $class): void {
		foreach ([null, false, 'saved', 7, new stdClass()] as $saved) {
			self::assertSame(expected: ['fallback' => true], actual: $this->normalise(class: $class, saved: $saved));
		}

	}//end testOtherValuesYieldTheFallback()

	/**
	 * Call the private `normaliseSaved()` of a service with the fallback `['fallback' => true]`.
	 *
	 * @param class-string $class The service.
	 * @param mixed        $saved The value saveObject() returned.
	 *
	 * @return array<string, mixed> The normalised array.
	 */
	private function normalise(string $class, mixed $saved): array {
		$reflection = new ReflectionClass($class);
		$method = $reflection->getMethod('normaliseSaved');

		return $method->invoke(
			$reflection->newInstanceWithoutConstructor(),
			$saved,
			['fallback' => true]
		);

	}//end normalise()
}//end class
