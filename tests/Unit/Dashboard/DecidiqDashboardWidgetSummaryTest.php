<?php

/**
 * Unit tests for how DecidiqDashboardWidget renders a user summary.
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
use OCA\Decidiq\Kernel\Contracts\DashboardWidgetServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The widget's ordering, icon url, empty-content message and next-meeting
 * item follow exactly from the summary and the url generator.
 *
 * @covers \OCA\Decidiq\Dashboard\DecidiqDashboardWidget
 *
 * @spec openspec/specs/dashboard/spec.md
 */
class DecidiqDashboardWidgetSummaryTest extends TestCase {

	/**
	 * Build a widget whose service answers the given summary.
	 *
	 * @param array<string, mixed> $summary The summary the service answers.
	 *
	 * @return DecidiqDashboardWidget
	 */
	private function widget(array $summary): DecidiqDashboardWidget {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->with('decidiq.dashboard.page')->willReturn('https://nc.example/index.php/apps/decidiq/');
		$url->method('imagePath')->with('decidiq', 'app-dark.svg')->willReturn('/custom_apps/decidiq/img/app-dark.svg');
		$url->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://nc.example' . $path);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1781352000);

		$service = $this->createMock(DashboardWidgetServiceInterface::class);
		$service->method('getUserSummary')->with('alice', 1781352000)->willReturn($summary);

		return new DecidiqDashboardWidget(l10n: $l10n, urlGenerator: $url, timeFactory: $time, widgetService: $service);
	}//end widget()

	/**
	 * The widget sorts at weight 20 and its icon is the absolute dark app icon.
	 *
	 * @return void
	 */
	public function testOrderAndIconUrl(): void {
		$widget = $this->widget(summary: ['pendingVotes' => 0]);

		self::assertSame(20, $widget->getOrder());
		self::assertSame('https://nc.example/custom_apps/decidiq/img/app-dark.svg', $widget->getIconUrl());
		self::assertSame('https://nc.example/index.php/apps/decidiq/', $widget->getUrl());
	}//end testOrderAndIconUrl()

	/**
	 * The items say the user is caught up when empty, and the cursor and limit
	 * do not change the two summary items.
	 *
	 * @return void
	 */
	public function testTheTwoItemsIgnoreCursorAndLimit(): void {
		$result = $this->widget(summary: ['pendingVotes' => 2])->getItemsV2('alice', 'decidiq-pending-votes', 1);
		$items  = $result->getItems();

		self::assertSame('You are all caught up', $result->getEmptyContentMessage());
		self::assertCount(2, $items);
		self::assertSame('Pending votes: 2', $items[0]->getTitle());
		self::assertSame('https://nc.example/index.php/apps/decidiq/', $items[0]->getLink());
		self::assertSame('https://nc.example/custom_apps/decidiq/img/app-dark.svg', $items[0]->getIconUrl());
	}//end testTheTwoItemsIgnoreCursorAndLimit()

	/**
	 * A missing or non-array next meeting renders the "No upcoming meetings" placeholder.
	 *
	 * @return void
	 */
	public function testANonArrayNextMeetingIsThePlaceholder(): void {
		foreach ([['pendingVotes' => 0], ['pendingVotes' => 0, 'nextMeeting' => 'm1'], ['pendingVotes' => 0, 'nextMeeting' => null]] as $summary) {
			$item = $this->widget(summary: $summary)->getItemsV2('alice')->getItems()[1];

			self::assertSame('No upcoming meetings', $item->getTitle());
			self::assertSame('', $item->getSubtitle());
			self::assertSame('decidiq-next-meeting', $item->getSinceId());
			self::assertSame('https://nc.example/index.php/apps/decidiq/', $item->getLink());
		}
	}//end testANonArrayNextMeetingIsThePlaceholder()

	/**
	 * A next meeting with a null title is named by its name, and an ISO date
	 * with a zone is shown as its own wall-clock time.
	 *
	 * @return void
	 */
	public function testANullTitleFallsBackToTheNameAndTheDateKeepsItsZone(): void {
		$item = $this->widget(
			summary: ['pendingVotes' => 0, 'nextMeeting' => ['title' => null, 'name' => 'Commissie', 'scheduledDate' => '2026-06-15T10:30:00+02:00']]
		)->getItemsV2('alice')->getItems()[1];

		self::assertSame('Commissie', $item->getTitle());
		self::assertSame('2026-06-15 10:30', $item->getSubtitle());
		self::assertSame('decidiq-next-meeting', $item->getSinceId());
		self::assertSame('https://nc.example/custom_apps/decidiq/img/app-dark.svg', $item->getIconUrl());
	}//end testANullTitleFallsBackToTheNameAndTheDateKeepsItsZone()

	/**
	 * A numeric-string pending count is shown as given.
	 *
	 * @return void
	 */
	public function testAStringPendingCountIsShownAsGiven(): void {
		$items = $this->widget(summary: ['pendingVotes' => '12', 'nextMeeting' => null])->getItemsV2('alice')->getItems();

		self::assertSame('Pending votes: 12', $items[0]->getTitle());
		self::assertSame('Decisions awaiting your vote', $items[0]->getSubtitle());
	}//end testAStringPendingCountIsShownAsGiven()
}//end class
