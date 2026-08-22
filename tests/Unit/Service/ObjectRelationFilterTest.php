<?php

/**
 * Unit tests for ObjectRelationFilter.
 *
 * This class had no test, and the defect it exists to prevent shipped anyway:
 * three call sites hand-wrote `_relations.<schema-slug>` instead of calling
 * filterFor(), every one of those queries returned zero rows on a healthy HTTP
 * 200, and the consequences were an advisory ballot that accepted unlimited
 * votes per citizen and a budget publication that reported no proposals at all.
 *
 * The fixtures below are not invented. They are the `@self.relations` payloads
 * dumped from a live OpenRegister instance, which is the only place the storage
 * shape is actually decided:
 *
 *   citizen-vote     {"proposalId": "<uuid>",
 *                     "relations.0.id": "<uuid>",
 *                     "relations.0.schema": "budget-proposal"}
 *   budget-proposal  {"participatoryBudget": "<uuid>"}
 *
 * Note what is NOT in either map: the related schema's slug. That is the whole
 * defect, and testAFilterKeyIsNeverASchemaSlug() is what pins it.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/voting-system/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\ObjectRelationFilter;
use PHPUnit\Framework\TestCase;

/**
 * Pins the one relation filter that matches how decidesk writes relations.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class ObjectRelationFilterTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var ObjectRelationFilter
	 */
	private ObjectRelationFilter $filter;

	/**
	 * Build the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->filter = new ObjectRelationFilter();

	}//end setUp()

	/**
	 * A stand-in for an OpenRegister ObjectEntity: matching() only ever calls
	 * jsonSerialize(), so the double needs nothing else.
	 *
	 * @param array<string,mixed> $payload The serialised object.
	 *
	 * @return object The entity double.
	 */
	private function entity(array $payload): object {
		return new class($payload) {
			/**
			 * @var array<string,mixed>
			 */
			private array $payload;

			/**
			 * @param array<string,mixed> $payload The serialised object.
			 */
			public function __construct(array $payload) {
				$this->payload = $payload;

			}//end __construct()

			/**
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return $this->payload;
			}//end jsonSerialize()
		};

	}//end entity()

	/**
	 * The filter key must address the property PATH, never the related schema.
	 *
	 * OpenRegister's SaveObject::scanForRelations() keys the `_relations` JSONB
	 * by the path it walked (`relations.0.id`), and MagicSearchHandler resolves
	 * `_relations.<field>` as `kv.key = '<field>' OR kv.key LIKE '<field>.%'`.
	 * So `relations` matches and any schema slug cannot. A slug is spelled in
	 * kebab-case in this register, which makes the wrong shape cheap to detect.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function testAFilterKeyIsNeverASchemaSlug(): void {
		$key = ObjectRelationFilter::RELATION_FILTER_FIELD;

		$this->assertSame('_relations.relations', $key);
		$this->assertStringStartsWith('_relations.', $key);
		$this->assertStringNotContainsString(
			'-',
			substr($key, strlen('_relations.')),
			'A hyphen after the prefix means the key is a schema slug, which can never match a '
			. '`relations.<n>.id` entry — the query then returns zero rows on a healthy HTTP 200.'
		);

	}//end testAFilterKeyIsNeverASchemaSlug()

	/**
	 * filterFor() produces the whole filter fragment, keyed correctly.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function testFilterForBuildsTheFragmentCallersShouldMerge(): void {
		$this->assertSame(
			['_relations.relations' => 'target-uuid'],
			$this->filter->filterFor(targetId: 'target-uuid')
		);

	}//end testFilterForBuildsTheFragmentCallersShouldMerge()

	/**
	 * The fragment merges with ordinary property filters without colliding.
	 *
	 * This is the exact shape the advisory-vote duplicate guard needs: scope to
	 * the proposal, then to the voter.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function testFilterForMergesWithAPropertyFilter(): void {
		$merged = ($this->filter->filterFor(targetId: 'proposal-1') + ['voterId' => 'alice']);

		$this->assertSame(
			[
				'_relations.relations' => 'proposal-1',
				'voterId' => 'alice',
			],
			$merged
		);

	}//end testFilterForMergesWithAPropertyFilter()

	/**
	 * matching() keeps a row whose OpenRegister-flattened relations reference it.
	 *
	 * The payload is the live `@self.relations` map for a CitizenVote. The
	 * OpenRegister filter scopes by id but not by schema, which is why this
	 * second pass exists at all.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function testMatchingKeepsTheFlattenedOpenRegisterShape(): void {
		$vote = $this->entity(
			[
				'voterId' => 'alice',
				'@self' => [
					'relations' => [
						'proposalId' => 'proposal-1',
						'relations.0.id' => 'proposal-1',
						'relations.0.schema' => 'budget-proposal',
					],
				],
			]
		);

		$this->assertCount(
			1,
			$this->filter->matching(entities: [$vote], schema: 'budget-proposal', targetId: 'proposal-1')
		);

	}//end testMatchingKeepsTheFlattenedOpenRegisterShape()

	/**
	 * matching() keeps a row carrying the structured relations array as written.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function testMatchingKeepsTheStructuredWriteShape(): void {
		$vote = $this->entity(
			[
				'relations' => [
					[
						'register' => 'decidesk',
						'schema' => 'budget-proposal',
						'id' => 'proposal-1',
					],
				],
			]
		);

		$this->assertCount(
			1,
			$this->filter->matching(entities: [$vote], schema: 'budget-proposal', targetId: 'proposal-1')
		);

	}//end testMatchingKeepsTheStructuredWriteShape()

	/**
	 * matching() drops a row that references a DIFFERENT object.
	 *
	 * Without this the test above would pass for a filter that keeps everything,
	 * which is exactly the failure mode being guarded against — a check that
	 * cannot reject is not a check.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function testMatchingDropsARowForAnotherTarget(): void {
		$other = $this->entity(
			[
				'@self' => [
					'relations' => [
						'proposalId' => 'proposal-2',
						'relations.0.id' => 'proposal-2',
					],
				],
			]
		);

		$this->assertCount(
			0,
			$this->filter->matching(entities: [$other], schema: 'budget-proposal', targetId: 'proposal-1')
		);

	}//end testMatchingDropsARowForAnotherTarget()

	/**
	 * A row with no relations at all is dropped rather than crashing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function testMatchingDropsARowWithNoRelations(): void {
		$bare = $this->entity(['voterId' => 'alice']);

		$this->assertCount(
			0,
			$this->filter->matching(entities: [$bare], schema: 'budget-proposal', targetId: 'proposal-1')
		);

	}//end testMatchingDropsARowWithNoRelations()

	/**
	 * A relations value of the wrong type is dropped, not fatal.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function testMatchingDropsARowWhoseRelationsAreNotAnArray(): void {
		$odd = $this->entity(['relations' => 'not-an-array']);

		$this->assertCount(
			0,
			$this->filter->matching(entities: [$odd], schema: 'budget-proposal', targetId: 'proposal-1')
		);

	}//end testMatchingDropsARowWhoseRelationsAreNotAnArray()

}//end class
