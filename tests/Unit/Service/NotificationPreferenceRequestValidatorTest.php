<?php

/**
 * Characterisation tests for NotificationPreferenceRequestValidator.
 *
 * Pins the error messages and the collected changes of the reminder-time,
 * delegation and communication fields, including which check wins when
 * several fail.
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
 *
 * @spec openspec/specs/user-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\NotificationPreferenceRequestValidator;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation tests for the preference payload validator.
 */
class NotificationPreferenceRequestValidatorTest extends TestCase {

	private const REMINDER_ERROR = 'Invalid reminderTimes. Expected a non-empty array of: 1h, 4h, 24h, 48h, 1w.';
	private const REMINDER_ENTRY_ERROR = 'Invalid reminderTimes entry. Expected one of: 1h, 4h, 24h, 48h, 1w.';
	private const FROM_ERROR = 'Invalid delegationFrom. Expected an ISO date (YYYY-MM-DD).';
	private const UNTIL_ERROR = 'Invalid delegationUntil. Expected an ISO date (YYYY-MM-DD).';
	private const ORDER_ERROR = 'Invalid delegation period: delegationUntil must not be before delegationFrom.';
	private const DELEGATE_ERROR = 'Invalid delegate. Expected a Nextcloud user id.';
	private const EXPIRY_ERROR = 'A delegation requires an expiry date (delegationUntil) — delegations expire automatically.';

	/**
	 * Run collect() over a request carrying $params.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array{0: string|null, 1: array<string, mixed>} The error and the collected changes.
	 */
	private function collect(array $params): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key) => ($params[$key] ?? null)
		);
		$changes = [];
		$error = (new NotificationPreferenceRequestValidator(request: $request))->collect($changes);

		return [$error, $changes];
	}//end collect()

	/**
	 * An empty payload is valid and changes nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyPayloadChangesNothing(): void {
		$this->assertSame([null, []], $this->collect(params: []));
	}//end testAnEmptyPayloadChangesNothing()

	/**
	 * Reminder times must be a non-empty list of known tokens; duplicates collapse.
	 *
	 * @return void
	 */
	public function testReminderTimes(): void {
		$this->assertSame([self::REMINDER_ERROR, []], $this->collect(params: ['reminderTimes' => '1h']));
		$this->assertSame([self::REMINDER_ERROR, []], $this->collect(params: ['reminderTimes' => []]));
		$this->assertSame([self::REMINDER_ENTRY_ERROR, []], $this->collect(params: ['reminderTimes' => ['1h', 'soon']]));
		$this->assertSame([self::REMINDER_ENTRY_ERROR, []], $this->collect(params: ['reminderTimes' => [1]]));
		$this->assertSame([null, ['reminderTimes' => ['4h', '1h']]], $this->collect(params: ['reminderTimes' => ['4h', '1h', '4h']]));
		$this->assertSame([null, ['reminderTimes' => ['1w', '24h']]], $this->collect(params: ['reminderTimes' => ['a' => '1w', 'b' => '1w', 'c' => '24h']]));
	}//end testReminderTimes()

	/**
	 * An empty delegate clears the whole delegation before any period check.
	 *
	 * @return void
	 */
	public function testAnEmptyDelegateClearsTheDelegation(): void {
		$cleared = ['delegate' => null, 'delegationFrom' => null, 'delegationUntil' => null];
		$this->assertSame([null, $cleared], $this->collect(params: ['delegate' => '']));
		$this->assertSame([null, $cleared], $this->collect(params: ['delegate' => '', 'delegationFrom' => 'garbage']));
	}//end testAnEmptyDelegateClearsTheDelegation()

	/**
	 * Period bounds must be ISO dates in order; the period is checked before the delegate.
	 *
	 * @return void
	 */
	public function testDelegationPeriodRefusals(): void {
		$this->assertSame([self::FROM_ERROR, []], $this->collect(params: ['delegationFrom' => '1-7-2026']));
		$this->assertSame([self::UNTIL_ERROR, []], $this->collect(params: ['delegationUntil' => '2026-7-14']));
		$this->assertSame([self::FROM_ERROR, []], $this->collect(params: ['delegate' => 'bad!', 'delegationFrom' => 'x', 'delegationUntil' => 'y']));
		$this->assertSame([self::ORDER_ERROR, []], $this->collect(params: ['delegate' => 'bob', 'delegationFrom' => '2026-07-14', 'delegationUntil' => '2026-07-01']));
	}//end testDelegationPeriodRefusals()

	/**
	 * A delegate must look like a user id and needs an expiry date.
	 *
	 * @return void
	 */
	public function testDelegateRefusals(): void {
		$this->assertSame([self::DELEGATE_ERROR, []], $this->collect(params: ['delegate' => 'bad!', 'delegationUntil' => '2026-07-14']));
		$this->assertSame([self::DELEGATE_ERROR, []], $this->collect(params: ['delegate' => 123, 'delegationUntil' => '2026-07-14']));
		$this->assertSame([self::DELEGATE_ERROR, []], $this->collect(params: ['delegate' => str_repeat('a', 65), 'delegationUntil' => '2026-07-14']));
		$this->assertSame([self::EXPIRY_ERROR, []], $this->collect(params: ['delegate' => 'bob']));
		$this->assertSame([self::EXPIRY_ERROR, []], $this->collect(params: ['delegate' => 'bob', 'delegationUntil' => '']));
	}//end testDelegateRefusals()

	/**
	 * Accepted delegations and bare bounds are collected, empty bounds as null.
	 *
	 * @return void
	 */
	public function testAcceptedDelegationsAreCollected(): void {
		$this->assertSame(
			[null, ['delegate' => 'bob.smith@x', 'delegationFrom' => '2026-07-01', 'delegationUntil' => '2026-07-14']],
			$this->collect(params: ['delegate' => 'bob.smith@x', 'delegationFrom' => '2026-07-01', 'delegationUntil' => '2026-07-14'])
		);
		$this->assertSame(
			[null, ['delegate' => 'bob', 'delegationUntil' => '2026-07-14']],
			$this->collect(params: ['delegate' => 'bob', 'delegationUntil' => '2026-07-14'])
		);
		$this->assertSame([null, ['delegationFrom' => '2026-07-01']], $this->collect(params: ['delegationFrom' => '2026-07-01']));
		$this->assertSame([null, ['delegationFrom' => null, 'delegationUntil' => null]], $this->collect(params: ['delegationFrom' => '', 'delegationUntil' => '']));
		$this->assertSame(
			[null, ['delegationFrom' => '2026-07-01', 'delegationUntil' => '2026-07-01']],
			$this->collect(params: ['delegationFrom' => '2026-07-01', 'delegationUntil' => '2026-07-01'])
		);
	}//end testAcceptedDelegationsAreCollected()

	/**
	 * Communication overrides: empty clears, invalid refuses, the first failure wins.
	 *
	 * @return void
	 */
	public function testCommunicationFields(): void {
		$this->assertSame(
			[null, ['governanceEmail' => null, 'urgentPhone' => '+31 (0)20-1234567', 'communicationLanguage' => 'nl']],
			$this->collect(params: ['governanceEmail' => '', 'urgentPhone' => '+31 (0)20-1234567', 'communicationLanguage' => 'nl'])
		);
		$this->assertSame(
			['Invalid governanceEmail. Expected a valid e-mail address.', []],
			$this->collect(params: ['governanceEmail' => 'nope', 'urgentPhone' => 'x'])
		);
		$this->assertSame(['Invalid governanceEmail. Expected a valid e-mail address.', []], $this->collect(params: ['governanceEmail' => 42]));
		$this->assertSame(
			['Invalid urgentPhone. Expected a phone number (digits, +, -, (), spaces).', ['governanceEmail' => 'a@example.com']],
			$this->collect(params: ['governanceEmail' => 'a@example.com', 'urgentPhone' => 'call me'])
		);
		$this->assertSame(
			['Invalid communicationLanguage. Expected one of: nl, en, de, fr, es, it.', ['urgentPhone' => null]],
			$this->collect(params: ['urgentPhone' => '', 'communicationLanguage' => 'xx'])
		);
	}//end testCommunicationFields()

	/**
	 * A reminder-time error stops the delegation from being collected.
	 *
	 * @return void
	 */
	public function testTheFirstErrorStopsCollection(): void {
		$this->assertSame(
			[self::REMINDER_ERROR, ['meetingCreated' => true]],
			$this->collect(params: ['meetingCreated' => 1, 'reminderTimes' => 'x', 'delegate' => ''])
		);
	}//end testTheFirstErrorStopsCollection()
}//end class
