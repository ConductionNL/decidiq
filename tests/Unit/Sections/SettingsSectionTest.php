<?php

/**
 * Unit tests for SettingsSection.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Sections
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Sections;

use OCA\Decidiq\Sections\SettingsSection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the admin settings section: identifier, translated name,
 * ordering priority and the icon resolved through the URL generator.
 *
 * @covers \OCA\Decidiq\Sections\SettingsSection
 */
class SettingsSectionTest extends TestCase {
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
	 * @var SettingsSection
	 */
	private SettingsSection $section;

	/**
	 * Build the section with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->l10n         = $this->createMock(IL10N::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->section      = new SettingsSection(l: $this->l10n, urlGenerator: $this->urlGenerator);
	}//end setUp()

	/**
	 * The section is an icon section so Nextcloud renders it with its icon.
	 *
	 * @return void
	 */
	public function testImplementsIconSection(): void {
		$this->assertInstanceOf(IIconSection::class, $this->section);
	}//end testImplementsIconSection()

	/**
	 * The identifier is the app id, which the admin settings class references.
	 *
	 * @return void
	 */
	public function testIdIsTheAppId(): void {
		$this->assertSame('decidiq', $this->section->getID());
	}//end testIdIsTheAppId()

	/**
	 * The name is the translated app name, passed through IL10N exactly once.
	 *
	 * @return void
	 */
	public function testNameIsTranslatedThroughL10n(): void {
		$this->l10n->expects($this->once())
			->method('t')
			->with('Decidiq')
			->willReturn('Decidiq (vertaald)');

		$this->assertSame('Decidiq (vertaald)', $this->section->getName());
	}//end testNameIsTranslatedThroughL10n()

	/**
	 * The priority places the section at a fixed slot in the settings menu.
	 *
	 * @return void
	 */
	public function testPriorityIsSeventyFive(): void {
		$this->assertSame(75, $this->section->getPriority());
	}//end testPriorityIsSeventyFive()

	/**
	 * The icon is the app's dark SVG, resolved through the URL generator.
	 *
	 * @return void
	 */
	public function testIconIsResolvedThroughUrlGenerator(): void {
		$this->urlGenerator->expects($this->once())
			->method('imagePath')
			->with('decidiq', 'app-dark.svg')
			->willReturn('/custom_apps/decidiq/img/app-dark.svg');

		$this->assertSame('/custom_apps/decidiq/img/app-dark.svg', $this->section->getIcon());
	}//end testIconIsResolvedThroughUrlGenerator()

	/**
	 * The section does not translate or resolve anything until asked.
	 *
	 * @return void
	 */
	public function testConstructionHasNoSideEffects(): void {
		$l10n = $this->createMock(IL10N::class);
		$url  = $this->createMock(IURLGenerator::class);
		$l10n->expects($this->never())->method('t');
		$url->expects($this->never())->method('imagePath');

		$section = new SettingsSection(l: $l10n, urlGenerator: $url);

		$this->assertSame(75, $section->getPriority());
	}//end testConstructionHasNoSideEffects()
}//end class
