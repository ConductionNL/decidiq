<?php

/**
 * What ContractBindings aliases, and that every alias is satisfiable.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Kernel\Contracts\ActionItemMigrationServiceInterface;
use OCA\Decidiq\Kernel\Contracts\DecisionCompetenceGuardInterface;
use OCA\Decidiq\Kernel\Contracts\FormResponseImportServiceInterface;
use OCA\Decidiq\Kernel\Contracts\GoverningDocumentReleaseInterface;
use OCA\Decidiq\Kernel\Contracts\LegalRemedyResolverInterface;
use OCA\Decidiq\Kernel\Contracts\ThemeRefreshServiceInterface;
use OCA\Decidiq\Kernel\Contracts\WrittenRoundCloserInterface;
use OCA\Decidiq\Kernel\Contracts\WrittenRoundStoreInterface;
use OCA\Decidiq\Service\ActionItemMigrationService;
use OCA\Decidiq\Service\ContractBindings;
use OCA\Decidiq\Service\DecisionCompetenceGuard;
use OCA\Decidiq\Service\Forms\FormResponseImportService;
use OCA\Decidiq\Service\GoverningDocument\GoverningDocumentRelease;
use OCA\Decidiq\Service\LegalRemedyResolver;
use OCA\Decidiq\Service\ThemeRefreshService;
use OCA\Decidiq\Service\WrittenRoundCloser;
use OCA\Decidiq\Service\WrittenRoundStore;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Nextcloud autowires classes but not interfaces, so a contract a job,
 * listener or command type-hints without an alias fails to resolve at
 * runtime. Pins the aliases of the contracts those callers use and that every
 * alias points at a class implementing its contract.
 *
 * @covers \OCA\Decidiq\Service\ContractBindings
 */
class ContractBindingsTest extends TestCase {

	/**
	 * Aliases registered: contract => implementation.
	 *
	 * @var array<string, string>
	 */
	private array $aliases = [];

	/**
	 * Register into a recording context.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			function (string $alias, string $target): void {
				$this->aliases[$alias] = $target;
			}
		);

		(new ContractBindings())->register(context: $context);
	}//end setUp()

	/**
	 * The contracts reached from jobs, listeners and commands are aliased to their services.
	 *
	 * @return void
	 */
	public function testTheJobListenerAndCommandContractsAreAliased(): void {
		$expected = [
			ActionItemMigrationServiceInterface::class => ActionItemMigrationService::class,
			DecisionCompetenceGuardInterface::class => DecisionCompetenceGuard::class,
			FormResponseImportServiceInterface::class => FormResponseImportService::class,
			GoverningDocumentReleaseInterface::class => GoverningDocumentRelease::class,
			LegalRemedyResolverInterface::class => LegalRemedyResolver::class,
			ThemeRefreshServiceInterface::class => ThemeRefreshService::class,
			WrittenRoundCloserInterface::class => WrittenRoundCloser::class,
			WrittenRoundStoreInterface::class => WrittenRoundStore::class,
		];

		foreach ($expected as $contract => $implementation) {
			$this->assertSame($implementation, ($this->aliases[$contract] ?? null), $contract);
		}
	}//end testTheJobListenerAndCommandContractsAreAliased()

	/**
	 * Every alias names an interface and a class that implements it.
	 *
	 * @return void
	 */
	public function testEveryAliasPointsAtAnImplementation(): void {
		$this->assertNotEmpty($this->aliases);
		foreach ($this->aliases as $contract => $implementation) {
			$this->assertTrue(interface_exists($contract), $contract . ' is an interface');
			$this->assertContains($contract, class_implements($implementation), $implementation . ' implements ' . $contract);
		}
	}//end testEveryAliasPointsAtAnImplementation()
}//end class
