<?php

/**
 * Unit tests for attendance per meeting (meeting-attendance-per-meeting, pla-09).
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
 * @spec openspec/changes/meeting-attendance-per-meeting/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MeetingAttendanceReader;
use OCA\Decidiq\Service\MeetingCostService;
use OCA\Decidiq\Service\MeetingRuleSource;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Anna sent apologies for the meeting of 14 October; on 7 October she was there.
 *
 * @spec openspec/changes/meeting-attendance-per-meeting/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
 */
class MeetingAttendanceTest extends TestCase {

	/**
	 * Stored attendance records.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $attendance = [];

	/**
	 * The body's participants. Their own attendanceStatus is the legacy
	 * single value, which says everyone was present.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $participants = [];

	/**
	 * Build the fixture.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->participants = [];
		foreach (['anna', 'bert', 'cees', 'dirk', 'eva'] as $name) {
			$this->participants[] = ['id' => 'P-' . $name, 'displayName' => ucfirst($name), 'governanceBody' => 'body-1', 'attendanceStatus' => 'present'];
		}

		$this->attendance = [
			['meeting' => 'm-07', 'participant' => 'P-anna', 'status' => 'present'],
			['meeting' => 'm-14', 'participant' => 'P-anna', 'status' => 'excused'],
			['meeting' => 'm-14', 'participant' => 'P-bert', 'status' => 'present'],
			['meeting' => 'm-14', 'participant' => 'P-cees', 'status' => 'proxy'],
			['meeting' => 'm-14', 'participant' => 'P-dirk', 'status' => 'absent'],
			['meeting' => 'm-14', 'participant' => 'P-eva', 'status' => 'absent'],
		];
	}//end setUp()

	/**
	 * Wrap an array as an ObjectEntity double.
	 *
	 * @param array<string, mixed> $data The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getUuid')->willReturn(($data['id'] ?? null));
		return $entity;
	}//end entity()

	/**
	 * The OpenRegister double: attendance answers every record (the reader must
	 * keep only its meeting's), participants answer the body.
	 *
	 * @param array<string, mixed> $meeting The meeting find() answers
	 * @param array<string, mixed> $body    The body find() answers
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(array $meeting = [], array $body = []): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				if (($config['filters']['schema'] ?? '') === 'meeting-attendance') {
					return array_map(fn (array $row): ObjectEntity => $this->entity($row), $this->attendance);
				}

				return array_map(fn (array $row): ObjectEntity => $this->entity($row), $this->participants);
			}
		);
		$objectService->method('find')->willReturnCallback(
			fn (int|string $id, mixed ...$rest): ?ObjectEntity => match (true) {
				$id === ($meeting['id'] ?? null) => $this->entity($meeting),
				$id === ($body['id'] ?? null) => $this->entity($body),
				default => null,
			}
		);
		return $objectService;
	}//end objectService()

	/**
	 * The merged schema for a slug (base register plus every register.d fragment).
	 *
	 * @param string $slug The schema slug
	 *
	 * @return array<string, mixed>
	 */
	private function mergedSchema(string $slug): array {
		$settings = __DIR__ . '/../../../lib/Settings/';
		$files = array_merge([$settings . 'decidesk_register.json'], (glob($settings . 'register.d/*.json') ?: []));
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
	 * Validate an object against a merged schema.
	 *
	 * @param array<string, mixed> $data   The object
	 * @param array<string, mixed> $schema The merged schema
	 *
	 * @return bool
	 */
	private function validates(array $data, array $schema): bool {
		$properties = $schema['properties'];
		foreach ($properties as $key => $property) {
			unset($properties[$key]['$ref'], $properties[$key]['facetable']);
		}

		$result = (new Validator())->validate(
			json_decode((string)json_encode($data)),
			json_decode((string)json_encode(['type' => 'object', 'required' => ($schema['required'] ?? []), 'properties' => $properties, 'additionalProperties' => false]))
		);
		return $result->isValid();
	}//end validates()

	/**
	 * The attendance the widget writes validates against the real fragment,
	 * and the schema is part of the decidiq register.
	 *
	 * @return void
	 */
	public function testTheWidgetsAttendanceRecordValidates(): void {
		$schema = $this->mergedSchema(slug: 'meeting-attendance');
		$this->assertNotSame([], $schema, 'A meeting-attendance schema is declared');

		$uuid = '6f1c1c38-4d3c-4d0e-9a55-2b8b2b1f0e01';
		$this->assertTrue($this->validates(['meeting' => $uuid, 'participant' => $uuid, 'status' => 'excused'], $schema));
		$this->assertTrue(
			$this->validates(['meeting' => $uuid, 'participant' => $uuid, 'status' => 'present', 'arrivedAt' => '2026-10-14T19:32:00+02:00', 'leftAt' => '2026-10-14T21:05:00+02:00'], $schema)
		);
		$this->assertFalse($this->validates(['meeting' => $uuid, 'participant' => $uuid, 'status' => 'late'], $schema));
		$this->assertFalse($this->validates(['participant' => $uuid, 'status' => 'present'], $schema));

		$registerSchemas = [];
		foreach ((glob(__DIR__ . '/../../../lib/Settings/register.d/*.json') ?: []) as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			$registerSchemas = array_merge($registerSchemas, ($doc['components']['registers']['decidiq']['schemas'] ?? []));
		}

		$this->assertContains('meeting-attendance', $registerSchemas);
	}//end testTheWidgetsAttendanceRecordValidates()

	/**
	 * Scenario "The clerk records apologies": the meeting of 14 October reads
	 * Anna as excused, the one of 7 October keeps her present.
	 *
	 * @return void
	 */
	public function testEachMeetingReadsItsOwnAttendance(): void {
		$reader = new MeetingAttendanceReader(objectService: $this->objectService());

		$this->assertSame('excused', ($reader->statusesFor(meetingId: 'm-14')['P-anna'] ?? null));
		$this->assertSame(['P-anna' => 'present'], $reader->statusesFor(meetingId: 'm-07'));
		$this->assertSame([], $reader->statusesFor(meetingId: 'm-21'));
	}//end testEachMeetingReadsItsOwnAttendance()

	/**
	 * The quorum check counts this meeting's attendance, not the single
	 * Participant value: 2 of 5 present or by proxy do not reach a quorum of 3.
	 *
	 * @return void
	 */
	public function testTheQuorumCountsThisMeetingsAttendance(): void {
		$meeting = ['id' => 'm-14', 'governanceBody' => 'body-1', 'quorumRequired' => 3];
		$participants = $this->createMock(ParticipantResolver::class);
		$participants->method('resolveMeetingParticipants')->willReturn($this->participants);
		$participants->method('resolveGovernanceBodyId')->willReturn('body-1');

		$source = new MeetingRuleSource(
			objectService: $this->objectService(meeting: $meeting, body: ['id' => 'body-1']),
			participantResolver: $participants,
		);
		$this->assertFalse($source->quorumMet(meetingId: 'm-14'));

		$meeting['id'] = 'm-21';
		$source = new MeetingRuleSource(
			objectService: $this->objectService(meeting: $meeting, body: ['id' => 'body-1']),
			participantResolver: $participants,
		);
		$this->assertTrue($source->quorumMet(meetingId: 'm-21'), 'A meeting without attendance records keeps the Participant value');
	}//end testTheQuorumCountsThisMeetingsAttendance()

	/**
	 * The meeting cost counts the members present at this meeting.
	 *
	 * @return void
	 */
	public function testTheCostCountsThisMeetingsPresentMembers(): void {
		$cost = (new MeetingCostService(
			logger: new NullLogger(),
			objectService: $this->objectService(body: ['id' => 'body-1', 'hourlyRate' => 100])
		))->calculateForMeeting(
			meetingId: 'm-14',
			meeting: [
				'id' => 'm-14',
				'governanceBody' => 'body-1',
				'openedAt' => '2026-10-14T19:00:00+00:00',
				'closedAt' => '2026-10-14T20:00:00+00:00',
			]
		);

		// Only Bert is present in person: 1 hour x 1 member x EUR 100.
		$this->assertSame(100.0, $cost);
	}//end testTheCostCountsThisMeetingsPresentMembers()
}//end class
