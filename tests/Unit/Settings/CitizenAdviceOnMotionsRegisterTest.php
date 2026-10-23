<?php

/**
 * The register carries a motion's citizen advisory vote, and the municipality
 * example set seeds one closed vote.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * Register test for participation-citizen-advisory-vote-on-motions task 1.
 *
 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
 */
class CitizenAdviceOnMotionsRegisterTest extends TestCase {

	/**
	 * The shipped decision schema carries the status and the three counts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
	 */
	public function testTheDecisionCarriesTheAdvisoryVote(): void {
		$properties = (MergedRegisterSchema::forSlug(slug: 'decision')['properties'] ?? []);

		self::assertSame(['not-open', 'open', 'closed'], $properties['citizenVotingStatus']['enum'] ?? null);
		foreach (['citizenAdviceFor', 'citizenAdviceAgainst', 'citizenAdviceAbstain'] as $count) {
			self::assertSame('integer', $properties[$count]['type'] ?? null, $count . ' is a count');
		}

	}//end testTheDecisionCarriesTheAdvisoryVote()

	/**
	 * The municipality set seeds one published motion with a closed vote of 3, 1 and 1.
	 *
	 * The whole seed is held against the shipped schema, so a
	 * seed the import would refuse fails here rather than on an instance.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
	 */
	public function testTheMunicipalitySetSeedsAClosedAdvisoryVote(): void {
		$profile = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/municipality.json'),
			true
		);
		$seeded  = array_values(
			array_filter(
				($profile['x-openregister']['seedData']['objects']['decision'] ?? []),
				static fn (array $decision): bool => ($decision['citizenVotingAllowed'] ?? false) === true
			)
		);

		self::assertCount(1, $seeded);
		$motion = $seeded[0];
		self::assertSame('motion', $motion['motionType']);
		self::assertSame('public', $motion['isPublished']);
		self::assertSame('closed', $motion['citizenVotingStatus']);
		self::assertSame([3, 1, 1], [$motion['citizenAdviceFor'], $motion['citizenAdviceAgainst'], $motion['citizenAdviceAbstain']]);

		// `@self` and `slug` are import envelope, not schema properties.
		$payload = array_diff_key($motion, array_flip(['@self', 'slug']));
		self::assertSame([], MergedRegisterSchema::violations(slug: 'decision', payload: $payload));

	}//end testTheMunicipalitySetSeedsAClosedAdvisoryVote()
}//end class
