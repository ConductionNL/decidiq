<?php

/**
 * Behaviour tests for DecidiqSearchProvider: the exact query sent per schema,
 * entity rows, row fallbacks, the entry fields and the failure log.
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

use JsonSerializable;
use OCA\Decidiq\Search\DecidiqSearchProvider;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins what the unified search provider asks OpenRegister and what it
 * builds from the answer.
 *
 * @covers \OCA\Decidiq\Search\DecidiqSearchProvider
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class DecidiqSearchProviderBehaviourTest extends TestCase {

	/**
	 * The object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The URL generator mock.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator&MockObject $urlGenerator;

	/**
	 * The provider under test.
	 *
	 * @var DecidiqSearchProvider
	 */
	private DecidiqSearchProvider $provider;

	/**
	 * Build the provider over mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->logger        = $this->createMock(originalClassName: LoggerInterface::class);
		$this->urlGenerator  = $this->createMock(originalClassName: IURLGenerator::class);
		$this->urlGenerator->method('imagePath')->willReturn('/img/decidiq/app-dark.svg');
		$this->urlGenerator->method('linkToRoute')->willReturn('/apps/decidiq/');

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'nl:'.$text);

		$this->provider = new DecidiqSearchProvider(
			objectService: $this->objectService,
			urlGenerator: $this->urlGenerator,
			l10n: $l10n,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * A search query for a term.
	 *
	 * @param string $term The term.
	 *
	 * @return ISearchQuery
	 */
	private function query(string $term): ISearchQuery {
		$query = $this->createMock(originalClassName: ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);

		return $query;
	}//end query()

	/**
	 * Answer findAll() with the rows given per schema.
	 *
	 * @param array<string,array<int,mixed>> $rowsBySchema Schema to rows.
	 *
	 * @return void
	 */
	private function answer(array $rowsBySchema): void {
		$this->objectService->method('findAll')->willReturnCallback(
			static fn (array $config = []): array => ($rowsBySchema[$config['filters']['schema'] ?? ''] ?? [])
		);
	}//end answer()

	/**
	 * Run a search and return the serialised entries.
	 *
	 * @param string $term The term.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function entries(string $term='raad'): array {
		$result = $this->provider->search(user: $this->createMock(originalClassName: IUser::class), query: $this->query(term: $term))->jsonSerialize();

		return array_map(static fn ($entry): array => $entry->jsonSerialize(), $result['entries']);
	}//end entries()

	/**
	 * The provider name is translated and names the result set.
	 *
	 * @return void
	 */
	public function testNameIsTranslatedAndNamesTheResult(): void {
		$this->answer(rowsBySchema: []);

		$this->assertSame(expected: 'nl:Decidiq governance', actual: $this->provider->getName());
		$result = $this->provider->search(user: $this->createMock(originalClassName: IUser::class), query: $this->query(term: 'x'))->jsonSerialize();
		$this->assertSame(expected: 'nl:Decidiq governance', actual: $result['name']);
		$this->assertFalse(condition: $result['isPaginated']);
	}//end testNameIsTranslatedAndNamesTheResult()

	/**
	 * Each schema is asked once, in order, with the trimmed term, the decidiq
	 * register in filters and a limit of five.
	 *
	 * @return void
	 */
	public function testQueriesEverySchemaWithTheTrimmedTerm(): void {
		$configs = [];
		$this->objectService->expects($this->exactly(count: 3))->method('findAll')->willReturnCallback(
			static function (array $config) use (&$configs): array {
				$configs[] = $config;
				return [];
			}
		);

		$this->entries(term: '  woningbouw ');

		$expected = [];
		foreach (['decision', 'meeting', 'minutes'] as $schema) {
			$expected[] = [
				'filters' => ['register' => 'decidiq', 'schema' => $schema],
				'search'  => 'woningbouw',
				'limit'   => 5,
			];
		}

		$this->assertSame(expected: $expected, actual: $configs);
	}//end testQueriesEverySchemaWithTheTrimmedTerm()

	/**
	 * An empty term asks OpenRegister nothing.
	 *
	 * @return void
	 */
	public function testEmptyTermAsksNothing(): void {
		$this->objectService->expects($this->never())->method('findAll');

		$this->assertSame(expected: [], actual: $this->entries(term: "\t "));
	}//end testEmptyTermAsksNothing()

	/**
	 * A decision hit carries the icon, the translated label, its status and
	 * its date, and links to the decision page.
	 *
	 * @return void
	 */
	public function testDecisionEntryFields(): void {
		$this->answer(rowsBySchema: 
			[
				'decision' => [['id' => 'd-1', 'title' => 'Budget 2026', 'lifecycle' => 'enacted', 'decisionDate' => '2026-06-01T09:00:00Z']],
			]
		);

		$this->urlGenerator->expects($this->once())->method('imagePath')->with('decidiq', 'app-dark.svg');

		$this->assertSame(
			expected: [
				[
					'thumbnailUrl' => '/img/decidiq/app-dark.svg',
					'title'        => 'Budget 2026',
					'subline'      => 'nl:Decision · enacted · 2026-06-01',
					'resourceUrl'  => '/apps/decidiq/decisions/d-1',
					'icon'         => 'icon-decidiq',
					'rounded'      => true,
					'attributes'   => [],
				],
			],
			actual: $this->entries()
		);
	}//end testDecisionEntryFields()

	/**
	 * An entity object is read through jsonSerialize(), and its id may come from `@self`.
	 *
	 * @return void
	 */
	public function testEntityRowsAndSelfIdAreRead(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * The serialised object.
			 *
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return ['@self' => ['id' => 'm 1/x'], 'title' => 'Raad', 'status' => 'planned', 'scheduledDate' => '2026-10-14T19:30:00Z'];
			}
		};
		$this->answer(rowsBySchema: ['meeting' => [$entity]]);

		$entries = $this->entries();

		$this->assertCount(expectedCount: 1, haystack: $entries);
		$this->assertSame(expected: 'nl:Meeting · planned · 2026-10-14', actual: $entries[0]['subline']);
		$this->assertSame(expected: '/apps/decidiq/meetings/m%201%2Fx', actual: $entries[0]['resourceUrl']);
	}//end testEntityRowsAndSelfIdAreRead()

	/**
	 * The status falls back from lifecycle to status to outcome, and a row
	 * without status or date shows only its label.
	 *
	 * @return void
	 */
	public function testStatusFallbacksAndBareSubline(): void {
		$this->answer(rowsBySchema: 
			[
				'decision' => [
					['id' => 'd-1', 'title' => 'A', 'outcome' => 'adopted'],
					['id' => 'd-2', 'title' => 'B', 'status' => 'draft', 'outcome' => 'adopted'],
					['id' => 'd-3', 'title' => 'C'],
				],
				'minutes'  => [['id' => 'n-1', 'title' => 'D', 'scheduledDate' => '2026-01-01']],
			]
		);

		$this->assertSame(
			expected: ['nl:Decision · adopted', 'nl:Decision · draft', 'nl:Decision', 'nl:Minutes'],
			actual: array_column($this->entries(), 'subline')
		);
	}//end testStatusFallbacksAndBareSubline()

	/**
	 * Rows that are neither arrays nor objects, or lack an id or title, are skipped.
	 *
	 * @return void
	 */
	public function testUnusableRowsAreSkipped(): void {
		$this->answer(rowsBySchema: 
			[
				'decision' => ['not a row', 42, null, ['title' => 'No id'], ['id' => 'd-1', 'title' => ''], ['id' => 'd-2', 'title' => 'Kept']],
			]
		);

		$this->assertSame(expected: ['Kept'], actual: array_column($this->entries(), 'title'));
	}//end testUnusableRowsAreSkipped()

	/**
	 * A failure is logged with the term and the message, keeping the hits found before it.
	 *
	 * @return void
	 */
	public function testFailureIsLoggedAndEarlierHitsKept(): void {
		$this->objectService->method('findAll')->willReturnCallback(
			static function (array $config): array {
				if ($config['filters']['schema'] === 'meeting') {
					throw new \RuntimeException('OR down');
				}

				return [['id' => 'd-1', 'title' => 'Found first']];
			}
		);
		$this->logger->expects($this->once())->method('error')->with(
			'Decidiq: unified search failed',
			['term' => 'raad', 'exception' => 'OR down']
		);

		$this->assertSame(expected: ['Found first'], actual: array_column($this->entries(), 'title'));
	}//end testFailureIsLoggedAndEarlierHitsKept()
}//end class
