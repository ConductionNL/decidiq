<?php

/**
 * Unit tests for EntityPayloadReader.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use JsonSerializable;
use OCA\Decidiq\Listener\EntityPayloadReader;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for reading an event entity's payload.
 *
 * @covers \OCA\Decidiq\Listener\EntityPayloadReader
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class EntityPayloadReaderTest extends TestCase {
	public function testPrefersTheObjectPayload(): void {
		$entity = new class implements JsonSerializable {
			public function getObject(): array {
				return ['title' => 'object'];
			}

			public function jsonSerialize(): array {
				return ['title' => 'json'];
			}
		};

		$this->assertSame(['title' => 'object'], (new EntityPayloadReader())->payloadOf($entity));
	}

	public function testFallsBackToJsonWhenTheObjectIsEmpty(): void {
		$entity = new class implements JsonSerializable {
			public function getObject(): ?array {
				return null;
			}

			public function jsonSerialize(): array {
				return ['title' => 'json'];
			}
		};

		$this->assertSame(['title' => 'json'], (new EntityPayloadReader())->payloadOf($entity));
	}

	public function testAnObjectWithoutEitherMethodHasNoPayload(): void {
		$this->assertSame([], (new EntityPayloadReader())->payloadOf(new stdClass()));
	}

	public function testAnEmptyObjectWithoutJsonHasNoPayload(): void {
		$entity = new class {
			public function getObject(): array {
				return [];
			}
		};

		$this->assertSame([], (new EntityPayloadReader())->payloadOf($entity));
	}
}
