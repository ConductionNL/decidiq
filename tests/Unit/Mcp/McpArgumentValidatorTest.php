<?php

/**
 * Unit tests for McpArgumentValidator.
 *
 * The validator is the only thing between an LLM-supplied argument and a
 * register query, so each refusal and its message are pinned.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Mcp
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

namespace OCA\Decidiq\Tests\Unit\Mcp;

use OCA\Decidiq\Mcp\McpArgumentValidator;
use OCA\Decidiq\Mcp\McpSourceFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for McpArgumentValidator.
 *
 * @covers \OCA\Decidiq\Mcp\McpArgumentValidator
 * @uses \OCA\Decidiq\Mcp\McpSourceFormatter
 */
class McpArgumentValidatorTest extends TestCase {

	/**
	 * A syntactically valid meeting UUID.
	 *
	 * @var string
	 */
	private const UUID = '0b6f8a52-3c1d-4e2f-9a7b-1c2d3e4f5a6b';

	/**
	 * The validator under test.
	 *
	 * @var McpArgumentValidator
	 */
	private McpArgumentValidator $validator;

	/**
	 * Set up the validator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->validator = new McpArgumentValidator(formatter: new McpSourceFormatter());
	}

	/**
	 * The error envelope for invalid arguments.
	 *
	 * @param string $message The message.
	 *
	 * @return array<string, mixed> The envelope.
	 */
	private function invalid(string $message): array {
		return ['isError' => true, 'error' => 'invalid_arguments', 'message' => $message];
	}

	/**
	 * Limits outside 1..20 are refused for the meeting list.
	 *
	 * @return void
	 */
	public function testTheMeetingListLimitIsBounded(): void {
		$this->assertSame(
			$this->invalid('Invalid limit 0. Must be between 1 and 20.'),
			$this->validator->validateLimitAndStatus(limit: 0, statusFilter: 'any')
		);
		$this->assertSame(
			$this->invalid('Invalid limit 21. Must be between 1 and 20.'),
			$this->validator->validateLimitAndStatus(limit: 21, statusFilter: 'any')
		);
		$this->assertNull($this->validator->validateLimitAndStatus(limit: 20, statusFilter: 'closed'));
		$this->assertNull($this->validator->validateLimitAndStatus(limit: 1, statusFilter: 'in-progress'));
	}

	/**
	 * A status outside the four known ones is refused, naming the allowed set.
	 *
	 * @return void
	 */
	public function testAnUnknownStatusIsRefused(): void {
		$this->assertSame(
			$this->invalid("Invalid statusFilter 'open'. Allowed: any, scheduled, in-progress, closed."),
			$this->validator->validateLimitAndStatus(limit: 5, statusFilter: 'open')
		);
	}

	/**
	 * A missing or empty title is refused.
	 *
	 * @return void
	 */
	public function testAMissingTitleIsRefused(): void {
		$expected = $this->invalid('Required argument title is missing.');

		$this->assertSame($expected, $this->validator->validateActionItemArgs(args: ['meetingUuid' => self::UUID]));
		$this->assertSame(
			$expected,
			$this->validator->validateActionItemArgs(args: ['meetingUuid' => self::UUID, 'title' => ''])
		);
	}

	/**
	 * A title must be 3..200 characters, counted as characters, not bytes.
	 *
	 * @return void
	 */
	public function testTheTitleLengthIsBounded(): void {
		$this->assertSame(
			$this->invalid('Title must be between 3 and 200 characters (got 2).'),
			$this->validator->validateActionItemArgs(args: ['meetingUuid' => self::UUID, 'title' => 'éé'])
		);
		$this->assertSame(
			$this->invalid('Title must be between 3 and 200 characters (got 201).'),
			$this->validator->validateActionItemArgs(args: ['meetingUuid' => self::UUID, 'title' => str_repeat('a', 201)])
		);
		$this->assertNull(
			$this->validator->validateActionItemArgs(args: ['meetingUuid' => self::UUID, 'title' => 'ééé'])
		);
	}

	/**
	 * A due date that is not an ISO date is refused; an absent one is fine.
	 *
	 * @return void
	 */
	public function testTheDueDateMustBeAnIsoDate(): void {
		$args = ['meetingUuid' => self::UUID, 'title' => 'Notulen rondsturen'];

		$this->assertSame(
			$this->invalid("Invalid dueDate '12-03-2026'. Expected ISO 8601 date (YYYY-MM-DD)."),
			$this->validator->validateActionItemArgs(args: $args + ['dueDate' => '12-03-2026'])
		);
		$this->assertNull($this->validator->validateActionItemArgs(args: $args + ['dueDate' => '2026-03-12']));
		$this->assertNull($this->validator->validateActionItemArgs(args: $args + ['dueDate' => '']));
	}

	/**
	 * The meeting UUID is checked before the title.
	 *
	 * @return void
	 */
	public function testTheMeetingIsCheckedFirst(): void {
		$this->assertSame(
			$this->invalid("Invalid UUID format for meetingUuid: 'nope'."),
			$this->validator->validateActionItemArgs(args: ['meetingUuid' => 'nope'])
		);
	}
}
