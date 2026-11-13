<?php

/**
 * The text and title a delegated decision gets when the requesting app sends none.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\DelegatedDecisionDefaults;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Service\DelegatedDecisionDefaults
 */
class DelegatedDecisionDefaultsTest extends TestCase {

	/**
	 * Defaults over an IL10N that substitutes its parameters like the real one.
	 *
	 * @return DelegatedDecisionDefaults The defaults.
	 */
	private function defaults(): DelegatedDecisionDefaults {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters=[]): string => vsprintf($text, $parameters)
		);

		return new DelegatedDecisionDefaults($l10n);
	}//end defaults()

	/**
	 * A sent text is kept, trimmed.
	 *
	 * @return void
	 */
	public function testASentTextIsKept(): void {
		self::assertSame(
			'Grant the permit',
			$this->defaults()->text(['text' => '  Grant the permit ', 'context' => ['question' => 'ignored']], [])
		);
	}//end testASentTextIsKept()

	/**
	 * Without text the first non-blank context field is used, in the fixed order.
	 *
	 * @return void
	 */
	public function testTheFirstNonBlankContextFieldStandsIn(): void {
		$defaults = $this->defaults();

		self::assertSame(
			'Why now',
			$defaults->text(['context' => ['description' => 'last', 'reasoning' => ' Why now ', 'question' => '  ']], [])
		);
		self::assertSame(
			'Shall we?',
			$defaults->text(['text' => ' ', 'context' => ['question' => 'Shall we?', 'reasoning' => 'second']], [])
		);
		self::assertSame(
			'desc',
			$defaults->text(['context' => ['question' => ['not', 'text'], 'description' => 'desc']], [])
		);
	}//end testTheFirstNonBlankContextFieldStandsIn()

	/**
	 * Without text or context the source app and subject are named.
	 *
	 * @return void
	 */
	public function testWithoutTextTheSourceAndSubjectAreNamed(): void {
		$defaults = $this->defaults();

		self::assertSame(
			'Decision requested by dossiq for Permit 12.',
			$defaults->text(['context' => 'not an array'], ['sourceApp' => 'dossiq', 'subjectLabel' => 'Permit 12'])
		);
		self::assertSame(
			'Decision requested by dossiq for case c-9.',
			$defaults->text([], ['sourceApp' => 'dossiq', 'subjectSchema' => ' case ', 'subjectId' => 'c-9'])
		);
		self::assertSame(
			'Decision requested by another app for ZK-2026-1.',
			$defaults->text([], ['externalReference' => 'ZK-2026-1'])
		);
		self::assertSame('Decision requested by another app.', $defaults->text([], []));
	}//end testWithoutTextTheSourceAndSubjectAreNamed()

	/**
	 * A sent title is kept; else the subject label; else the source app is named.
	 *
	 * @return void
	 */
	public function testTheTitleFallsBackToTheSubjectThenTheSource(): void {
		$defaults = $this->defaults();

		self::assertSame('Permit', $defaults->title(['title' => ' Permit '], ['subjectLabel' => 'other']));
		self::assertSame('Permit 12', $defaults->title(['title' => ''], ['subjectLabel' => ' Permit 12 ']));
		self::assertSame('Decision requested by pipelinq', $defaults->title([], ['sourceApp' => 'pipelinq']));
		self::assertSame('Decision requested by another app', $defaults->title([], ['sourceApp' => '  ']));
	}//end testTheTitleFallsBackToTheSubjectThenTheSource()
}//end class
