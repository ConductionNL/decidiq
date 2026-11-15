<?php

/**
 * Unit tests for RenameStepTypes.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Migration;

use OCA\Decidiq\Migration\RenameStepTypes;
use OCA\Decidiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The rename of legacy onboarding and offboarding step types.
 *
 * @covers \OCA\Decidiq\Migration\RenameStepTypes
 */
final class RenameStepTypesTest extends TestCase {

	/**
	 * An in-memory object service keyed by schema.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows        Rows per schema
	 * @param list<string>                              $failingUuids Uuids whose save throws
	 *
	 * @return object
	 */
	private function objectService(array $rows, array $failingUuids = []): object {
		return new class ($rows, $failingUuids) {
			public string $schema = '';

			public bool $inSystemScope = false;

			/**
			 * Saved payloads.
			 *
			 * @var list<array<string, mixed>>
			 */
			public array $saved = [];

			public function __construct(private readonly array $rows, private readonly array $failingUuids) {
			}

			public function runAsSystem(callable $callback): mixed {
				$this->inSystemScope = true;
				try {
					return $callback();
				} finally {
					$this->inSystemScope = false;
				}
			}

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $config = []): array {
				return ($this->rows[$this->schema] ?? []);
			}

			public function saveObject(array $object, string $register, string $schema, string $uuid): array {
				if (in_array($uuid, $this->failingUuids, true) === true) {
					throw new RuntimeException("cannot save $uuid");
				}

				$this->saved[] = [
					'register' => $register,
					'schema' => $schema,
					'uuid' => $uuid,
					'object' => $object,
					'asSystem' => $this->inSystemScope,
				];
				return $object;
			}
		};

	}//end objectService()

	/**
	 * Build the step.
	 *
	 * @param object|null          $objectService The object service, null when unresolvable
	 * @param LoggerInterface|null $logger        The logger
	 * @param bool                 $available     Whether OpenRegister is available
	 *
	 * @return RenameStepTypes
	 */
	private function step(?object $objectService, ?LoggerInterface $logger = null, bool $available = true): RenameStepTypes {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($available);
		$container = $this->createMock(ContainerInterface::class);
		if ($objectService === null) {
			$container->method('get')->willThrowException(new RuntimeException('not registered'));
		} else {
			$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);
		}

		return new RenameStepTypes(
			settingsService: $settings,
			container: $container,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
		);

	}//end step()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepNamesWhatItDoes(): void {
		self::assertSame('Rename the Decidiq swearing-in onboarding step to installation', $this->step(null)->getName());

	}//end testTheStepNamesWhatItDoes()

	/**
	 * Without OpenRegister nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsMigrated(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with('OpenRegister unavailable — nothing to migrate.');

		$this->step(null, null, false)->run($output);

	}//end testWithoutOpenRegisterNothingIsMigrated()

	/**
	 * An unresolvable ObjectService is a warning.
	 *
	 * @return void
	 */
	public function testAnUnresolvableObjectServiceWarns(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects(self::never())->method('info');
		$output->expects(self::once())->method('warning')->with('Could not resolve OpenRegister ObjectService: not registered');

		$this->step(null)->run($output);

	}//end testAnUnresolvableObjectServiceWarns()

	/**
	 * Legacy step types are renamed in both schemas, as system, keeping everything else.
	 *
	 * @return void
	 */
	public function testLegacyStepTypesAreRenamedInBothSchemas(): void {
		$service = $this->objectService(
			[
				'member-onboarding' => [
					[
						'id' => 'on-1',
						'@self' => ['register' => 'decidiq'],
						'person' => 'p-1',
						'steps' => [
							['stepType' => 'swearing-in', 'done' => true],
							['stepType' => 'account-linking'],
							'not a step',
							['stepType' => 'political-group-assignment'],
						],
					],
				],
				'member-offboarding' => [
					['id' => 'off-1', 'steps' => [['stepType' => 'groepen-intrekken'], ['stepType' => 'exit-bevestiging']]],
				],
			]
		);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with('Decidiq step-type rename complete: 2 record(s).');
		$output->expects(self::never())->method('warning');

		$this->step($service)->run($output);

		self::assertCount(2, $service->saved);
		[$onboarding, $offboarding] = $service->saved;

		self::assertSame('decidiq', $onboarding['register']);
		self::assertSame('member-onboarding', $onboarding['schema']);
		self::assertSame('on-1', $onboarding['uuid']);
		self::assertTrue($onboarding['asSystem']);
		self::assertArrayNotHasKey('@self', $onboarding['object']);
		self::assertSame('p-1', $onboarding['object']['person']);
		self::assertSame(
			[
				['stepType' => 'installation', 'done' => true],
				['stepType' => 'account-linking'],
				'not a step',
				['stepType' => 'body-group-assignment'],
			],
			$onboarding['object']['steps']
		);

		self::assertSame('member-offboarding', $offboarding['schema']);
		self::assertSame(
			[['stepType' => 'revoke-groups'], ['stepType' => 'exit-confirmation']],
			$offboarding['object']['steps']
		);

	}//end testLegacyStepTypesAreRenamedInBothSchemas()

	/**
	 * Rows already in the new words, without an id or without steps are left alone.
	 *
	 * @return void
	 */
	public function testRowsNeedingNoRenameAreNotSaved(): void {
		$service = $this->objectService(
			[
				'member-onboarding' => [
					['id' => 'on-1', 'steps' => [['stepType' => 'installation']]],
					['steps' => [['stepType' => 'swearing-in']]],
					['id' => 'on-3', 'steps' => 'swearing-in'],
					['id' => 'on-4'],
				],
			]
		);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with('Decidiq step-type rename complete: 0 record(s).');

		$this->step($service)->run($output);

		self::assertSame([], $service->saved);

	}//end testRowsNeedingNoRenameAreNotSaved()

	/**
	 * A failed save is warned and logged, and the other rows still migrate.
	 *
	 * @return void
	 */
	public function testAFailedSaveDoesNotStopTheOthers(): void {
		$service = $this->objectService(
			[
				'member-onboarding' => [
					['id' => 'on-bad', 'steps' => [['stepType' => 'beediging']]],
					['id' => 'on-good', 'steps' => [['stepType' => 'beediging']]],
				],
			],
			['on-bad']
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Decidiq: swearing-in step rename failed for one record',
			['error' => 'cannot save on-bad', 'object' => 'on-bad']
		);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with('Failed to rewrite a step type: cannot save on-bad');
		$output->expects(self::once())->method('info')->with('Decidiq step-type rename complete: 1 record(s).');

		$this->step($service, $logger)->run($output);

		self::assertSame(['on-good'], array_column($service->saved, 'uuid'));
		self::assertSame([['stepType' => 'installation']], $service->saved[0]['object']['steps']);

	}//end testAFailedSaveDoesNotStopTheOthers()
}//end class
