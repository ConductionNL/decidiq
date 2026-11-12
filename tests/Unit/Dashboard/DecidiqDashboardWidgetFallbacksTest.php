<?php

/**
 * Unit tests for DecidiqDashboardWidget's fallbacks.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dashboard/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Dashboard;

use OCA\Decidiq\Dashboard\DecidiqDashboardWidget;
use OCA\Decidiq\Service\DashboardWidgetService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Dashboard\Model\WidgetButton;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The widget's link survives a missing route, and the next meeting is named
 * and dated from whatever the summary carries.
 *
 * @covers \OCA\Decidiq\Dashboard\DecidiqDashboardWidget
 *
 * @spec openspec/specs/dashboard/spec.md
 */
class DecidiqDashboardWidgetFallbacksTest extends TestCase {

	/**
	 * The user ids the summary was asked for, with the time.
	 *
	 * @var array<int, array{0: string, 1: int}>
	 */
	private array $asked = [];

	/**
	 * Build a widget over a summary, with or without the app route.
	 *
	 * @param array<string, mixed> $summary  The summary the service answers.
	 * @param bool                 $hasRoute Whether the dashboard route resolves.
	 *
	 * @return DecidiqDashboardWidget
	 */
	private function widget(array $summary, bool $hasRoute = true): DecidiqDashboardWidget {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		$url = $this->createMock(IURLGenerator::class);
		if ($hasRoute === true) {
			$url->method('linkToRouteAbsolute')->with('decidiq.dashboard.page')->willReturn('https://nc.example/index.php/apps/decidiq/');
		} else {
			$url->method('linkToRouteAbsolute')->willThrowException(new RuntimeException('route not registered'));
		}

		$url->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://nc.example' . $path);
		$url->method('imagePath')->with('decidiq', 'app-dark.svg')->willReturn('/apps/decidiq/img/app-dark.svg');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1781352000);

		$asked = &$this->asked;
		$service = new class($summary, $asked) extends DashboardWidgetService {

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed>              $summary The summary.
			 * @param array<int, array{0: string, 1: int}> $asked   Where calls are recorded.
			 */
			public function __construct(
				private array $summary,
				private array &$asked,
			) {
			}

			/**
			 * Record the call and answer the summary.
			 *
			 * @param string $userId User id
			 * @param int    $now    Now
			 *
			 * @return array<string, mixed>
			 */
			public function getUserSummary(string $userId, int $now): array {
				$this->asked[] = [$userId, $now];

				return $this->summary;
			}//end getUserSummary()
		};

		return new DecidiqDashboardWidget(l10n: $l10n, urlGenerator: $url, timeFactory: $time, widgetService: $service);
	}//end widget()

	/**
	 * Without the dashboard route the links fall back to the app path.
	 *
	 * @return void
	 */
	public function testWithoutTheRouteTheLinksUseTheAppPath(): void {
		$widget = $this->widget(summary: ['pendingVotes' => 1, 'nextMeeting' => null], hasRoute: false);

		self::assertSame('https://nc.example/apps/decidiq/', $widget->getUrl());

		$button = $widget->getWidgetButtons('alice')[0];
		self::assertSame(WidgetButton::TYPE_MORE, $button->getType());
		self::assertSame('https://nc.example/apps/decidiq/', $button->getLink());
		self::assertSame('Open Decidiq', $button->getText());

		foreach ($widget->getItemsV2('alice')->getItems() as $item) {
			self::assertSame('https://nc.example/apps/decidiq/', $item->getLink());
			self::assertSame('https://nc.example/apps/decidiq/img/app-dark.svg', $item->getIconUrl());
		}
	}//end testWithoutTheRouteTheLinksUseTheAppPath()

	/**
	 * The summary is asked for the user at the clock's time, and the items carry their ids.
	 *
	 * @return void
	 */
	public function testTheSummaryIsAskedForTheUserAtTheClocksTime(): void {
		$items = $this->widget(summary: ['pendingVotes' => 5, 'nextMeeting' => null])->getItemsV2('bob')->getItems();

		self::assertSame([['bob', 1781352000]], $this->asked);
		self::assertSame('Pending votes: 5', $items[0]->getTitle());
		self::assertSame('Decisions awaiting your vote', $items[0]->getSubtitle());
		self::assertSame('decidiq-pending-votes', $items[0]->getSinceId());
		self::assertSame('decidiq-next-meeting', $items[1]->getSinceId());
		self::assertSame('', $items[1]->getSubtitle());
	}//end testTheSummaryIsAskedForTheUserAtTheClocksTime()

	/**
	 * The next meeting is titled by its title, else its name, else generically.
	 *
	 * @return void
	 */
	public function testTheNextMeetingTitleFallsBack(): void {
		$title = fn (array $meeting): string => $this->widget(summary: ['pendingVotes' => 0, 'nextMeeting' => $meeting])
			->getItemsV2('alice')->getItems()[1]->getTitle();

		self::assertSame('Raad', $title(['title' => 'Raad', 'name' => 'Commissie']));
		self::assertSame('Commissie', $title(['name' => 'Commissie']));
		self::assertSame('Next meeting', $title([]));
	}//end testTheNextMeetingTitleFallsBack()

	/**
	 * The next meeting is dated from its schedule, and generically without a usable one.
	 *
	 * @return void
	 */
	public function testTheNextMeetingSubtitleFallsBack(): void {
		$subtitle = fn (array $meeting): string => $this->widget(summary: ['pendingVotes' => 0, 'nextMeeting' => $meeting])
			->getItemsV2('alice')->getItems()[1]->getSubtitle();

		self::assertSame('2026-06-15 10:00', $subtitle(['title' => 'Raad', 'scheduledDate' => '2026-06-15 10:00:00']));
		self::assertSame('Your next meeting', $subtitle(['title' => 'Raad']));
		self::assertSame('Your next meeting', $subtitle(['title' => 'Raad', 'scheduledDate' => 'not a date']));
	}//end testTheNextMeetingSubtitleFallsBack()

	/**
	 * Loading needs no assets, and the icon class is the app's.
	 *
	 * @return void
	 */
	public function testLoadingNeedsNothing(): void {
		$widget = $this->widget(summary: ['pendingVotes' => 0, 'nextMeeting' => null]);

		$widget->load();

		self::assertSame('icon-decidiq', $widget->getIconClass());
		self::assertSame([], $this->asked);
	}//end testLoadingNeedsNothing()
}//end class
