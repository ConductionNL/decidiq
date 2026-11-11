<?php

/**
 * Characterisation test for the stored-date parsing of three classes.
 *
 * AgendaInvitation::parse(), OriSerializer::parseDate() and
 * ParallelSessionListener::moment() each turn a stored date value into a
 * DateTimeImmutable, or null when it is not a non-empty parseable string. This
 * pins those outcomes for each of them.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Decidiq\Listener\ParallelSessionListener;
use OCA\Decidiq\Service\AgendaInvitation;
use OCA\Decidiq\Service\OriSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the date parsing of the three classes that carry it.
 */
class DateValueParsingTest extends TestCase {

	/**
	 * The classes and the name of their parsing method.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function parsers(): array {
		return [
			'agenda invitation' => [AgendaInvitation::class, 'parse'],
			'ori serializer' => [OriSerializer::class, 'parseDate'],
			'parallel session listener' => [ParallelSessionListener::class, 'moment'],
		];

	}//end parsers()

	/**
	 * Parseable strings parse; anything else is null.
	 *
	 * @param class-string $class  The class under test.
	 * @param string       $method Its parsing method.
	 *
	 * @return void
	 */
	#[DataProvider('parsers')]
	public function testParsing(string $class, string $method): void {
		$parsed = $this->parse(owner: [$class, $method], value: '2026-03-04T05:06:07+02:00');
		self::assertInstanceOf(expected: DateTimeImmutable::class, actual: $parsed);
		self::assertSame(expected: '2026-03-04T05:06:07+02:00', actual: $parsed->format(DateTimeInterface::ATOM));

		foreach (['', 'not a date', null, 20260304, false, ['2026-03-04']] as $value) {
			self::assertNull(actual: $this->parse(owner: [$class, $method], value: $value));
		}

	}//end testParsing()

	/**
	 * Call the private parsing method of a class.
	 *
	 * @param array{0: class-string, 1: string} $owner The class and its method.
	 * @param mixed                             $value The stored value.
	 *
	 * @return DateTimeImmutable|null The parsed date.
	 */
	private function parse(array $owner, mixed $value): ?DateTimeImmutable {
		$reflection = new ReflectionClass($owner[0]);

		return $reflection->getMethod($owner[1])->invoke($reflection->newInstanceWithoutConstructor(), $value);

	}//end parse()
}//end class
