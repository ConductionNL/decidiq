<?php

namespace Unit\Service;

use OCA\Decidiq\Service\DemoDataService;
use OCA\Decidiq\Service\SeedProfileService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The example sets an operator picks in the setup wizard.
 *
 * These run against the REAL descriptors in `lib/Settings/profiles`, not a
 * fixture. The point of the change is that the shipped files are the source of
 * truth, so a test over invented ones would assert nothing about what installs.
 */
class SeedProfileServiceTest extends TestCase {
	private IAppManager $appManager;
	private DemoDataService $demoData;
	private SeedProfileService $service;

	protected function setUp(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));
		$this->demoData = $this->createMock(DemoDataService::class);

		$this->service = new SeedProfileService(
			$this->appManager,
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			$this->demoData
		);
	}

	public function testEveryShippedSetIsOffered(): void {
		$this->demoData->method('isAvailable')->willReturn(false);

		$ids = array_column($this->service->listProfiles(), 'id');

		// Read from disk rather than a list in code, so a set that ships without
		// being offered is impossible by construction.
		$this->assertSame(['municipality', 'association', 'corporate', 'works-council'], $ids);
	}

	public function testSetsAreOfferedInTheirDeclaredOrder(): void {
		$this->demoData->method('isAvailable')->willReturn(false);

		$profiles = $this->service->listProfiles();

		$this->assertSame('municipality', $profiles[0]['id']);
		// `order` is an implementation detail of the file, not of the offer.
		$this->assertArrayNotHasKey('order', $profiles[0]);
		$this->assertGreaterThan(0, $profiles[0]['objectCount']);
		$this->assertNotSame('', $profiles[0]['label']);
	}

	public function testTheGeneratedSetIsOfferedOnlyWhenItShips(): void {
		// ADR-111 rule 2 keeps the schema-generated dataset on offer, but only
		// when the descriptor is actually on disk: offering an import that
		// cannot run is worse than not offering it.
		$this->demoData->method('isAvailable')->willReturn(true);
		$this->assertContains('generated', array_column($this->service->listProfiles(), 'id'));

		$absent = new SeedProfileService(
			$this->appManager,
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			$this->stubDemoData(available: false)
		);
		$this->assertNotContains('generated', array_column($absent->listProfiles(), 'id'));
	}

	public function testAnUnknownSetIsNotKnown(): void {
		$this->demoData->method('isAvailable')->willReturn(false);

		$this->assertTrue($this->service->isKnown('municipality'));
		$this->assertFalse($this->service->isKnown('atlantis'));
	}

	public function testInstallingAnUnknownSetThrowsRatherThanReturningNothing(): void {
		$this->demoData->method('isAvailable')->willReturn(false);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No example set is called "atlantis".');

		$this->service->install('atlantis');
	}

	public function testASetIdCannotEscapeTheProfileDirectory(): void {
		// 🔴 THE POINT OF THIS TEST. The id arrives from an HTTP request. If the
		// path were built by concatenating it, `../../config/config` would name a
		// readable file, so resolution goes through the descriptors that were
		// actually read and matched on their declared id.
		$this->demoData->method('isAvailable')->willReturn(false);

		foreach (['../../config/config', '../decidesk_register', 'municipality/../../../etc/passwd'] as $hostile) {
			$this->assertFalse($this->service->isKnown($hostile), $hostile . ' must not resolve');

			try {
				$this->service->install($hostile);
				$this->fail('Installing ' . $hostile . ' must throw');
			} catch (RuntimeException $e) {
				$this->assertStringContainsString('No example set is called', $e->getMessage());
			}
		}
	}

	public function testTheWizardOffersExactlyTheSetsThatShip(): void {
		// 🔴 THE MANIFEST IS A SECOND COPY OF THE LIST, SO IT CAN DRIFT.
		// `CnSetupWizard` reads a `choice` step's options from
		// `manifest.setup.steps[].options` (a static array), not from the server,
		// so adding a set without listing it there ships a set nobody can pick,
		// and removing one leaves an option whose import fails at the next step.
		$manifest = json_decode(
			(string)file_get_contents(dirname(__DIR__, 3) . '/src/manifest.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		$step = null;
		foreach (($manifest['setup']['steps'] ?? []) as $candidate) {
			if (($candidate['id'] ?? '') === 'example-set') {
				$step = $candidate;
			}
		}

		$this->assertNotNull($step, 'The wizard must ask which example set to load');
		$this->assertSame('choice', $step['type']);
		$this->assertSame('example_profile', $step['configKey']);

		$this->demoData->method('isAvailable')->willReturn(true);
		$expected = array_merge(
			[SeedProfileService::NONE_PROFILE],
			array_column($this->service->listProfiles(), 'id')
		);

		$this->assertSame($expected, array_column($step['options'], 'value'));
	}

	public function testNoSeedIsReferencedByASlugTooLongToStore(): void {
		// 🔴 A 37-CHARACTER SLUG SILENTLY DROPPED AN OBJECT.
		//
		// OpenRegister types a relation column as varchar(36), the width of a
		// UUID. A seed that points at another object BY SLUG therefore fails to
		// insert when that slug is 37 characters or more, and the importer skips
		// the row with a warning nobody reads:
		//
		//   Skipping seed object for 'termijnagenda-item' - import failed:
		//   SQLSTATE[22001] value too long for type character varying(36)
		//
		// Measured on a fresh instance: the municipality set reported "Imported
		// 199 example object(s)" and 198 landed, because
		// `lta-herziening-parkeerbeleid` referenced
		// `goal-amsterdam-parkeerbeleid-kwartaal` (37). The count comes from the
		// FILE, so the message could not tell the operator anything was wrong.
		//
		// The rule is about being REFERENCED, not about being long: `_slug`
		// itself is a wider column, and 20 other seeds carry slugs past 36
		// characters with no trouble because nothing points at them.
		$objects = [];
		foreach (glob(dirname(__DIR__, 3) . '/lib/Settings/profiles/*.json') as $file) {
			$data = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
			foreach (($data['x-openregister']['seedData']['objects'] ?? []) as $schema => $seeds) {
				foreach ($seeds as $seed) {
					$objects[] = [$schema, $seed];
				}
			}
		}

		$slugs = [];
		foreach ($objects as [$schema, $seed]) {
			$slugs[(string)($seed['slug'] ?? '')] = true;
		}

		$offenders = [];
		foreach ($objects as [$schema, $seed]) {
			$ownSlug = (string)($seed['slug'] ?? '');
			foreach ($this->everyString($seed) as $value) {
				if ($value !== $ownSlug && isset($slugs[$value]) === true && strlen($value) > 36) {
					$offenders[] = $schema . '/' . $ownSlug . ' -> ' . $value . ' (' . strlen($value) . ' chars)';
				}
			}
		}

		$this->assertSame(
			[],
			array_values(array_unique($offenders)),
			'A seed referenced by another seed must have a slug of at most 36 characters, '
			. 'or OpenRegister cannot store the reference and drops the referring object.'
		);
	}

	/** Every string anywhere in a seed, however deeply nested. */
	private function everyString(array $seed): array {
		$found = [];
		array_walk_recursive(
			$seed,
			static function ($value) use (&$found): void {
				if (is_string($value) === true) {
					$found[] = $value;
				}
			}
		);

		return $found;
	}

	private function stubDemoData(bool $available): DemoDataService {
		$stub = $this->createMock(DemoDataService::class);
		$stub->method('isAvailable')->willReturn($available);

		return $stub;
	}
}
