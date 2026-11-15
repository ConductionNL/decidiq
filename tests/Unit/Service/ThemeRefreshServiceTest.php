<?php

/**
 * Tests for ThemeRefreshService and ThemeRefreshJob: which published themes
 * the nightly run republishes, and which it leaves alone.
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
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-004-an-active-published-theme-stays-current
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\BackgroundJob\ThemeRefreshJob;
use OCA\Decidiq\Kernel\Contracts\PublicationServiceInterface;
use OCA\Decidiq\Kernel\Contracts\SystemWhenNobodySignedInInterface;
use OCA\Decidiq\Service\ThemeRefreshService;
use OCA\Decidiq\Service\ThemeTimelineBuilder;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The refresh over a mocked register, timeline and publication path.
 */
#[CoversClass(ThemeRefreshService::class)]
#[CoversClass(ThemeRefreshJob::class)]
final class ThemeRefreshServiceTest extends TestCase {
	/**
	 * The construction decision of the swimming pool, published.
	 *
	 * @var array<string,mixed>
	 */
	private const CONSTRUCTION = [
		'date' => '2027-03-01', 'kind' => 'decision', 'title' => 'Bouwbesluit zwembad',
		'objectId' => 'd-bouw', 'publication' => 'pub-bouw',
	];

	/**
	 * The first meeting, published.
	 *
	 * @var array<string,mixed>
	 */
	private const MEETING = ['date' => '2026-02-10', 'kind' => 'meeting', 'title' => 'Raad 10 februari', 'objectId' => 'm-1', 'publication' => 'pub-m1'];

	/**
	 * The rectify calls made: record id, actor and reason.
	 *
	 * @var list<array{0:string,1:string,2:string}>
	 */
	private array $rectified = [];

	/**
	 * The service over the given rows, with the given timeline now per theme id.
	 *
	 * @param array<string,list<array<string,mixed>>> $rows     Rows per schema.
	 * @param array<string,list<array<string,mixed>>> $timeline The published entries now, per theme id.
	 * @param LoggerInterface|null                    $logger   The logger.
	 *
	 * @return ThemeRefreshService
	 */
	private function service(array $rows, array $timeline, ?LoggerInterface $logger = null): ThemeRefreshService {
		$schema  = '';
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			static function (string $slug) use (&$schema, $objects): ObjectServiceInterface {
				$schema = $slug;
				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use (&$schema, $rows): array {
				return array_values(
					array_filter(
						($rows[$schema] ?? []),
						static function (array $row) use ($config): bool {
							foreach (($config['filters'] ?? []) as $field => $value) {
								if (($row[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);
		$objects->method('find')->willReturnCallback(
			function (
				string|int $id, ?array $_extend = [], bool $_files = false,
				mixed $register = null, mixed $schema = null
			) use ($rows): ?ObjectEntityInterface {
				foreach (($rows[(string)$schema] ?? []) as $row) {
					if ($row['id'] === $id) {
						$entity = $this->createMock(ObjectEntityInterface::class);
						$entity->method('jsonSerialize')->willReturn($row);
						return $entity;
					}
				}

				return null;
			}
		);

		$builder = $this->createMock(ThemeTimelineBuilder::class);
		$builder->method('publishedEntries')->willReturnCallback(static fn (array $theme): array => ($timeline[$theme['id']] ?? []));

		$publications = $this->createMock(PublicationServiceInterface::class);
		$publications->method('rectify')->willReturnCallback(
			function (string $recordId, string $actorId, string $reason): array {
				if ($recordId === 'rec-broken') {
					throw new RuntimeException('catalog down');
				}

				$this->rectified[] = [$recordId, $actorId, $reason];
				return ['record' => [], 'previous' => [], 'warnings' => []];
			}
		);

		return new ThemeRefreshService($objects, $builder, $publications, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end service()

	/**
	 * "A new decision joins the story": the pool theme gained the construction
	 * decision and is republished from its newest version; an unchanged theme,
	 * a concluded one, one not public and one never published are left alone.
	 *
	 * @return void
	 */
	public function testOnlyAnActivePublicThemeWithAChangedTimelineIsRepublished(): void {
		$rows = [
			'governance-theme' => [
				['id' => 't-pool', 'title' => 'Nieuw zwembad De Hoge Dijk', 'status' => 'active', 'isPublic' => true],
				['id' => 't-same', 'title' => 'Centrumplan', 'status' => 'active', 'isPublic' => true],
				['id' => 't-done', 'title' => 'Afgerond', 'status' => 'concluded', 'isPublic' => true],
				['id' => 't-private', 'title' => 'Intern', 'status' => 'active', 'isPublic' => false],
				['id' => 't-never', 'title' => 'Nooit gepubliceerd', 'status' => 'active', 'isPublic' => true],
			],
			'publication-record' => [
				[
					'id' => 'rec-private', 'sourceType' => 'theme', 'sourceObject' => 't-private',
					'status' => 'published', 'payloadVersion' => 1, 'payloadObject' => 'pay-done',
				],
				[
					'id' => 'rec-pool-1', 'sourceType' => 'theme', 'sourceObject' => 't-pool',
					'status' => 'published', 'payloadVersion' => 1, 'payloadObject' => 'pay-pool-1',
				],
				[
					'id' => 'rec-pool-2', 'sourceType' => 'theme', 'sourceObject' => 't-pool',
					'status' => 'published', 'payloadVersion' => 2, 'payloadObject' => 'pay-pool-2',
				],
				[
					'id' => 'rec-same', 'sourceType' => 'theme', 'sourceObject' => 't-same',
					'status' => 'published', 'payloadVersion' => 1, 'payloadObject' => 'pay-same',
				],
				[
					'id' => 'rec-done', 'sourceType' => 'theme', 'sourceObject' => 't-done',
					'status' => 'published', 'payloadVersion' => 1, 'payloadObject' => 'pay-done',
				],
			],
			'publication-payload' => [
				['id' => 'pay-pool-2', 'timeline' => [self::MEETING]],
				['id' => 'pay-same', 'timeline' => [array_reverse(self::MEETING, true)]],
				['id' => 'pay-done', 'timeline' => []],
			],
		];
		$timeline = [
			't-pool' => [self::MEETING, self::CONSTRUCTION], 't-same' => [self::MEETING], 't-done' => [self::MEETING], 't-private' => [self::MEETING],
		];

		$outcome = $this->service($rows, $timeline)->refresh();

		$this->assertSame(['republished' => ['t-pool'], 'unchanged' => ['t-same'], 'failed' => []], $outcome);
		$this->assertSame([['rec-pool-2', 'system', 'The theme timeline changed; the nightly refresh published a new version.']], $this->rectified);
	}//end testOnlyAnActivePublicThemeWithAChangedTimelineIsRepublished()

	/**
	 * A failing republish is logged and the next theme still runs; a lost payload counts as changed.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndALostPayloadRepublishes(): void {
		$rows = [
			'governance-theme' => [
				['id' => 't-broken', 'status' => 'active', 'isPublic' => true],
				['id' => 't-lost', 'status' => 'active', 'isPublic' => true],
			],
			'publication-record' => [
				[
					'id' => 'rec-broken', 'sourceType' => 'theme', 'sourceObject' => 't-broken',
					'status' => 'published', 'payloadVersion' => 1, 'payloadObject' => 'pay-x',
				],
				[
					'id' => 'rec-lost', 'sourceType' => 'theme', 'sourceObject' => 't-lost',
					'status' => 'published', 'payloadVersion' => 1, 'payloadObject' => 'pay-gone',
				],
			],
			'publication-payload' => [],
		];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Decidiq theme refresh: a theme could not be republished',
			['themeId' => 't-broken', 'exception' => 'catalog down']
		);

		$outcome = $this->service($rows, ['t-broken' => [self::MEETING], 't-lost' => [self::MEETING]], $logger)->refresh();

		$this->assertSame(['republished' => ['t-lost'], 'unchanged' => [], 'failed' => ['t-broken']], $outcome);
	}//end testAFailureIsLoggedAndALostPayloadRepublishes()

	/**
	 * The job runs nightly, as the system, and logs the counts.
	 *
	 * @return void
	 */
	public function testTheJobRunsTheRefreshAsTheSystemNightly(): void {
		$refresh = $this->createMock(ThemeRefreshService::class);
		$refresh->expects($this->once())->method('refresh')->willReturn(['republished' => ['t-pool'], 'unchanged' => [], 'failed' => []]);
		$system = $this->createMock(SystemWhenNobodySignedInInterface::class);
		$system->expects($this->once())->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('Decidiq theme refresh done', ['republished' => 1, 'unchanged' => 0, 'failed' => 0]);

		$job = new ThemeRefreshJob($this->createMock(ITimeFactory::class), $refresh, $system, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame(86400, $job->getInterval());
	}//end testTheJobRunsTheRefreshAsTheSystemNightly()
}//end class
