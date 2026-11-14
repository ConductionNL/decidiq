<?php

/**
 * The ORI wire format adapter.
 *
 * Decidiq stores council information on its own Popolo-aligned schemas with
 * English identifiers. The ORI (Open Raadsinformatie) exchange format uses
 * Dutch field names and values. This class is the one place that vocabulary
 * lives: it reads an ORI record into English-keyed fields for the importer,
 * and renders stored objects back into the ORI shape for the feeds. It holds
 * no state and persists nothing.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\Ori
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service\Ori;

/**
 * Reads ORI records into decidiq fields and renders decidiq objects as ORI records.
 *
 * Every `read*()` returns English keys; a reference to another ORI record is
 * returned as that record's slug, for the importer to resolve. A source value
 * the mapping does not know is returned as null, so the caller reports it
 * instead of storing an untranslated value.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One read and one render per ORI record type.
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */
class OriExportMapper {

	/**
	 * The source schemas, by what they hold, in the order they are imported.
	 *
	 * Bodies before the persons who are members of them, meetings and documents
	 * before the agenda items that reference them, agenda items before the votes
	 * taken on them.
	 */
	public const SOURCE_SCHEMAS = [
		'bodies' => 'fractie',
		'persons' => 'raadslid',
		'meetings' => 'vergadering',
		'documents' => 'raadsdocument',
		'agendaItems' => 'agendapunt',
		'votes' => 'stemming',
	];

	/**
	 * Meeting type: ORI value => decidiq `Meeting.meetingType`.
	 */
	public const MEETING_TYPES = [
		'raadsvergadering' => 'regular',
		'commissievergadering' => 'committee',
		'hearing' => 'public hearing',
		'informatiebijeenkomst' => 'informational-session',
	];

	/**
	 * Meeting status: ORI value => decidiq `Meeting.lifecycle`.
	 */
	public const MEETING_STATES = [
		'planned' => 'draft',
		'confirmed' => 'scheduled',
		'afgelast' => 'cancelled',
	];

	/**
	 * Document type: ORI value => decidiq `DigitalDocument.documentType`.
	 */
	public const DOCUMENT_TYPES = [
		'motion' => 'motion',
		'amendment' => 'amendment',
		'decision' => 'decision',
		'brief' => 'letter',
		'report' => 'report',
		'minutes' => 'minutes',
	];

	/**
	 * Vote type: ORI value => decidiq `Decision.decisionType`.
	 */
	public const DECISION_TYPES = [
		'motion' => 'motion',
		'amendment' => 'amendment',
		'proposal' => 'resolution',
	];

	/**
	 * Vote result: ORI value => decidiq `Decision.outcome` / `VotingRound.result`.
	 */
	public const RESULTS = [
		'aangenomen' => 'adopted',
		'verworpen' => 'rejected',
	];

	/**
	 * Party classification: ORI value => decidiq `GovernanceBody.coalitionRole`.
	 */
	public const COALITION_ROLES = [
		'coalitiepartij' => 'coalition',
		'oppositiepartij' => 'opposition',
	];

	/**
	 * How a party voted: ORI value => decidiq party result value.
	 */
	public const PARTY_VOTES = [
		'voor' => 'for',
		'tegen' => 'against',
		'onthouding' => 'abstain',
		'for' => 'for',
		'against' => 'against',
		'abstain' => 'abstain',
	];

	/**
	 * Member role: ORI value => [decidiq `Membership.role`, the body it is held on].
	 *
	 * An alderman sits on the executive board, everyone else on the council.
	 */
	public const ROLES = [
		'councilMember' => ['member', 'council'],
		'mayor' => ['chair', 'council'],
		'clerk' => ['secretary', 'council'],
		'alderman' => ['member', 'executive'],
	];

	/**
	 * Read a political group record.
	 *
	 * @param array<string, mixed> $record The ORI record.
	 *
	 * @return array{name: string, seatCount: int|null, coalitionRole: string|null, coalitionRoleSource: mixed}
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function readBody(array $record): array {
		return [
			'name' => self::text(value: ($record['name'] ?? null)),
			'seatCount' => self::integer(value: ($record['zetels'] ?? null)),
			'coalitionRole' => self::translate(map: self::COALITION_ROLES, value: ($record['classification'] ?? null)),
			'coalitionRoleSource' => ($record['classification'] ?? null),
		];
	}//end readBody()

	/**
	 * Read a council member record.
	 *
	 * @param array<string, mixed> $record The ORI record.
	 *
	 * @return array{name: string, role: string|null, body: string|null, roleSource: mixed, party: string|null, active: bool}
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function readPerson(array $record): array {
		$role = self::ROLES[(string)($record['role'] ?? '')] ?? [null, null];

		return [
			'name' => self::text(value: ($record['name'] ?? null)),
			'role' => $role[0],
			'body' => $role[1],
			'roleSource' => ($record['role'] ?? null),
			'party' => self::optionalText(value: ($record['fractie'] ?? null)),
			'active' => (($record['actief'] ?? true) !== false),
		];
	}//end readPerson()

	/**
	 * Read a meeting record.
	 *
	 * @param array<string, mixed> $record The ORI record.
	 *
	 * @return array<string, mixed> title, meetingType, lifecycle, scheduledDate, endDate, location,
	 *                              organisation, committee, and the untranslated typeSource/statusSource.
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function readMeeting(array $record): array {
		return [
			'title' => self::text(value: ($record['name'] ?? null)),
			'meetingType' => self::translate(map: self::MEETING_TYPES, value: ($record['type'] ?? null)),
			'typeSource' => ($record['type'] ?? null),
			'lifecycle' => self::translate(map: self::MEETING_STATES, value: ($record['status'] ?? null)),
			'statusSource' => ($record['status'] ?? null),
			'scheduledDate' => self::optionalText(value: ($record['startDate'] ?? null)),
			'endDate' => self::optionalText(value: ($record['endDate'] ?? null)),
			'location' => self::optionalText(value: ($record['location'] ?? null)),
			'organisation' => self::optionalText(value: ($record['organisation'] ?? null)),
			'committee' => self::optionalText(value: ($record['committee'] ?? null)),
		];
	}//end readMeeting()

	/**
	 * Read a council document record.
	 *
	 * @param array<string, mixed> $record The ORI record.
	 *
	 * @return array<string, mixed> name, documentType, typeSource, classification, url, fileName, contentSize, encodingFormat.
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function readDocument(array $record): array {
		$size = self::integer(value: ($record['fileSize'] ?? null));

		return [
			'name' => self::text(value: ($record['title'] ?? null)),
			'documentType' => self::translate(map: self::DOCUMENT_TYPES, value: ($record['type'] ?? null)),
			'typeSource' => ($record['type'] ?? null),
			'classification' => self::optionalText(value: ($record['classification'] ?? null)),
			'url' => self::optionalText(value: ($record['url'] ?? null)),
			'fileName' => self::optionalText(value: ($record['fileName'] ?? null)),
			'contentSize' => self::optionalText(value: $size),
			'encodingFormat' => self::optionalText(value: ($record['contentType'] ?? null)),
		];
	}//end readDocument()

	/**
	 * Read an agenda item record.
	 *
	 * @param array<string, mixed> $record The ORI record.
	 *
	 * @return array{title: string, description: string|null, orderNumber: int, meeting: string|null,
	 *               parentItem: string|null, documents: array<int, string>}
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function readAgendaItem(array $record): array {
		$documents = [];
		foreach ((array)($record['attachments'] ?? []) as $slug) {
			$slug = self::optionalText(value: $slug);
			if ($slug !== null) {
				$documents[] = $slug;
			}
		}

		return [
			'title' => self::text(value: ($record['subject'] ?? null)),
			'description' => self::optionalText(value: ($record['omschrijving'] ?? null)),
			'orderNumber' => (self::integer(value: ($record['sortOrder'] ?? null)) ?? 0),
			'meeting' => self::optionalText(value: ($record['vergadering'] ?? null)),
			'parentItem' => self::optionalText(value: ($record['parentAgendaItem'] ?? null)),
			'documents' => $documents,
		];
	}//end readAgendaItem()

	/**
	 * Read a vote record.
	 *
	 * @param array<string, mixed> $record The ORI record.
	 *
	 * @return array<string, mixed> subject, decisionType, outcome, agendaItem, votesFor, votesAgainst,
	 *                              votesAbstain, partyResults, and the untranslated sources.
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function readVote(array $record): array {
		$parties = [];
		foreach ((array)($record['politicalGroupResults'] ?? []) as $result) {
			$result = (array)$result;
			$parties[] = [
				'party' => self::optionalText(value: ($result['fractie'] ?? null)),
				'value' => self::translate(map: self::PARTY_VOTES, value: ($result['stem'] ?? null)),
				'valueSource' => ($result['stem'] ?? null),
				'seatCount' => self::integer(value: ($result['zetels'] ?? null)),
			];
		}

		return [
			'subject' => self::text(value: ($record['subject'] ?? null)),
			'decisionType' => self::translate(map: self::DECISION_TYPES, value: ($record['type'] ?? null)),
			'typeSource' => ($record['type'] ?? null),
			'outcome' => self::translate(map: self::RESULTS, value: ($record['result'] ?? null)),
			'resultSource' => ($record['result'] ?? null),
			'agendaItem' => self::optionalText(value: ($record['agendapunt'] ?? null)),
			'votesFor' => self::integer(value: ($record['votesFor'] ?? null)),
			'votesAgainst' => self::integer(value: ($record['votesAgainst'] ?? null)),
			'votesAbstain' => self::integer(value: ($record['onthoudingen'] ?? null)),
			'partyResults' => $parties,
		];
	}//end readVote()

	/**
	 * Render a stored Meeting as an ORI meeting record.
	 *
	 * @param array<string, mixed> $meeting The stored Meeting.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function meeting(array $meeting): array {
		return self::withoutNulls(
			record: [
				'id' => ($meeting['id'] ?? null),
				'name' => ($meeting['title'] ?? null),
				'type' => self::reverse(map: self::MEETING_TYPES, value: ($meeting['meetingType'] ?? null)),
				'status' => self::reverse(map: self::MEETING_STATES, value: ($meeting['lifecycle'] ?? null)),
				'startDate' => ($meeting['scheduledDate'] ?? null),
				'endDate' => ($meeting['endDate'] ?? null),
				'location' => ($meeting['location'] ?? null),
			]
		);
	}//end meeting()

	/**
	 * Render a stored AgendaItem as an ORI agenda item record.
	 *
	 * @param array<string, mixed> $item The stored AgendaItem.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function agendaItem(array $item): array {
		return self::withoutNulls(
			record: [
				'id' => ($item['id'] ?? null),
				'subject' => ($item['title'] ?? null),
				'omschrijving' => ($item['description'] ?? null),
				'sortOrder' => ($item['orderNumber'] ?? null),
				'vergadering' => ($item['meeting'] ?? null),
				'parentAgendaItem' => ($item['parentItem'] ?? null),
				'attachments' => ($item['documents'] ?? null),
			]
		);
	}//end agendaItem()

	/**
	 * Render a stored DigitalDocument as an ORI document record.
	 *
	 * @param array<string, mixed> $document The stored DigitalDocument.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function document(array $document): array {
		$size = self::integer(value: ($document['contentSize'] ?? null));

		return self::withoutNulls(
			record: [
				'id' => ($document['id'] ?? null),
				'title' => ($document['name'] ?? null),
				'type' => self::reverse(map: self::DOCUMENT_TYPES, value: ($document['documentType'] ?? null)),
				'classification' => ($document['classification'] ?? null),
				'url' => ($document['url'] ?? null),
				'fileName' => ($document['fileName'] ?? null),
				'fileSize' => $size,
				'contentType' => ($document['encodingFormat'] ?? null),
			]
		);
	}//end document()

	/**
	 * Render a stored VotingRound, with the Decision it voted on, as an ORI vote record.
	 *
	 * @param array<string, mixed> $round    The stored VotingRound.
	 * @param array<string, mixed> $decision The stored Decision it voted on; empty when unknown.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function vote(array $round, array $decision=[]): array {
		$parties = null;
		if (is_array($round['partyResults'] ?? null) === true) {
			$parties = [];
			foreach ($round['partyResults'] as $result) {
				$result = (array)$result;
				$parties[] = self::withoutNulls(
					record: [
						'fractie' => ($result['party'] ?? null),
						'stem' => self::reverse(map: ['voor' => 'for', 'tegen' => 'against', 'onthouding' => 'abstain'], value: ($result['value'] ?? null)),
						'zetels' => ($result['seatCount'] ?? null),
					]
				);
			}
		}

		$item = null;
		if (($decision['subjectSchema'] ?? null) === 'agenda-item') {
			$item = ($decision['subjectId'] ?? null);
		}

		return self::withoutNulls(
			record: [
				'id' => ($round['id'] ?? null),
				'subject' => ($decision['title'] ?? null),
				'type' => self::reverse(map: self::DECISION_TYPES, value: ($decision['decisionType'] ?? null)),
				'result' => self::reverse(map: self::RESULTS, value: ($round['result'] ?? ($decision['outcome'] ?? null))),
				'agendapunt' => $item,
				'votesFor' => ($round['votesFor'] ?? null),
				'votesAgainst' => ($round['votesAgainst'] ?? null),
				'onthoudingen' => ($round['votesAbstain'] ?? null),
				'politicalGroupResults' => $parties,
			]
		);
	}//end vote()

	/**
	 * Translate a source value, or null when the mapping does not know it.
	 *
	 * @param array<string, string> $map   Source value => decidiq value.
	 * @param mixed                 $value The source value.
	 *
	 * @return string|null
	 */
	private static function translate(array $map, mixed $value): ?string {
		if (is_string($value) === false) {
			return null;
		}

		return ($map[$value] ?? null);
	}//end translate()

	/**
	 * The source value a decidiq value came from, or null when none maps to it.
	 *
	 * @param array<string, string> $map   Source value => decidiq value.
	 * @param mixed                 $value The decidiq value.
	 *
	 * @return string|null
	 */
	private static function reverse(array $map, mixed $value): ?string {
		if (is_string($value) === false) {
			return null;
		}

		$source = array_search($value, $map, true);
		if ($source === false) {
			return null;
		}

		return (string)$source;
	}//end reverse()

	/**
	 * A value as trimmed text, empty when absent.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function text(mixed $value): string {
		return (self::optionalText(value: $value) ?? '');
	}//end text()

	/**
	 * A value as trimmed text, null when absent or empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function optionalText(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;
	}//end optionalText()

	/**
	 * A value as an integer, null when it is not a whole number.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int|null
	 */
	private static function integer(mixed $value): ?int {
		if (is_int($value) === true) {
			return $value;
		}

		if (is_string($value) === true && preg_match('/^-?\d+$/', trim($value)) === 1) {
			return (int)trim($value);
		}

		return null;
	}//end integer()

	/**
	 * A record without its null fields.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return array<string, mixed>
	 */
	private static function withoutNulls(array $record): array {
		return array_filter($record, static fn (mixed $value): bool => $value !== null);
	}//end withoutNulls()
}//end class
