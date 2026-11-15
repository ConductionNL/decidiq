<?php

/**
 * Unit tests for GovernanceFilter.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Activity
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

namespace OCA\Decidiq\Tests\Unit\Activity;

use OCA\Decidiq\Activity\GovernanceFilter;
use OCA\Decidiq\Activity\GovernanceSetting;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the activity stream filter for Decidiq governance events.
 *
 * @covers \OCA\Decidiq\Activity\GovernanceFilter
 */
class GovernanceFilterTest extends TestCase {
	/**
	 * Localisation mock.
	 *
	 * @var IL10N&MockObject
	 */
	private IL10N&MockObject $l10n;

	/**
	 * URL generator mock.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator&MockObject $urlGenerator;

	/**
	 * Subject under test.
	 *
	 * @var GovernanceFilter
	 */
	private GovernanceFilter $filter;

	/**
	 * Build the filter with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->l10n         = $this->createMock(IL10N::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->filter       = new GovernanceFilter(l10n: $this->l10n, urlGenerator: $this->urlGenerator);
	}//end setUp()

	/**
	 * The identifier is the app id.
	 *
	 * @return void
	 */
	public function testIdentifierIsTheAppId(): void {
		$this->assertSame('decidiq', $this->filter->getIdentifier());
	}//end testIdentifierIsTheAppId()

	/**
	 * The name is the app name passed through IL10N.
	 *
	 * @return void
	 */
	public function testNameIsTranslated(): void {
		$this->l10n->expects($this->once())->method('t')->with('Decidiq')->willReturn('Decidiq NL');

		$this->assertSame('Decidiq NL', $this->filter->getName());
	}//end testNameIsTranslated()

	/**
	 * The priority places the filter in the sidebar ordering.
	 *
	 * @return void
	 */
	public function testPriorityIsSixty(): void {
		$this->assertSame(60, $this->filter->getPriority());
	}//end testPriorityIsSixty()

	/**
	 * The icon is the absolute URL of the app's dark SVG.
	 *
	 * @return void
	 */
	public function testIconIsAbsoluteUrlOfDarkAppIcon(): void {
		$this->urlGenerator->expects($this->once())
			->method('imagePath')
			->with('decidiq', 'app-dark.svg')
			->willReturn('/apps/decidiq/img/app-dark.svg');
		$this->urlGenerator->expects($this->once())
			->method('getAbsoluteURL')
			->with('/apps/decidiq/img/app-dark.svg')
			->willReturn('https://cloud.example/apps/decidiq/img/app-dark.svg');

		$this->assertSame('https://cloud.example/apps/decidiq/img/app-dark.svg', $this->filter->getIcon());
	}//end testIconIsAbsoluteUrlOfDarkAppIcon()

	/**
	 * Only the governance type survives, re-indexed from zero.
	 *
	 * @return void
	 */
	public function testFilterTypesKeepsOnlyGovernanceReindexed(): void {
		$result = $this->filter->filterTypes(['files', 'calendar', GovernanceSetting::TYPE_GOVERNANCE, 'comments']);

		$this->assertSame([GovernanceSetting::TYPE_GOVERNANCE], $result);
	}//end testFilterTypesKeepsOnlyGovernanceReindexed()

	/**
	 * Without the governance type the filter yields nothing.
	 *
	 * @return void
	 */
	public function testFilterTypesWithoutGovernanceIsEmpty(): void {
		$this->assertSame([], $this->filter->filterTypes(['files', 'comments']));
		$this->assertSame([], $this->filter->filterTypes([]));
	}//end testFilterTypesWithoutGovernanceIsEmpty()

	/**
	 * Only Decidiq's own app is allowed through.
	 *
	 * @return void
	 */
	public function testAllowedAppsIsOnlyDecidiq(): void {
		$this->assertSame(['decidiq'], $this->filter->allowedApps());
	}//end testAllowedAppsIsOnlyDecidiq()
}//end class
