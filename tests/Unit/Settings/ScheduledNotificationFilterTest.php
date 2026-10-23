<?php

/**
 * Every declared notification must be able to match and render.
 *
 * OpenRegister's scheduled notification job keeps only the objects whose
 * data matches `trigger.filter`. After the Dutch-to-English value rename
 * (RenameDutchDecidiqValues) the filters still named the Dutch values, so
 * the long-term agenda, planning cycle, ancillary position and commitment
 * reminders matched nothing or only part of what they should (issue #1392).
 * These tests run each reminder's filter against the current enum of the
 * property it filters on, one case per reminder, over the register exactly
 * as it is imported (SettingsService::shippedRegisterDescriptor()).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Repair\RenameDutchVocabularyColumns;
use OCA\Decidiq\Service\SettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * Filter values and template placeholders against the merged register.
 */
class ScheduledNotificationFilterTest extends TestCase {

	/**
	 * One case per scheduled reminder in the merged register.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function scheduledReminderProvider(): array {
		$cases = [];
		foreach (self::schemas() as $schemaName => $schema) {
			foreach (($schema['x-openregister-notifications'] ?? []) as $key => $notification) {
				if (is_array($notification) === false || ($notification['trigger']['type'] ?? '') !== 'scheduled') {
					continue;
				}

				$cases["{$schemaName}.{$key}"] = [$schemaName, (string)$key];
			}
		}

		return $cases;
	}//end scheduledReminderProvider()

	/**
	 * One case per declared notification in the merged register.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function notificationProvider(): array {
		$cases = [];
		foreach (self::schemas() as $schemaName => $schema) {
			foreach (($schema['x-openregister-notifications'] ?? []) as $key => $notification) {
				if (is_array($notification) === true) {
					$cases["{$schemaName}.{$key}"] = [$schemaName, (string)$key];
				}
			}
		}

		return $cases;
	}//end notificationProvider()

	/**
	 * Every value a reminder filters on is a value the property can hold.
	 *
	 * @param string $schemaName Schema name.
	 * @param string $key        Notification key.
	 *
	 * @return void
	 */
	#[DataProvider('scheduledReminderProvider')]
	public function testReminderFilterMatchesTheCurrentEnum(string $schemaName, string $key): void {
		$schema = self::schemas()[$schemaName];
		$filter = ($schema['x-openregister-notifications'][$key]['trigger']['filter'] ?? []);
		self::assertIsArray($filter);

		foreach ($filter as $property => $expected) {
			$enum = ($schema['properties'][$property]['enum'] ?? null);
			if (is_array($enum) === false) {
				continue;
			}

			foreach (self::filterValues(expected: $expected) as $value) {
				self::assertContains(
					needle: $value,
					haystack: $enum,
					message: "{$schemaName}.{$key} filters {$property} on '{$value}', which is not in the enum ["
						. implode(', ', $enum) . '], so the reminder can never match it'
				);
			}
		}
	}//end testReminderFilterMatchesTheCurrentEnum()

	/**
	 * No notification text still names a property the vocabulary rename moved.
	 *
	 * RenameDutchVocabularyColumns renamed the stored properties (for example
	 * `onderwerp` to `subject`, `organisatie` to `organisation`); a template
	 * still naming the old one renders an empty value.
	 *
	 * @param string $schemaName Schema name.
	 * @param string $key        Notification key.
	 *
	 * @return void
	 */
	#[DataProvider('notificationProvider')]
	public function testTemplatesDoNotNameARenamedProperty(string $schemaName, string $key): void {
		$schema = self::schemas()[$schemaName];
		$notification = $schema['x-openregister-notifications'][$key];
		$text = json_encode(
			value: [
				($notification['subject'] ?? null),
				($notification['body'] ?? null),
				($notification['message'] ?? null),
				($notification['title'] ?? null),
			]
		);
		preg_match_all(pattern: '/\{\{\s*(\w+)\s*\}\}/', subject: (string)$text, matches: $matches);

		$renamed = (new ReflectionClassConstant(RenameDutchVocabularyColumns::class, 'COLUMN_MAP'))->getValue();
		foreach (array_unique($matches[1]) as $placeholder) {
			$column = strtolower((string)preg_replace(pattern: '/(?<!^)[A-Z]/', replacement: '_$0', subject: $placeholder));
			if (array_key_exists($column, $renamed) === false
				|| array_key_exists($placeholder, ($schema['properties'] ?? [])) === true
			) {
				continue;
			}

			self::fail(
				message: "{$schemaName}.{$key} renders {{{$placeholder}}}, a property renamed to {$renamed[$column]}, so it shows empty"
			);
		}

		self::assertTrue(condition: true);
	}//end testTemplatesDoNotNameARenamedProperty()

	/**
	 * The string values a filter entry compares against.
	 *
	 * A filter entry is a scalar (equality), a list (membership) or an
	 * operator object carrying `value`.
	 *
	 * @param mixed $expected The filter entry.
	 *
	 * @return string[]
	 */
	private static function filterValues(mixed $expected): array {
		if (is_array($expected) === true && array_key_exists('value', $expected) === true) {
			$expected = $expected['value'];
		}

		if (is_array($expected) === false) {
			$expected = [$expected];
		}

		return array_values(array_filter($expected, 'is_string'));
	}//end filterValues()

	/**
	 * The schemas of the register as it is imported.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemas(): array {
		static $schemas = null;
		if ($schemas === null) {
			$schemas = SettingsService::shippedRegisterDescriptor()['components']['schemas'];
		}

		return $schemas;
	}//end schemas()
}//end class
