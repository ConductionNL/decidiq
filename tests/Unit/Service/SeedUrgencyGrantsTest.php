<?php

/**
 * SeedUrgencyGrants: an example set's own urgent decisions are granted past
 * the urgency field guard for the length of the import, and only those.
 *
 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\SeedUrgencyGrants;
use OCA\Decidiq\Service\UrgencyWriteGrant;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for SeedUrgencyGrants.
 */
class SeedUrgencyGrantsTest extends TestCase {

	/**
	 * A set with one urgent decision, one plain one and one urgent one without an id.
	 *
	 * @return array<string, mixed>
	 */
	private static function descriptor(): array {
		return ['x-openregister' => ['seedData' => ['objects' => ['decision' => [
			['slug' => 'spoed', 'id' => 'd-urgent', 'isUrgent' => true, 'urgencyReason' => 'Hoogwater'],
			['slug' => 'gewoon', 'id' => 'd-plain', 'isUrgent' => false],
			['slug' => 'spoed-zonder-id', 'isUrgent' => true],
		]]]]];
	}//end descriptor()

	/**
	 * Only the urgent decisions with a fixed id are named.
	 *
	 * @return void
	 */
	public function testOnlyUrgentDecisionsWithAnIdAreNamed(): void {
		self::assertSame(expected: ['d-urgent'], actual: SeedUrgencyGrants::urgentDecisionIds(descriptor: self::descriptor()));
	}//end testOnlyUrgentDecisionsWithAnIdAreNamed()

	/**
	 * The grant holds during the import and is gone afterwards.
	 *
	 * @return void
	 */
	public function testTheGrantHoldsOnlyDuringTheImport(): void {
		$grant  = new UrgencyWriteGrant();
		$during = [];

		$result = (new SeedUrgencyGrants(grant: $grant))->during(
			descriptor: self::descriptor(),
			import: static function () use ($grant, &$during): string {
				$during = [$grant->isGranted(decisionId: 'd-urgent'), $grant->isGranted(decisionId: 'd-plain')];
				return 'imported';
			}
		);

		self::assertSame(expected: 'imported', actual: $result);
		self::assertSame(expected: [true, false], actual: $during);
		self::assertFalse(condition: $grant->isGranted(decisionId: 'd-urgent'));
	}//end testTheGrantHoldsOnlyDuringTheImport()

	/**
	 * A failed import still revokes the grants.
	 *
	 * @return void
	 */
	public function testAFailedImportRevokesTheGrants(): void {
		$grant = new UrgencyWriteGrant();

		try {
			(new SeedUrgencyGrants(grant: $grant))->during(
				descriptor: self::descriptor(),
				import: static function (): never {
					throw new RuntimeException(message: 'import failed');
				}
			);
			self::fail(message: 'the failure reaches the caller');
		} catch (RuntimeException $e) {
			self::assertSame(expected: 'import failed', actual: $e->getMessage());
		}

		self::assertFalse(condition: $grant->isGranted(decisionId: 'd-urgent'));
	}//end testAFailedImportRevokesTheGrants()

	/**
	 * Every urgent decision a shipped example set declares has a fixed id, so it is granted.
	 *
	 * @return void
	 */
	public function testEveryShippedUrgentDecisionHasAFixedId(): void {
		$root    = __DIR__ . '/../../../lib/Settings';
		$missing = [];
		foreach (['/profiles/*.json', '/example-sets/*.json'] as $pattern) {
			$files = glob($root . $pattern);
			if (is_array($files) === false) {
				continue;
			}

			foreach ($files as $file) {
				$set = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
				foreach ((array)($set['x-openregister']['seedData']['objects']['decision'] ?? []) as $decision) {
					$urgent = ($decision['isUrgent'] ?? false) === true;
					if ($urgent === true && (string)($decision['id'] ?? ($decision['uuid'] ?? '')) === '') {
						$missing[] = basename($file) . ' ' . (string)($decision['slug'] ?? '?');
					}
				}
			}
		}

		self::assertSame(expected: [], actual: $missing);
	}//end testEveryShippedUrgentDecisionHasAFixedId()
}//end class
