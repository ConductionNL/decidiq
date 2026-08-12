<?php

/**
 * Unit tests for DecideskDashboardWidget.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Dashboard
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

namespace OCA\Decidesk\Tests\Unit\Dashboard;

use OCA\Decidesk\Dashboard\DecideskDashboardWidget;
use OCA\Decidesk\Service\DashboardWidgetService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests widget identity (id/title/order), the IAPIWidgetV2 item shape,
 * deep-link url + button, and the empty/fail-soft item path.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
class DecideskDashboardWidgetTest extends TestCase {

	/**
	 * Build a widget over a service stub returning the given summary.
	 *
	 * @param array{pendingVotes:int, nextMeeting:array<string,mixed>|null} $summary Stub summary
	 *
	 * @return DecideskDashboardWidget
	 */
	private function makeWidget(array $summary): DecideskDashboardWidget {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, $p = []): string {
				$params = is_array($p) === true ? $p : [$p];
				return vsprintf(str_replace('%s', '%s', $text), $params) ?: $text;
			}
		);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://nc.example/index.php/apps/decidesk/');
		$url->method('getAbsoluteURL')->willReturnCallback(static fn (string $p): string => 'https://nc.example' . $p);
		$url->method('imagePath')->willReturn('/apps/decidesk/img/app-dark.svg');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1781352000);

		$service = new class($summary) extends DashboardWidgetService {

			/**
			 * @param array{pendingVotes:int, nextMeeting:array<string,mixed>|null} $summary Stub summary
			 */
			public function __construct(
				private array $summary,
			) {
			}

			/**
			 * @param string $userId User id
			 * @param int $now Now
			 *
			 * @return array{pendingVotes:int, nextMeeting:array<string,mixed>|null}
			 */
			public function getUserSummary(string $userId, int $now): array {
				return $this->summary;
			}//end getUserSummary()
		};

		return new DecideskDashboardWidget(
			l10n: $l10n,
			urlGenerator: $url,
			timeFactory: $time,
			widgetService: $service,
		);

	}//end makeWidget()

	/**
	 * The widget implements every expected OCP dashboard interface.
	 *
	 * @return void
	 */
	public function testImplementsExpectedInterfaces(): void {
		$widget = $this->makeWidget(['pendingVotes' => 0, 'nextMeeting' => null]);

		$this->assertInstanceOf(IWidget::class, $widget);
		$this->assertInstanceOf(IAPIWidgetV2::class, $widget);
		$this->assertInstanceOf(IIconWidget::class, $widget);
		$this->assertInstanceOf(IButtonWidget::class, $widget);

	}//end testImplementsExpectedInterfaces()

	/**
	 * Identity getters return the expected stable values.
	 *
	 * @return void
	 */
	public function testIdentity(): void {
		$widget = $this->makeWidget(['pendingVotes' => 0, 'nextMeeting' => null]);

		$this->assertSame('decidesk', $widget->getId());
		$this->assertSame('Decidesk', $widget->getTitle());
		$this->assertIsInt($widget->getOrder());
		$this->assertSame('icon-decidesk', $widget->getIconClass());
		$this->assertStringContainsString('app-dark.svg', $widget->getIconUrl());

	}//end testIdentity()

	/**
	 * getUrl and the widget button both deep-link into the Decidesk app.
	 *
	 * @return void
	 */
	public function testDeepLinkUrlAndButton(): void {
		$widget = $this->makeWidget(['pendingVotes' => 0, 'nextMeeting' => null]);

		$this->assertStringContainsString('/apps/decidesk/', (string)$widget->getUrl());

		$buttons = $widget->getWidgetButtons('alice');
		$this->assertCount(1, $buttons);
		$this->assertStringContainsString('/apps/decidesk/', $buttons[0]->getLink());
		$this->assertSame('Open Decidesk', $buttons[0]->getText());

	}//end testDeepLinkUrlAndButton()

	/**
	 * getItemsV2 surfaces the pending-votes count and the next meeting.
	 *
	 * @return void
	 */
	public function testItemsV2WithData(): void {
		$widget = $this->makeWidget(
			[
				'pendingVotes' => 3,
				'nextMeeting' => ['id' => 'm1', 'title' => 'Board meeting', 'scheduledDate' => '2026-06-15T10:00:00Z'],
			]
		);

		$items = $widget->getItemsV2('alice')->getItems();

		$this->assertCount(2, $items);
		$this->assertStringContainsString('3', $items[0]->getTitle());
		$this->assertSame('Board meeting', $items[1]->getTitle());
		$this->assertStringContainsString('/apps/decidesk/', $items[0]->getLink());

	}//end testItemsV2WithData()

	/**
	 * With no pending votes and no next meeting the items reflect the empty
	 * state and the WidgetItems carries an empty-content message.
	 *
	 * @return void
	 */
	public function testItemsV2EmptyState(): void {
		$widget = $this->makeWidget(['pendingVotes' => 0, 'nextMeeting' => null]);

		$widgetItems = $widget->getItemsV2('alice');
		$items = $widgetItems->getItems();

		$this->assertCount(2, $items);
		$this->assertStringContainsString('0', $items[0]->getTitle());
		$this->assertSame('No upcoming meetings', $items[1]->getTitle());
		$this->assertNotSame('', $widgetItems->getEmptyContentMessage());

	}//end testItemsV2EmptyState()
}//end class
