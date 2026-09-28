<?php

/**
 * Unit tests for DecidiqSearchProvider.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Search
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

namespace OCA\Decidiq\Tests\Unit\Search;

use OCA\Decidiq\Search\DecidiqSearchProvider;
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
class DecidiqSearchProviderTest extends TestCase {

	/**
	 * The object-service double the last makeProvider() built.
	 *
	 * @var object|null
	 */
	private ?object $lastObjectService = null;

	/**
	 * Build the provider over a schema-routed fake ObjectService.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema schema → rows
	 * @param bool $broken True = ObjectService unavailable
	 *
	 * @return DecidiqSearchProvider
	 */
	private function makeProvider(array $rowsBySchema = [], bool $broken = false): DecidiqSearchProvider {
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
			/**
			 * Argument counts of every findAll() call.
			 *
			 * @var array<int, int>
			 */
			public array $argCounts = [];

			public function findAll(array $config = []): array {
				$this->argCounts[] = func_num_args();
				// OpenRegister's ObjectService::findAll() reads the register and
				// schema ONLY from $config['filters'] (prepareFindAllConfig());
				// top-level 'register'/'schema' keys are ignored. The double
				// answers the same way, or it would pass a query the real
				// service runs against no schema at all.
				if (($config['filters']['register'] ?? null) !== 'decidiq') {
					return [];
				}

				return ($this->rowsBySchema[$config['filters']['schema'] ?? ''] ?? []);
			}//end findAll()
		};

		$this->lastObjectService = $objectService;
		$container = $this->createMock(ContainerInterface::class);
		if ($broken === true) {
			$container->method('get')->willThrowException(new \RuntimeException('OR missing'));
		} else {
			$container->method('get')->willReturn($objectService);
		}

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturn('/img/decidiq/app-dark.svg');
		$urlGenerator->method('linkToRoute')->willReturn('/apps/decidiq/');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DecidiqSearchProvider(
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

		self::assertSame(expected: 'decidiq', actual: $provider->getId());
		self::assertSame(expected: -1, actual: $provider->getOrder('decidiq.dashboard.page', []));
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

	/**
	 * Minutes are searched, and a hit names Minutes, its lifecycle and its
	 * approval date, opens the minutes page, and uses no em-dash.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md#requirement-req-mus-001-minutes-appear-in-nextclouds-unified-search
	 *
	 * @return void
	 */
	public function testMinutesAreFoundAndOpenTheMinutesPage(): void {
		$provider = $this->makeProvider(
			rowsBySchema: [
				'minutes' => [
					['id' => 'min-1', 'title' => 'Notulen raad 14 oktober', 'lifecycle' => 'approved', 'approvedAt' => '2026-11-01T10:00:00+00:00'],
				],
			]
		);

		$entries = $provider->search($this->createMock(IUser::class), $this->query('woningbouw'))->jsonSerialize()['entries'];

		self::assertCount(1, $entries);
		$entry = $entries[0]->jsonSerialize();
		self::assertSame('Notulen raad 14 oktober', $entry['title']);
		self::assertSame('Minutes · approved · 2026-11-01', $entry['subline']);
		self::assertSame('/apps/decidiq/minutes/min-1', $entry['resourceUrl']);

	}//end testMinutesAreFoundAndOpenTheMinutesPage()

	/**
	 * Every hit opens its detail page under the history router, never a
	 * `#/` hash the router ignores, and no subline carries an em-dash.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md#requirement-req-mus-001-minutes-appear-in-nextclouds-unified-search
	 *
	 * @return void
	 */
	public function testEveryHitOpensItsPageWithoutAnEmDash(): void {
		$provider = $this->makeProvider(
			rowsBySchema: [
				'decision' => [['id' => 'd-1', 'title' => 'Budget 2026', 'lifecycle' => 'enacted', 'decisionDate' => '2026-06-01']],
				'meeting' => [['id' => 'm-1', 'title' => 'Raad', 'lifecycle' => 'scheduled', 'scheduledDate' => '2026-10-14T19:30:00Z']],
			]
		);

		$entries = $provider->search($this->createMock(IUser::class), $this->query('raad'))->jsonSerialize()['entries'];
		$urls = [];
		foreach ($entries as $entry) {
			$data = $entry->jsonSerialize();
			$urls[] = $data['resourceUrl'];
			self::assertStringNotContainsString('—', $data['subline']);
		}

		self::assertSame(['/apps/decidiq/decisions/d-1', '/apps/decidiq/meetings/m-1'], $urls);

	}//end testEveryHitOpensItsPageWithoutAnEmDash()

	/**
	 * The provider never switches OpenRegister's read rules off: every query
	 * passes only its config, so findAll() keeps its default `_rbac = true` and
	 * a searcher gets only what they may read.
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md#requirement-req-mus-002-search-shows-only-minutes-the-searcher-may-read
	 *
	 * @return void
	 */
	public function testSearchKeepsOpenRegisterReadRules(): void {
		$provider = $this->makeProvider(rowsBySchema: []);
		$provider->search($this->createMock(IUser::class), $this->query('woningbouw'));

		self::assertSame([1, 1, 1], $this->lastObjectService->argCounts, 'one findAll per schema, config only, RBAC left on');

	}//end testSearchKeepsOpenRegisterReadRules()
}//end class
