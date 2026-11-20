<?php

/**
 * The urgent decisions register: the declaring body and office and the report
 * date on a decision, and the five urgent decisions of the municipality
 * example set.
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

use OCA\Decidiq\Service\UrgencyDeclarationService;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * Register and seed tests for urgent-decisions-register tasks 1 and 5.
 *
 * @spec openspec/changes/urgent-decisions-register/tasks.md#task-1-fields
 */
class UrgentDecisionsRegisterTest extends TestCase {

	/**
	 * The three fields this change adds to a decision.
	 *
	 * @var list<string>
	 */
	private const FIELDS = ['urgencyDeclaredByBody', 'urgencyDeclaredInOffice', 'reportedToRatifyingBodyAt'];

	/**
	 * The decision carries the declaring body, the office and the report
	 * date, all optional.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-020-the-declaration-records-the-declaring-body-or-office
	 */
	public function testTheDecisionCarriesTheRegisterFields(): void {
		$schema     = MergedRegisterSchema::forSlug(slug: 'decision');
		$properties = $schema['properties'];

		self::assertSame(
			expected: ['governance-body', 'uuid'],
			actual: [$properties['urgencyDeclaredByBody']['$ref'], $properties['urgencyDeclaredByBody']['format']]
		);
		self::assertSame(expected: 'string', actual: $properties['urgencyDeclaredInOffice']['type']);
		self::assertSame(expected: 'date', actual: $properties['reportedToRatifyingBodyAt']['format']);
		self::assertSame(expected: [], actual: array_intersect(self::FIELDS, $schema['required'] ?? []));

		$payload = [
			'title'                     => 'Noodverordening Havenfestival 2026',
			'isUrgent'                  => true,
			'urgencyDeclaredByBody'     => 'body-college',
			'urgencyDeclaredInOffice'   => 'Burgemeester',
			'reportedToRatifyingBodyAt' => '2026-10-06',
		];
		$refused = array_filter(
			MergedRegisterSchema::violations(slug: 'decision', payload: $payload),
			static fn (string $line): bool => (str_contains($line, 'urgencyDeclared') === true || str_contains($line, 'reportedTo') === true)
		);
		self::assertSame(expected: [], actual: array_values($refused));

	}//end testTheDecisionCarriesTheRegisterFields()

	/**
	 * The three fields join the server-guarded urgency fields, so a direct
	 * edit cannot set them and every decision save carries them forward.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/urgent-decisions-register/tasks.md#task-1-fields
	 */
	public function testTheFieldsAreGuarded(): void {
		foreach (self::FIELDS as $field) {
			self::assertContains(needle: $field, haystack: UrgencyDeclarationService::URGENCY_FIELDS);
		}

	}//end testTheFieldsAreGuarded()

	/**
	 * The example set holds five urgent decisions, three still awaiting
	 * ratification and two ratified, each with its declaring body, a mayor's
	 * one with its office and report date, and a ratifying stage.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/urgent-decisions-register/tasks.md#task-5-example-data
	 */
	public function testTheExampleSetHoldsFiveUrgentDecisions(): void {
		$objects = self::objects();
		$urgent  = array_values(array_filter($objects['decision'], static fn (array $row): bool => ($row['isUrgent'] ?? false) === true));
		$bodies  = array_column($objects['governance-body'], 'slug');

		self::assertCount(expectedCount: 5, haystack: $urgent);
		$ratifying = [];
		foreach ($objects['decision-stage'] as $stage) {
			if ($stage['stageType'] === 'ratifying') {
				$ratifying[$stage['decision']] = $stage['status'];
			}
		}

		$waiting = 0;
		foreach ($urgent as $decision) {
			self::assertContains(needle: $decision['urgencyDeclaredByBody'], haystack: $bodies, message: $decision['title']);
			self::assertArrayHasKey(key: $decision['slug'], array: $ratifying, message: $decision['title'] . ' has no ratifying stage');
			self::assertSame(expected: [], actual: self::violations(decision: $decision));
			if ($ratifying[$decision['slug']] === 'pending') {
				$waiting++;
			}
		}

		self::assertSame(expected: 3, actual: $waiting);

		$byTitle = array_column($urgent, null, 'title');
		self::assertSame(expected: 'Burgemeester', actual: $byTitle['Noodverordening Havenfestival 2026']['urgencyDeclaredInOffice']);
		self::assertSame(expected: '2026-10-06', actual: $byTitle['Noodverordening Havenfestival 2026']['reportedToRatifyingBodyAt']);
		self::assertArrayNotHasKey(key: 'urgencyDeclaredInOffice', array: $byTitle['Tijdelijke opvang in sporthal De Linde']);
		self::assertArrayNotHasKey(key: 'reportedToRatifyingBodyAt', array: $byTitle['Tijdelijke opvang in sporthal De Linde']);

	}//end testTheExampleSetHoldsFiveUrgentDecisions()

	/**
	 * The schema refusals of a seeded decision, without its seed keys.
	 *
	 * @param array<string, mixed> $decision The seeded decision.
	 *
	 * @return list<string>
	 */
	private static function violations(array $decision): array {
		$payload = array_diff_key($decision, ['@self' => true, 'slug' => true, 'uuid' => true, 'id' => true]);
		return MergedRegisterSchema::violations(slug: 'decision', payload: $payload);

	}//end violations()

	/**
	 * The municipality example set's objects by schema.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function objects(): array {
		$profile = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/municipality.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		return $profile['x-openregister']['seedData']['objects'];

	}//end objects()

}//end class
