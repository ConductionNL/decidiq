<?php

/**
 * Unit tests for DateValueParser.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\DateValueParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for lenient parsing of stored date values.
 *
 * @covers \OCA\Decidiq\Service\DateValueParser
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
 */
class DateValueParserTest extends TestCase {
	public function testParsesAnIsoDate(): void {
		$date = (new DateValueParser())->parse('2026-03-01T10:00:00+01:00');

		$this->assertNotNull($date);
		$this->assertSame('2026-03-01T10:00:00+01:00', $date->format(DATE_ATOM));
	}

	public function testInvalidOrMissingValuesAreNull(): void {
		$parser = new DateValueParser();

		$this->assertNull($parser->parse('not a date'));
		$this->assertNull($parser->parse(''));
		$this->assertNull($parser->parse(null));
		$this->assertNull($parser->parse(1700000000));
	}
}
