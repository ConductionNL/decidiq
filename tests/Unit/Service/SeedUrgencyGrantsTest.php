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
use OCA\Decidiq\Service\UrgentSeedImport;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
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
	 * Every save: the uuid, and whether its urgency write was granted then.
	 *
	 * @var list<array{0: string, 1: bool}>
	 */
	private array $saves = [];

	/**
	 * The service over an object store that holds every decision.
	 *
	 * @param UrgencyWriteGrant $grant The grant.
	 *
	 * @return SeedUrgencyGrants
	 */
	private function grants(UrgencyWriteGrant $grant): SeedUrgencyGrants {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject(['title' => 'Spoed', 'isUrgent' => true]);
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options) use ($grant): ObjectEntity {
				$this->saves[] = [(string)$options->uuid, $grant->isGranted(decisionId: (string)$options->uuid)];
				return new ObjectEntity();
			}
		);

		return new SeedUrgencyGrants(grant: $grant, objectService: $objects, logger: new NullLogger());
	}//end grants()

	/**
	 * After the import each urgent decision is saved once more, still granted,
	 * so its materialised calculations see the stages imported after it.
	 *
	 * @return void
	 */
	public function testEachUrgentDecisionIsSavedAgainAfterTheImport(): void {
		$grant = new UrgencyWriteGrant();

		$this->grants(grant: $grant)->during(descriptor: self::descriptor(), import: static fn (): bool => true);

		self::assertSame(expected: [['d-urgent', true]], actual: $this->saves);
	}//end testEachUrgentDecisionIsSavedAgainAfterTheImport()

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

		$result = ($this->grants(grant: $grant))->during(
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
			($this->grants(grant: $grant))->during(
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
	 * Through the container: granted when it holds the service, as is when it does not.
	 *
	 * @return void
	 */
	public function testAroundUsesTheContainersGrantsOrImportsAsIs(): void {
		$grant     = new UrgencyWriteGrant();
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($this->grants(grant: $grant));
		$seen = (new UrgentSeedImport())->run(
			container: $container,
			descriptor: self::descriptor(),
			import: static fn (): bool => $grant->isGranted(decisionId: 'd-urgent')
		);
		self::assertTrue(condition: $seen);

		$empty = $this->createMock(originalClassName: ContainerInterface::class);
		$empty->method('get')->willThrowException(new RuntimeException(message: 'not wired'));
		self::assertSame(
			expected: 'as is',
			actual: (new UrgentSeedImport())->run(container: $empty, descriptor: self::descriptor(), import: static fn (): string => 'as is')
		);
	}//end testAroundUsesTheContainersGrantsOrImportsAsIs()

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
