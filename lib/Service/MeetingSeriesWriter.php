<?php

/**
 * Decidiq Meeting Series Writer
 *
 * Writes a planned meeting series through OpenRegister: stamps the series on
 * the template meeting, creates one meeting per expanded date and copies the
 * template's agenda onto each of them.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/meeting-management/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * Writes the objects of a meeting series planned by MeetingSeriesService.
 *
 * Every method takes the planned run: objectService, meetingId, template,
 * series, pattern, scheduledDate and expansion.
 *
 * @spec openspec/specs/meeting-management/spec.md
 */
final class MeetingSeriesWriter {

	/**
	 * Descriptive Meeting fields copied from the template onto every
	 * generated instance (each instance stays independently editable —
	 * REQ-MSR-002 is untouched because instances are plain OR objects).
	 *
	 * @var string[]
	 */
	private const COPIED_FIELDS = [
		'title',
		'meetingType',
		'meetingMode',
		'location',
		'virtualLocation',
		'eventAttendanceMode',
		'governanceBody',
		'quorumRequired',
		'chair',
		'isPublic',
	];

	/**
	 * Stamp series + pattern on the template (REQ-MSR-001: pattern is stored
	 * as JSON on the first/template meeting).
	 *
	 * @param array<string, mixed> $run The planned run
	 *
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return void
	 */
	public function stampTemplate(array $run): void {
		$run['objectService']->saveObject(
			object: array_merge($run['template'], ['series' => $run['series'], 'seriesPattern' => $run['pattern']]),
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'decidiq', schema: 'meeting'), uuid: $run['meetingId'])
		);

	}//end stampTemplate()

	/**
	 * Create one independently-editable Meeting per non-template date.
	 *
	 * @param array<string, mixed> $run The planned run (objectService, template, series, expansion, scheduledDate)
	 *
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return array<int, array<string, mixed>> Created instance payloads
	 */
	public function createInstances(array $run): array {
		$templateDay = substr($run['scheduledDate'], 0, 10);
		$instances = [];
		foreach ($run['expansion']['dates'] as $date) {
			// The template itself covers its own date.
			if (substr($date, 0, 10) !== $templateDay) {
				$instances[] = $this->createInstance(run: $run, date: $date);
			}
		}

		return $instances;

	}//end createInstances()

	/**
	 * Create the Meeting for one date and return what was stored.
	 *
	 * @param array<string, mixed> $run The planned run
	 * @param string $date The instance's ISO-8601 datetime
	 *
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return array<string, mixed> The stored instance, or the payload when the save answered no object
	 */
	private function createInstance(array $run, string $date): array {
		$instance = $this->instancePayload(run: $run, date: $date);
		$saved = $run['objectService']->saveObject(
			object: $instance,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'decidiq', schema: 'meeting'))
		);
		if (is_object($saved) === true) {
			return (array)$saved->jsonSerialize();
		}

		return $instance;

	}//end createInstance()

	/**
	 * The payload of one instance: its date, lifecycle and series, plus the
	 * template's descriptive fields that are set.
	 *
	 * @param array<string, mixed> $run The planned run
	 * @param string $date The instance's ISO-8601 datetime
	 *
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return array<string, mixed> The payload
	 */
	private function instancePayload(array $run, string $date): array {
		$instance = [
			'scheduledDate' => $date,
			'lifecycle' => 'scheduled',
			'series' => $run['series'],
		];
		foreach (self::COPIED_FIELDS as $field) {
			if (array_key_exists($field, $run['template']) === true && $run['template'][$field] !== null) {
				$instance[$field] = $run['template'][$field];
			}
		}

		return $instance;

	}//end instancePayload()

	/**
	 * Give every generated meeting the template meeting's agenda
	 * (agenda-templates-and-copy, pla-14).
	 *
	 * @param array<string, mixed>             $run       The planned run (objectService, meetingId)
	 * @param array<int, array<string, mixed>> $instances The generated meetings
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-002-copy-items-or-a-whole-agenda-from-an-earlier-meeting
	 */
	public function copyAgenda(array $run, array $instances): void {
		$filters = ['register' => 'decidiq', 'schema' => 'agenda-item', 'meeting' => $run['meetingId']];
		$items = $run['objectService']->findAll(['filters' => $filters, 'limit' => 500]);
		if ($items === []) {
			return;
		}

		$copy = ['items' => $items, 'copier' => new AgendaItemCopier()];
		foreach ($instances as $instance) {
			$toMeetingId = (string)($instance['id'] ?? $instance['@self']['id'] ?? '');
			if ($toMeetingId !== '') {
				$this->copyAgendaTo(run: $run, copy: $copy + ['to' => $toMeetingId]);
			}
		}

	}//end copyAgenda()

	/**
	 * Save a copy of the template's agenda items on one generated meeting.
	 *
	 * @param array<string, mixed> $run  The planned run (objectService, meetingId)
	 * @param array<string, mixed> $copy The items, the copier and the target meeting id under `to`
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-002-copy-items-or-a-whole-agenda-from-an-earlier-meeting
	 */
	private function copyAgendaTo(array $run, array $copy): void {
		$payloads = $copy['copier']->payloads(items: $copy['items'], fromMeetingId: $run['meetingId'], toMeetingId: $copy['to']);
		foreach ($payloads as $payload) {
			$run['objectService']->saveObject(
				object: $payload,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'decidiq', schema: 'agenda-item'))
			);
		}

	}//end copyAgendaTo()
}//end class
