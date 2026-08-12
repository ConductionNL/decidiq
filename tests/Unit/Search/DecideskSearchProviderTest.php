<?php

/**
 * Unit tests for DecideskSearchProvider.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Search;

use OCA\Decidesk\Search\DecideskSearchProvider;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests id/name/order, the empty-term short-circuit, result mapping,
 * and fail-soft behavior.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class DecideskSearchProviderTest extends TestCase {

	/**
	 * Build the provider over a schema-routed fake ObjectService.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema schema → rows
	 * @param bool $broken True = ObjectService unavailable
	 *
	 * @return DecideskSearchProvider
	 */
	private function makeProvider(array $rowsBySchema = [], bool $broken = false): DecideskSearchProvider {
		$objectService = new class($rowsBySchema) {

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema schema → rows
			 */
			public function __construct(
				private array $rowsBySchema,
			) {
			}

			/**
			 * Schema-routed findAll fixture.
			 *
			 * @param array<string, mixed> $config Query config
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				return ($this->rowsBySchema[$config['schema'] ?? ''] ?? []);
			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		if ($broken === true) {
			$container->method('get')->willThrowException(new \RuntimeException('OR missing'));
		} else {
			$container->method('get')->willReturn($objectService);
		}

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturn('/img/decidesk/app-dark.svg');
		$urlGenerator->method('linkToRoute')->willReturn('/apps/decidesk/');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DecideskSearchProvider(
			container: $container,
			urlGenerator: $urlGenerator,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end makeProvider()

	/**
	 * Build a search query mock for a term.
	 *
	 * @param string $term Search term
	 *
	 * @return ISearchQuery
	 */
	private function query(string $term): ISearchQuery {
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);
		return $query;
	}//end query()

	/**
	 * Identity + ordering contract.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testIdentityAndOrder(): void {
		$provider = $this->makeProvider();

		self::assertSame(expected: 'decidesk', actual: $provider->getId());
		self::assertSame(expected: -1, actual: $provider->getOrder('decidesk.dashboard.page', []));
		self::assertSame(expected: 25, actual: $provider->getOrder('files.view.index', []));

	}//end testIdentityAndOrder()

	/**
	 * Empty terms short-circuit with no OR access.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testEmptyTermShortCircuits(): void {
		$provider = $this->makeProvider(broken: true);

		$result = $provider->search($this->createMock(IUser::class), $this->query('   '));

		self::assertSame(expected: [], actual: $result->jsonSerialize()['entries']);

	}//end testEmptyTermShortCircuits()

	/**
	 * Result rows from every searched schema map into entries; rows without
	 * id/title are dropped.
	 *
	 * The supertype refactor (ADR-005) folded the former `resolution` schema
	 * into `decision` (resolutions are now Decision rows carrying a
	 * decisionType), so the provider searches the two live schemas
	 * (`decision`, `meeting`). A row supplied under the retired `resolution`
	 * schema is never queried and must not surface.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testMapsRowsAcrossSchemas(): void {
		$provider = $this->makeProvider(
			rowsBySchema: [
				'decision' => [
					['id' => 'd-1', 'title' => 'Budget 2026', 'lifecycle' => 'enacted'],
					['id' => 'd-broken'],
				],
				'meeting' => [
					['id' => 'm-1', 'title' => 'Q2 Board Meeting', 'lifecycle' => 'scheduled'],
				],
				// Retired schema — supplied to prove it is not searched.
				'resolution' => [
					['id' => 'r-1', 'title' => 'Merger resolution', 'status' => 'adopted'],
				],
			]
		);

		$result = $provider->search($this->createMock(IUser::class), $this->query('budget'));
		$entries = $result->jsonSerialize()['entries'];

		// d-1 + m-1 map; d-broken (no title) is dropped; r-1 is never queried.
		self::assertCount(expectedCount: 2, haystack: $entries);

	}//end testMapsRowsAcrossSchemas()

	/**
	 * A broken register fails soft with an empty result.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 *
	 * @return void
	 */
	public function testFailsSoftOnBrokenRegister(): void {
		$provider = $this->makeProvider(broken: true);

		$result = $provider->search($this->createMock(IUser::class), $this->query('budget'));

		self::assertSame(expected: [], actual: $result->jsonSerialize()['entries']);

	}//end testFailsSoftOnBrokenRegister()
}//end class
