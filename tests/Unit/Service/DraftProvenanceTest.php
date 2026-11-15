<?php

/**
 * Unit test for DraftProvenance.
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
 * @spec openspec/specs/meeting-transcription/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Decidiq\Service\DraftProvenance;
use PHPUnit\Framework\TestCase;

/**
 * The provenance stamped on a generated minutes draft.
 *
 * @covers \OCA\Decidiq\Service\DraftProvenance
 */
class DraftProvenanceTest extends TestCase {

	/**
	 * A draft generated now carries the provider and an ISO-8601 time of now.
	 *
	 * @return void
	 */
	public function testNowStampsTheProviderAndTheCurrentAtomTime(): void {
		$before = time();
		$stamp  = DraftProvenance::now(providerId: 'llm-1')->toArray();
		$after  = time();

		self::assertSame(['aiGenerated', 'providerId', 'generatedAt'], array_keys($stamp));
		self::assertTrue($stamp['aiGenerated']);
		self::assertSame('llm-1', $stamp['providerId']);
		$parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $stamp['generatedAt']);
		self::assertNotFalse($parsed);
		self::assertSame($stamp['generatedAt'], $parsed->format(DateTimeInterface::ATOM));
		self::assertGreaterThanOrEqual($before, $parsed->getTimestamp());
		self::assertLessThanOrEqual($after, $parsed->getTimestamp());
	}//end testNowStampsTheProviderAndTheCurrentAtomTime()
}//end class
