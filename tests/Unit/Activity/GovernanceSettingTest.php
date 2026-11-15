<?php

/**
 * Unit tests for GovernanceSetting.
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

use OCA\Decidiq\Activity\GovernanceSetting;
use OCP\Activity\ActivitySettings;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the per-user activity setting for governance events.
 *
 * @covers \OCA\Decidiq\Activity\GovernanceSetting
 */
class GovernanceSettingTest extends TestCase {
	/**
	 * Localisation mock.
	 *
	 * @var IL10N&MockObject
	 */
	private IL10N&MockObject $l10n;

	/**
	 * Subject under test.
	 *
	 * @var GovernanceSetting
	 */
	private GovernanceSetting $setting;

	/**
	 * Build the setting with a mocked IL10N that marks translated strings.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text): string => '[nl] ' . $text
		);
		$this->setting = new GovernanceSetting(l10n: $this->l10n);
	}//end setUp()

	/**
	 * The setting is an ActivitySettings so Nextcloud lists it.
	 *
	 * @return void
	 */
	public function testIsActivitySettings(): void {
		$this->assertInstanceOf(ActivitySettings::class, $this->setting);
	}//end testIsActivitySettings()

	/**
	 * The identifier is the stable governance type string.
	 *
	 * @return void
	 */
	public function testIdentifierIsGovernanceType(): void {
		$this->assertSame('decidesk_governance', GovernanceSetting::TYPE_GOVERNANCE);
		$this->assertSame('decidesk_governance', $this->setting->getIdentifier());
	}//end testIdentifierIsGovernanceType()

	/**
	 * The name is the translated description of a governance event.
	 *
	 * @return void
	 */
	public function testNameIsTranslated(): void {
		$this->assertSame(
			'[nl] A governance event (decision, meeting, vote or resolution) happened in Decidiq',
			$this->setting->getName()
		);
	}//end testNameIsTranslated()

	/**
	 * The setting lives in the shared "other" group with a translated label.
	 *
	 * @return void
	 */
	public function testGroupIsOtherActivities(): void {
		$this->assertSame('other', $this->setting->getGroupIdentifier());
		$this->assertSame('[nl] Other activities', $this->setting->getGroupName());
	}//end testGroupIsOtherActivities()

	/**
	 * The priority places the setting in the list.
	 *
	 * @return void
	 */
	public function testPriorityIsSixty(): void {
		$this->assertSame(60, $this->setting->getPriority());
	}//end testPriorityIsSixty()

	/**
	 * Notifications are on by default for governance events.
	 *
	 * @return void
	 */
	public function testNotificationIsEnabledByDefault(): void {
		$this->assertTrue($this->setting->isDefaultEnabledNotification());
	}//end testNotificationIsEnabledByDefault()
}//end class
