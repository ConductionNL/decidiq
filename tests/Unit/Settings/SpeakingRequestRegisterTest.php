<?php

/**
 * One speaking-request schema for every kind of meeting and a per-body
 * speaking policy, with a declared lifecycle that freezes the citizen's own
 * fields after approval (inspreekrecht-plenair tasks 1 and 3).
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
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-001-generalized-inspraak-aanmelding-schema-on-openregister
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
class SpeakingRequestRegisterTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	private const WRITERS = ['decidiq-secretariat', 'decidiq-administrators', 'decidesk-administrators'];

	/**
	 * The schema with this slug, merged over the base register and every fragment.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<string, mixed>
	 */
	private function mergedSchema(string $slug): array {
		$files = array_merge([self::SETTINGS . 'decidesk_register.json'], (glob(self::SETTINGS . 'register.d/*.json') ?: []));
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
	 * How many register files declare a schema with this slug.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return int
	 */
	private function declarations(string $slug): int {
		$count = 0;
		foreach (array_merge([self::SETTINGS . 'decidesk_register.json'], (glob(self::SETTINGS . 'register.d/*.json') ?: [])) as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			foreach (($doc['components']['schemas'] ?? []) as $name => $fragment) {
				$count += (int)(($fragment['slug'] ?? $name) === $slug);
			}
		}

		return $count;
	}//end declarations()

	/**
	 * The seed objects of one example set.
	 *
	 * @param string $profile The example set.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function seeds(string $profile): array {
		$doc = json_decode((string)file_get_contents(self::SETTINGS . 'profiles/' . $profile . '.json'), true);
		return $doc['x-openregister']['seedData']['objects'];
	}//end seeds()

	/**
	 * Both schemas are declared once, in fragment 64, with titles on every property.
	 *
	 * @return void
	 */
	public function testBothSchemasAreDeclaredOnceWithTitles(): void {
		foreach (['speaking-request' => 'schema:RegisterAction', 'speaking-policy' => 'schema:StructuredValue'] as $slug => $type) {
			self::assertSame(1, $this->declarations($slug), $slug . ' has one canonical definition');
			$schema = $this->mergedSchema($slug);
			self::assertSame($type, ($schema['x-schema-org'] ?? null));
			foreach ($schema['properties'] as $key => $property) {
				self::assertNotSame('', (string)($property['title'] ?? ''), $slug . '.' . $key . ' has a title');
			}
		}

		$request = $this->mergedSchema('speaking-request');
		self::assertSame(['meeting', 'governanceBody', 'topic', 'lifecycle'], $request['required']);
		self::assertSame('meeting', $request['properties']['meeting']['$ref']);
		self::assertSame('agenda-item', $request['properties']['agendaItem']['$ref']);
		self::assertSame('governance-body', $request['properties']['governanceBody']['$ref']);
		self::assertSame(['speakerName', 'topicText'], $request['properties']['topic']['required']);
		self::assertSame(['name', 'email', 'phone', 'address'], array_keys($request['properties']['contactDetails']['properties']));

		$policy = $this->mergedSchema('speaking-policy');
		self::assertFalse($policy['properties']['enabled']['default'], 'a body offers no speaking rights until it says so');
		self::assertSame(24, $policy['properties']['registrationDeadlineHours']['default']);
		self::assertSame(5, $policy['properties']['defaultSpeakingMinutes']['default']);
		self::assertSame(['agenda-item', 'meeting'], $policy['properties']['level']['enum']);
		self::assertSame(['count', 'first-name', 'speaker-name'], $policy['properties']['publicDisplay']['enum']);
	}//end testBothSchemasAreDeclaredOnceWithTitles()

	/**
	 * The lifecycle is the specced map, and nothing leaves a terminal state.
	 *
	 * @return void
	 */
	public function testTheLifecycleIsTheSpeccedMap(): void {
		$lifecycle = $this->mergedSchema('speaking-request')['x-openregister-lifecycle'];
		self::assertSame('lifecycle', $lifecycle['field']);
		self::assertSame('registered', $lifecycle['initial']);
		$pairs = array_map(static fn (array $t): string => $t['from'] . '>' . $t['to'], $lifecycle['transitions']);
		sort($pairs);
		self::assertSame(['approved>no-show', 'approved>spoke', 'registered>approved', 'registered>rejected'], $pairs);
		self::assertSame(['rejected', 'spoke', 'no-show'], $lifecycle['terminal']);
		self::assertSame(
			['registered', 'approved', 'rejected', 'spoke', 'no-show'],
			$this->mergedSchema('speaking-request')['properties']['lifecycle']['enum']
		);
		self::assertSame(array_keys($lifecycle['states']), ['registered', 'approved', 'rejected', 'spoke', 'no-show']);
	}//end testTheLifecycleIsTheSpeccedMap()

	/**
	 * From approval on the citizen's fields are read only; the rest stays writable.
	 *
	 * @return void
	 */
	public function testTheCitizensFieldsFreezeAtApproval(): void {
		$states = $this->mergedSchema('speaking-request')['x-openregister-lifecycle']['states'];
		self::assertSame([], ($states['registered']['fields']['readOnly'] ?? []), 'a registered request can still be corrected');
		foreach (['approved', 'rejected', 'spoke', 'no-show'] as $state) {
			$readOnly = ($states[$state]['fields']['readOnly'] ?? []);
			self::assertSame(['contactDetails', 'topic'], $readOnly, $state . ' freezes the citizen fields');
			foreach (['lifecycle', 'allottedMinutes', 'position', 'contribution', 'transcriptSegment'] as $writable) {
				self::assertNotContains($writable, $readOnly, $state . ' keeps ' . $writable . ' writable');
			}
		}

		self::assertSame(['rejectionReason'], $states['rejected']['fields']['required'], 'a rejection needs a reason');
	}//end testTheCitizensFieldsFreezeAtApproval()

	/**
	 * Members read requests, only the secretariat reads contact details, only staff write.
	 *
	 * @return void
	 */
	public function testContactDetailsAreForTheSecretariatOnly(): void {
		foreach (['speaking-request', 'speaking-policy'] as $slug) {
			$auth = $this->mergedSchema($slug)['authorization'];
			self::assertSame(['authenticated'], $auth['read'], $slug . ' is read by members, never anonymously');
			foreach (['create', 'update', 'delete'] as $verb) {
				self::assertSame(self::WRITERS, $auth[$verb], $slug . ' ' . $verb . ' is staff only');
			}
		}

		$contact = $this->mergedSchema('speaking-request')['properties']['contactDetails']['authorization'];
		self::assertSame(self::WRITERS, $contact['read']);
		self::assertSame(self::WRITERS, $contact['update']);
		self::assertArrayNotHasKey('authorization', $this->mergedSchema('speaking-request')['properties']['topic'], 'the topic is publishable');
	}//end testContactDetailsAreForTheSecretariatOnly()

	/**
	 * Every notification uses a trigger OpenRegister fires, with Dutch and English subjects.
	 *
	 * @return void
	 */
	public function testNotificationsAreDeclaredInBothLanguages(): void {
		$notifications = $this->mergedSchema('speaking-request')['x-openregister-notifications'];
		self::assertSame(
			['speakingRequestRegistered', 'speakingRequestApproved', 'speakingRequestRejected', 'speakingRequestReferred'],
			array_keys($notifications)
		);
		self::assertSame('created', $notifications['speakingRequestRegistered']['trigger']['type']);
		self::assertSame('approved', $notifications['speakingRequestApproved']['trigger']['condition']['value']);
		self::assertSame('rejected', $notifications['speakingRequestRejected']['trigger']['condition']['value']);
		self::assertSame(['field' => 'meeting', 'operator' => 'changed'], $notifications['speakingRequestReferred']['trigger']['condition']);
		foreach ($notifications as $name => $notification) {
			self::assertNotSame('', (string)($notification['subject']['nl'] ?? ''), $name . ' has a Dutch subject');
			self::assertNotSame('', (string)($notification['subject']['en'] ?? ''), $name . ' has an English subject');
		}
	}//end testNotificationsAreDeclaredInBothLanguages()

	/**
	 * The municipality set seeds a policy and three requests on seeded meetings.
	 *
	 * @return void
	 */
	public function testTheMunicipalitySetSeedsTheLoop(): void {
		$seeds = $this->seeds('municipality');
		$meetings = array_column($seeds['meeting'], null, 'slug');
		$items = array_column($seeds['agenda-item'], null, 'slug');
		$bodies = array_column($seeds['governance-body'], null, 'slug');

		self::assertCount(1, $seeds['speaking-policy']);
		$policy = $seeds['speaking-policy'][0];
		self::assertArrayHasKey($policy['governanceBody'], $bodies);
		self::assertSame(['agenda-item', 'first-name'], [$policy['level'], $policy['publicDisplay']]);
		$this->assertValidSeed('speaking-policy', $policy);

		$byState = array_column($seeds['speaking-request'], null, 'lifecycle');
		self::assertSame(['spoke', 'approved', 'registered'], array_column($seeds['speaking-request'], 'lifecycle'));
		self::assertNotSame('', $byState['spoke']['contribution']);
		self::assertSame([4, 2], [$byState['approved']['allottedMinutes'], $byState['approved']['position']]);
		self::assertArrayNotHasKey('allottedMinutes', $byState['registered']);
		foreach ($seeds['speaking-request'] as $request) {
			self::assertArrayHasKey($request['meeting'], $meetings, 'the request names a seeded meeting');
			self::assertSame($request['meeting'], $items[$request['agendaItem']]['meeting'], 'the agenda item belongs to the meeting');
			self::assertSame($policy['governanceBody'], $request['governanceBody']);
			$this->assertValidSeed('speaking-request', $request);
		}
	}//end testTheMunicipalitySetSeedsTheLoop()

	/**
	 * The association set seeds meeting-level speaking and a stored rejection reason.
	 *
	 * @return void
	 */
	public function testTheAssociationSetSeedsMeetingLevelSpeaking(): void {
		$seeds = $this->seeds('association');
		$meetings = array_column($seeds['meeting'], null, 'slug');
		$policy = $seeds['speaking-policy'][0];
		self::assertSame(['meeting', 'count', 48, 3], [$policy['level'], $policy['publicDisplay'], $policy['registrationDeadlineHours'], $policy['defaultSpeakingMinutes']]);
		$this->assertValidSeed('speaking-policy', $policy);

		$rejected = $seeds['speaking-request'][0];
		self::assertSame('rejected', $rejected['lifecycle']);
		self::assertSame('Onderwerp staat niet op de agenda van deze vergadering', $rejected['rejectionReason']);
		self::assertArrayNotHasKey('agendaItem', $rejected, 'meeting-level speaking names no agenda item');
		self::assertArrayHasKey($rejected['meeting'], $meetings);
		$this->assertValidSeed('speaking-request', $rejected);
	}//end testTheAssociationSetSeedsMeetingLevelSpeaking()

	/**
	 * The seed validates against its schema with no undeclared property.
	 *
	 * @param string               $slug   The schema slug.
	 * @param array<string, mixed> $object The seed object.
	 *
	 * @return void
	 */
	private function assertValidSeed(string $slug, array $object): void {
		$schema = $this->mergedSchema($slug);
		unset($object['@self'], $object['slug']);
		$properties = [];
		foreach ($schema['properties'] as $key => $property) {
			if (isset($property['$ref']) === true) {
				unset($property['format']);
			}

			unset($property['$ref'], $property['facetable'], $property['authorization']);
			$properties[$key] = $property;
		}

		$result = (new Validator())->validate(
			json_decode((string)json_encode($object)),
			json_decode((string)json_encode(['type' => 'object', 'required' => ($schema['required'] ?? []), 'properties' => $properties, 'additionalProperties' => false]))
		);
		self::assertTrue($result->isValid(), sprintf('The %s seed validates: %s', $slug, json_encode($object)));
	}//end assertValidSeed()
}//end class
