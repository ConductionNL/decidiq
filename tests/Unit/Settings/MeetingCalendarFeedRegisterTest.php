<?php

/**
 * A meeting declares its dates for OpenRegister's calendar feed and may carry
 * a Microsoft Teams meeting whose join address is a Teams address
 * (platform-microsoft-365-calendar-and-teams tasks 1 and 4).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-001-a-meetings-dates-reach-openregisters-calendar-feed
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class MeetingCalendarFeedRegisterTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	private const JOIN = 'https://teams.microsoft.com/l/meetup-join/00000000-0000-0000-0000-000000000000';

	/**
	 * The schema with this slug, merged over the base register and every fragment.
	 *
	 * @param string $slug The schema slug
	 *
	 * @return array<string, mixed> The merged schema.
	 */
	private function mergedSchema(string $slug): array {
		$files  = array_merge([self::SETTINGS . 'decidesk_register.json'], (glob(self::SETTINGS . 'register.d/*.json') ?: []));
		$schema = [];
		foreach ($files as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			foreach (($doc['components']['schemas'] ?? []) as $name => $fragment) {
				if (($fragment['slug'] ?? $name) === $slug) {
					$schema = array_replace_recursive($schema, $fragment);
				}
			}
		}

		return $schema;
	}//end mergedSchema()

	/**
	 * The municipality example set's seeded meetings by slug.
	 *
	 * @return array<string, array<string, mixed>> The meetings.
	 */
	private function seededMeetings(): array {
		$doc = json_decode((string)file_get_contents(self::SETTINGS . 'profiles/municipality.json'), true);
		return array_column($doc['x-openregister']['seedData']['objects']['meeting'], null, 'slug');
	}//end seededMeetings()

	/**
	 * Whether a teamsMeeting value validates against the declared property.
	 *
	 * @param array<string, mixed> $teams The value
	 *
	 * @return bool
	 */
	private function teamsIsValid(array $teams): bool {
		$property = $this->mergedSchema('meeting')['properties']['teamsMeeting'];
		unset($property['nullable']);
		foreach ($property['properties'] as $key => $sub) {
			if (($sub['nullable'] ?? false) === true) {
				$sub['type'] = [$sub['type'], 'null'];
			}

			unset($sub['nullable']);
			$property['properties'][$key] = $sub;
		}

		$result = (new Validator())->validate(
			json_decode((string)json_encode($teams)),
			json_decode((string)json_encode($property))
		);

		return $result->isValid();
	}//end teamsIsValid()

	/**
	 * The meeting is an appointment from scheduledDate to endDate, two hours without an end, at its location.
	 *
	 * @return void
	 */
	public function testTheMeetingDeclaresItsDatesForTheFeed(): void {
		$schema   = $this->mergedSchema('meeting');
		$provider = ($schema['configuration']['calendarProvider'] ?? []);

		self::assertTrue($provider['enabled'] ?? false);
		self::assertSame('{title}', ($provider['titleTemplate'] ?? null));
		self::assertSame('location', ($provider['locationField'] ?? null));
		self::assertSame(
			['kind' => 'appointment', 'endProperty' => 'endDate', 'durationMinutes' => 120, 'summaryTemplate' => '{title}'],
			($provider['dates']['scheduledDate'] ?? null)
		);
		self::assertArrayNotHasKey('submissionDeadline', $provider['dates'], 'a clerk-set deadline is not resolved through the working calendar');
		foreach (['scheduledDate', 'endDate', 'location', 'title'] as $property) {
			self::assertArrayHasKey($property, $schema['properties'], $property . ' exists on the merged meeting');
		}
	}//end testTheMeetingDeclaresItsDatesForTheFeed()

	/**
	 * A Teams join address is accepted, any other address refused, and the join address is required.
	 *
	 * @return void
	 */
	public function testOnlyATeamsAddressIsAcceptedAsJoinAddress(): void {
		self::assertTrue($this->teamsIsValid(['joinUrl' => self::JOIN, 'externalId' => null, 'createdBy' => 'Griffie', 'createdAt' => '2027-03-30T10:00:00+02:00']));
		self::assertFalse($this->teamsIsValid(['joinUrl' => 'https://example.org/meet/123']));
		self::assertFalse($this->teamsIsValid(['joinUrl' => 'http://teams.microsoft.com/l/meetup-join/1']));
		self::assertFalse($this->teamsIsValid(['externalId' => 'graph-123']));
	}//end testOnlyATeamsAddressIsAcceptedAsJoinAddress()

	/**
	 * The example set seeds a digital meeting with a Teams meeting and a hybrid one without.
	 *
	 * @return void
	 */
	public function testTheMunicipalitySetSeedsATeamsAndAHybridMeeting(): void {
		$meetings = $this->seededMeetings();

		$digital = ($meetings['commissie-ruimte-digitaal-2027-04-16'] ?? []);
		self::assertSame('digital', ($digital['meetingMode'] ?? null));
		self::assertStringStartsWith('2027-04-16', (string)($digital['scheduledDate'] ?? ''));
		self::assertSame(self::JOIN, ($digital['teamsMeeting']['joinUrl'] ?? null));
		self::assertTrue($this->teamsIsValid($digital['teamsMeeting']));

		$hybrid = ($meetings['raadsvergadering-2027-04-23'] ?? []);
		self::assertSame('hybrid', ($hybrid['meetingMode'] ?? null));
		self::assertSame('Raadzaal, Stadhuis', ($hybrid['location'] ?? null));
		self::assertArrayNotHasKey('teamsMeeting', $hybrid);

		$properties = $this->mergedSchema('meeting')['properties'];
		foreach ([$digital, $hybrid] as $meeting) {
			foreach (array_keys($meeting) as $key) {
				if ($key === '@self' || $key === 'slug') {
					continue;
				}

				self::assertArrayHasKey($key, $properties, $key . ' is a meeting property');
			}
		}
	}//end testTheMunicipalitySetSeedsATeamsAndAHybridMeeting()
}//end class
