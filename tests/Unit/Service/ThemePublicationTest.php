<?php

/**
 * Tests for publishing a theme: who may, and what its payload carries.
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
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-003-a-published-theme-carries-only-published-items
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Service\AgendaPapers;
use OCA\Decidiq\Service\PublicationConfigService;
use OCA\Decidiq\Service\PublicationEligibilityService;
use OCA\Decidiq\Service\PublicationPayloadService;
use OCA\Decidiq\Service\ThemeTimelineBuilder;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A theme publishes only when it is public and something in it is published.
 */
class ThemePublicationTest extends TestCase {

	/**
	 * The timeline entries the builder answers.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $published = [];

	/**
	 * The schema the source was loaded from.
	 *
	 * @var string|int|null
	 */
	private string|int|null $loadedSchema = null;

	/**
	 * A timeline builder answering the configured published entries.
	 *
	 * @return ThemeTimelineBuilder The builder.
	 */
	private function timeline(): ThemeTimelineBuilder {
		$timeline = $this->createPartialMock(ThemeTimelineBuilder::class, ['publishedEntries']);
		$timeline->method('publishedEntries')->willReturnCallback(fn (): array => $this->published);
		return $timeline;
	}//end timeline()

	/**
	 * The eligibility service over one stored theme.
	 *
	 * @param array<string, mixed> $theme The stored theme.
	 *
	 * @return PublicationEligibilityService The service.
	 */
	private function eligibility(array $theme): PublicationEligibilityService {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use ($theme): ObjectEntity {
				$this->loadedSchema = $schema;
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($theme);
				return $entity;
			}
		);

		return new PublicationEligibilityService($this->createMock(LoggerInterface::class), objectService: $objectService, themeTimeline: $this->timeline());
	}//end eligibility()

	/**
	 * A public theme with a published item is eligible, read from the theme schema.
	 *
	 * @return void
	 */
	public function testAPublicThemeWithAPublishedItemIsEligible(): void {
		$this->published = [['objectId' => 'd-1']];
		$theme = ['title' => 'Nieuw zwembad', 'isPublic' => true];

		$this->assertSame($theme, $this->eligibility($theme)->assertEligible('theme', 't-1'));
		$this->assertSame('governance-theme', $this->loadedSchema);
	}//end testAPublicThemeWithAPublishedItemIsEligible()

	/**
	 * A theme not marked public is refused.
	 *
	 * @return void
	 */
	public function testAThemeNotMarkedPublicIsRefused(): void {
		$this->published = [['objectId' => 'd-1']];

		$this->expectException(AccessDeniedException::class);
		$this->expectExceptionMessage('Only themes marked public are publishable.');
		$this->eligibility(['title' => 'Nieuw zwembad', 'isPublic' => 'yes'])->assertEligible('theme', 't-1');
	}//end testAThemeNotMarkedPublicIsRefused()

	/**
	 * A theme whose items are all unpublished is refused with a reason.
	 *
	 * @return void
	 */
	public function testAThemeWithNothingPublishedIsRefused(): void {
		$this->published = [];

		$this->expectException(AccessDeniedException::class);
		$this->expectExceptionMessage('A theme is publishable once at least one of its items is published itself.');
		$this->eligibility(['title' => 'Nieuw zwembad', 'isPublic' => true])->assertEligible('theme', 't-1');
	}//end testAThemeWithNothingPublishedIsRefused()

	/**
	 * The payload carries the theme's text and only the published timeline.
	 *
	 * @return void
	 */
	public function testThePayloadCarriesTheTextAndThePublishedTimeline(): void {
		$this->published = [
			['date' => '2026-10-14', 'kind' => 'meeting', 'title' => 'Gemeenteraad', 'objectId' => 'm-1', 'publication' => 'pay-1'],
		];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$timeline = $this->timeline();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => ($id === ThemeTimelineBuilder::class) ? $timeline : $objectService
		);
		$logger = $this->createMock(LoggerInterface::class);
		$service = new PublicationPayloadService($container, $logger, new PublicationConfigService($appConfig), new AgendaPapers($objectService, $container, $logger));

		$payload = $service->build(
			'theme',
			[
				'title' => 'Nieuw zwembad De Hoge Dijk',
				'introduction' => 'De gemeente bouwt een nieuw zwembad.',
				'blocks' => [['heading' => 'Planning', 'body' => 'Opening in 2029.', 'internal' => 'x'], 'junk'],
				'status' => 'active',
				'portfolioHolder' => 'person-1',
				'meetings' => ['m-1', 'm-2'],
			],
			null,
			2
		);

		$this->assertSame('theme', $payload['documentType']);
		$this->assertSame('Nieuw zwembad De Hoge Dijk', $payload['title']);
		$this->assertSame('De gemeente bouwt een nieuw zwembad.', $payload['introduction']);
		$this->assertSame([['heading' => 'Planning', 'body' => 'Opening in 2029.']], $payload['blocks']);
		$this->assertSame('active', $payload['status']);
		$this->assertSame(2, $payload['payloadVersion']);
		$this->assertSame($this->published, $payload['timeline']);
		$this->assertArrayNotHasKey('meetings', $payload);
		$this->assertArrayNotHasKey('portfolioHolder', $payload);
	}//end testThePayloadCarriesTheTextAndThePublishedTimeline()
}//end class
