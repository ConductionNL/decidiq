<?php

/**
 * The register carries a body's term reminder setting and declares a reminder
 * before a membership or a position hold ends.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Register test for bodies-term-of-office-and-step-down task 3.
 *
 * Runs over the register exactly as it is imported, base file and every
 * register.d fragment merged.
 *
 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-004-the-secretary-is-reminded-before-a-term-ends
 */
class TermOfOfficeRemindersRegisterTest extends TestCase {

	/**
	 * The reminder each schema declares.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function reminderProvider(): array {
		return [
			'membership'    => ['membership', 'membershipTermEndsSoon'],
			'position hold' => ['position-hold', 'positionTermEndsSoon'],
		];
	}//end reminderProvider()

	/**
	 * A body carries an optional number of reminder days, 90 by default.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-004-the-secretary-is-reminded-before-a-term-ends
	 */
	public function testABodyCarriesItsReminderDays(): void {
		$property = (MergedRegisterSchema::forSlug(slug: 'governance-body')['properties']['termReminderDays'] ?? null);

		self::assertIsArray($property);
		self::assertSame('integer', $property['type']);
		self::assertSame(90, $property['default']);
		self::assertSame(1, $property['minimum']);
		self::assertTrue($property['nullable']);
		self::assertSame([], MergedRegisterSchema::violations(slug: 'governance-body', payload: ['name' => 'Gemeenteraad', 'bodyType' => 'legislative', 'domain' => 'municipality', 'termReminderDays' => 60]));

	}//end testABodyCarriesItsReminderDays()

	/**
	 * Each reminder is a daily scheduled sweep for an end date within 90 days.
	 *
	 * @param string $slug The schema slug.
	 * @param string $key  The notification key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-004-the-secretary-is-reminded-before-a-term-ends
	 */
	#[DataProvider('reminderProvider')]
	public function testTheReminderFiresNinetyDaysBeforeTheEndDate(string $slug, string $key): void {
		$schema   = MergedRegisterSchema::forSlug(slug: $slug);
		$reminder = ($schema['x-openregister-notifications'][$key] ?? null);

		self::assertIsArray($reminder, $slug . ' declares ' . $key);
		self::assertTrue($reminder['enabled']);
		self::assertSame('scheduled', $reminder['trigger']['type']);
		self::assertSame(86400, $reminder['trigger']['intervalSec']);
		self::assertSame(['endDate' => ['operator' => 'withinNext', 'value' => 'P90D']], $reminder['trigger']['filter']);

	}//end testTheReminderFiresNinetyDaysBeforeTheEndDate()

	/**
	 * The filter and the message name properties the schema has, and the end date is a date.
	 *
	 * @param string $slug The schema slug.
	 * @param string $key  The notification key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-004-the-secretary-is-reminded-before-a-term-ends
	 */
	#[DataProvider('reminderProvider')]
	public function testTheReminderNamesExistingProperties(string $slug, string $key): void {
		$schema     = MergedRegisterSchema::forSlug(slug: $slug);
		$properties = $schema['properties'];
		$reminder   = $schema['x-openregister-notifications'][$key];

		foreach (array_keys($reminder['trigger']['filter']) as $field) {
			self::assertArrayHasKey($field, $properties, $slug . ' filters on ' . $field);
			self::assertContains($properties[$field]['format'] ?? null, ['date', 'date-time'], $field . ' is a date');
		}

		foreach (['nl', 'en'] as $language) {
			self::assertNotSame('', ($reminder['subject'][$language] ?? ''), $key . ' has a ' . $language . ' subject');
			preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $reminder['subject'][$language], $matches);
			self::assertNotSame([], $matches[1], $key . ' names the end date');
			foreach ($matches[1] as $placeholder) {
				self::assertArrayHasKey($placeholder, $properties, $key . ' renders ' . $placeholder);
			}
		}

	}//end testTheReminderNamesExistingProperties()

	/**
	 * The reminder goes to the secretariat group, and only to it.
	 *
	 * @param string $slug The schema slug.
	 * @param string $key  The notification key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-004-the-secretary-is-reminded-before-a-term-ends
	 */
	#[DataProvider('reminderProvider')]
	public function testTheReminderGoesToTheSecretariat(string $slug, string $key): void {
		$reminder = MergedRegisterSchema::forSlug(slug: $slug)['x-openregister-notifications'][$key];

		self::assertSame([['kind' => 'groups', 'groups' => ['decidiq-secretariat']]], $reminder['recipients']);
		self::assertSame(['nc-notification'], $reminder['channels']);

	}//end testTheReminderGoesToTheSecretariat()

	/**
	 * A membership may end in the future, and no schema stores the schedule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bodies-term-of-office-and-step-down/specs/governance-bodies/spec.md#requirement-req-tos-002-the-step-down-schedule-lists-every-term-that-ends-soonest-first
	 */
	public function testTheScheduleIsNotStored(): void {
		$term = ['role' => 'member', 'startDate' => '2026-03-30T00:00:00+02:00', 'endDate' => '2030-03-29T00:00:00+02:00'];
		self::assertSame([], MergedRegisterSchema::violations(slug: 'membership', payload: $term));

		foreach (['step-down-schedule', 'term-of-office'] as $slug) {
			self::assertSame([], MergedRegisterSchema::forSlug(slug: $slug), $slug . ' is never a stored schema');
		}

		// The retired projection keeps its slug for old rows only (retire-the-unbuilt-rooster).
		$retired = MergedRegisterSchema::forSlug(slug: 'rooster-van-aftreden');
		self::assertStringStartsWith('SUPERSEDED', (string)($retired['description'] ?? ''));

	}//end testTheScheduleIsNotStored()
}//end class
