<?php

/**
 * Migrates council information from an ORI register into decidiq's own schemas.
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Reads every object of a source ORI register, maps it per the mapping table in
 * the ori-adoption design, and writes decidiq objects in dependency order.
 *
 * Every object written carries `externalReference: ori:<source uuid>`; a re-run
 * finds it by that tag and updates it instead of creating a second one, and a
 * rollback deletes exactly the tagged objects. The source register is only
 * read. A reference that resolves to nothing, or a value the mapping does not
 * know, is reported as a problem rather than dropped or stored untranslated.
 * Party-level vote results are kept as an aggregate on the voting round; no
 * per-member votes are invented from them.
 *
 * The run state lives on the instance and is reset at the start of each run.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One mapping step per ORI record type.
 * @SuppressWarnings(PHPMD.TooManyMethods)
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */
class OriImportService {

	/**
	 * The register decidiq's objects live in.
	 */
	public const TARGET_REGISTER = 'decidiq';

	/**
	 * The prefix of every import tag.
	 */
	public const TAG_PREFIX = 'ori:';

	/**
	 * The target schemas, in the order they are written.
	 */
	public const TARGET_SCHEMAS = [
		'governance-body',
		'person',
		'membership',
		'meeting',
		'digital-document',
		'agenda-item',
		'decision',
		'voting-round',
	];

	/**
	 * The domain a body the import creates gets.
	 */
	private const DOMAIN = 'municipality';

	/**
	 * The council name used when no meeting names its organisation.
	 */
	private const DEFAULT_COUNCIL = 'Council';

	/**
	 * Whether this run writes nothing.
	 *
	 * @var bool
	 */
	private bool $dryRun = false;

	/**
	 * The report of this run.
	 *
	 * @var OriImportReport
	 */
	private OriImportReport $report;

	/**
	 * Import tag => uuid of every tagged object, per target schema.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $tagged = [];

	/**
	 * Import tags already written in this run, per target schema.
	 *
	 * @var array<string, array<string, true>>
	 */
	private array $written = [];

	/**
	 * Source slug => written uuid, per source schema.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $slugs = [];

	/**
	 * Political group slug => its name.
	 *
	 * @var array<string, string>
	 */
	private array $partyNames = [];

	/**
	 * Agenda item uuid => its title.
	 *
	 * @var array<string, string>
	 */
	private array $itemTitles = [];

	/**
	 * The council body's name and uuid.
	 *
	 * @var array{name: string, uuid: string}
	 */
	private array $council = ['name' => '', 'uuid' => ''];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads the source register and writes decidiq's.
	 * @param OriExportMapper        $mapper        Reads ORI records.
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly OriExportMapper $mapper,
	) {
		$this->report = new OriImportReport();
	}//end __construct()

	/**
	 * Import every object of a source ORI register.
	 *
	 * @param string $sourceRegister The source register's slug.
	 * @param string $cutoverDate    The end date given to the memberships of an inactive member (Y-m-d).
	 *
	 * @return OriImportReport
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function import(string $sourceRegister='ori', string $cutoverDate=''): OriImportReport {
		$this->begin(dryRun: false);

		return $this->run(sourceRegister: $sourceRegister, cutoverDate: $cutoverDate);
	}//end import()

	/**
	 * Report what an import would write, writing nothing.
	 *
	 * @param string $sourceRegister The source register's slug.
	 * @param string $cutoverDate    The end date given to the memberships of an inactive member (Y-m-d).
	 *
	 * @return OriImportReport
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function preview(string $sourceRegister='ori', string $cutoverDate=''): OriImportReport {
		$this->begin(dryRun: true);

		return $this->run(sourceRegister: $sourceRegister, cutoverDate: $cutoverDate);
	}//end preview()

	/**
	 * Read the source and write (or, in a dry run, report) every mapped object.
	 *
	 * @param string $sourceRegister The source register's slug.
	 * @param string $cutoverDate    The end date given to the memberships of an inactive member (Y-m-d).
	 *
	 * @return OriImportReport
	 */
	private function run(string $sourceRegister, string $cutoverDate): OriImportReport {
		if ($cutoverDate === '') {
			$cutoverDate = date('Y-m-d');
		}

		$source = [];
		foreach (OriExportMapper::SOURCE_SCHEMAS as $role => $schema) {
			$source[$role] = $this->records(register: $sourceRegister, schema: $schema);
			$this->report->read(schema: $schema, count: count($source[$role]));
		}

		$this->importCouncil(meetings: $source['meetings']);
		$this->importBodies(records: $source['bodies']);
		$this->importPersons(records: $source['persons'], cutoverDate: $cutoverDate);
		$this->importMeetings(records: $source['meetings']);
		$this->importDocuments(records: $source['documents']);
		$this->importAgendaItems(records: $source['agendaItems'], votes: $source['votes']);
		$this->importVotes(records: $source['votes']);

		return $this->report;
	}//end run()

	/**
	 * Delete every decidiq object an import wrote, and nothing else.
	 *
	 * @return OriImportReport
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function rollback(): OriImportReport {
		$this->begin(dryRun: false);

		return $this->removeTagged();
	}//end rollback()

	/**
	 * Report what a rollback would delete, deleting nothing.
	 *
	 * @return OriImportReport
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function previewRollback(): OriImportReport {
		$this->begin(dryRun: true);

		return $this->removeTagged();
	}//end previewRollback()

	/**
	 * Delete (or, in a dry run, report) every tagged object, dependants first.
	 *
	 * @return OriImportReport
	 */
	private function removeTagged(): OriImportReport {

		foreach (array_reverse(array_keys($this->tagged)) as $schema) {
			foreach ($this->tagged[$schema] as $tag => $uuid) {
				if ($this->dryRun === false) {
					$this->objectService->deleteObject(
						uuid: $uuid,
						register: self::TARGET_REGISTER,
						schema: $schema,
						_rbac: false,
						_multitenancy: false
					);
				}

				$this->report->wrote(action: 'deleted', schema: $schema, reference: $tag);
			}
		}

		return $this->report;
	}//end removeTagged()

	/**
	 * Reset the run state and read the tags already in decidiq.
	 *
	 * @param bool $dryRun Whether this run writes nothing.
	 *
	 * @return void
	 */
	private function begin(bool $dryRun): void {
		$this->dryRun = $dryRun;
		$this->report = new OriImportReport(dryRun: $dryRun);
		$this->slugs = [];
		$this->partyNames = [];
		$this->itemTitles = [];
		$this->council = ['name' => '', 'uuid' => ''];
		$this->tagged = [];
		$this->written = [];

		foreach (self::TARGET_SCHEMAS as $schema) {
			$this->tagged[$schema] = [];
			foreach ($this->records(register: self::TARGET_REGISTER, schema: $schema) as $row) {
				$tag = (string)($row['externalReference'] ?? '');
				if (str_starts_with($tag, self::TAG_PREFIX) === true && $row['id'] !== '') {
					$this->tagged[$schema][$tag] = $row['id'];
				}
			}
		}
	}//end begin()

	/**
	 * The council every member and meeting belongs to: the first organisation a meeting names.
	 *
	 * @param array<int, array<string, mixed>> $meetings The source meetings.
	 *
	 * @return void
	 */
	private function importCouncil(array $meetings): void {
		$name = self::DEFAULT_COUNCIL;
		foreach ($meetings as $record) {
			$organisation = $this->mapper->readMeeting(record: $record)['organisation'];
			if ($organisation !== null) {
				$name = $organisation;
				break;
			}
		}

		$this->council = [
			'name' => $name,
			'uuid' => $this->body(kind: 'council', name: $name, bodyType: 'legislative'),
		];
	}//end importCouncil()

	/**
	 * A body the import derives rather than reads (council, executive board, committee).
	 *
	 * @param string      $kind     council, executive or committee.
	 * @param string      $name     The body's name.
	 * @param string      $bodyType The body's type.
	 * @param string|null $parent   The parent body's uuid.
	 *
	 * @return string The body's uuid.
	 */
	private function body(string $kind, string $name, string $bodyType, ?string $parent=null): string {
		$data = ['name' => $name, 'bodyType' => $bodyType, 'domain' => self::DOMAIN];
		if ($parent !== null) {
			$data['parentBody'] = $parent;
		}

		return $this->upsert(schema: 'governance-body', tag: self::TAG_PREFIX . $kind . ':' . $name, data: $data, label: $name);
	}//end body()

	/**
	 * Political groups become bodies of type political-group under the council.
	 *
	 * @param array<int, array<string, mixed>> $records The source political groups.
	 *
	 * @return void
	 */
	private function importBodies(array $records): void {
		$schema = OriExportMapper::SOURCE_SCHEMAS['bodies'];
		foreach ($records as $record) {
			$body = $this->mapper->readBody(record: $record);
			if ($body['name'] === '') {
				$this->report->problem(schema: $schema, source: $record['id'], problem: 'has no name');
				continue;
			}

			$data = [
				'name' => $body['name'],
				'bodyType' => 'political-group',
				'domain' => self::DOMAIN,
				'parentBody' => $this->council['uuid'],
			];
			if ($body['seatCount'] !== null) {
				$data['seatCount'] = $body['seatCount'];
			}

			if ($body['coalitionRole'] !== null) {
				$data['coalitionRole'] = $body['coalitionRole'];
			} else if ($body['coalitionRoleSource'] !== null) {
				$this->report->problem(schema: $schema, source: $record['id'], problem: 'unknown classification ' . json_encode($body['coalitionRoleSource']));
			}

			$uuid = $this->upsert(schema: 'governance-body', tag: self::tag(record: $record), data: $data, label: $body['name']);
			$this->remember(schema: $schema, record: $record, uuid: $uuid);
			if ($record['slug'] !== '') {
				$this->partyNames[$record['slug']] = $body['name'];
			}
		}//end foreach
	}//end importBodies()

	/**
	 * Council members become a person, a membership of the council (or the
	 * executive board) and a membership of their political group.
	 *
	 * @param array<int, array<string, mixed>> $records     The source members.
	 * @param string                           $cutoverDate End date of an inactive member's memberships.
	 *
	 * @return void
	 */
	private function importPersons(array $records, string $cutoverDate): void {
		$schema = OriExportMapper::SOURCE_SCHEMAS['persons'];
		foreach ($records as $record) {
			$person = $this->mapper->readPerson(record: $record);
			if ($person['name'] === '') {
				$this->report->problem(schema: $schema, source: $record['id'], problem: 'has no name');
				continue;
			}

			$tag = self::tag(record: $record);
			$personUuid = $this->upsert(schema: 'person', tag: $tag, data: ['name' => $person['name']], label: $person['name']);
			$this->remember(schema: $schema, record: $record, uuid: $personUuid);

			$this->importMemberships(record: $record, person: $person, personUuid: $personUuid, cutoverDate: $cutoverDate);
		}//end foreach
	}//end importPersons()

	/**
	 * A member's membership of the council (or executive board) and of their political group.
	 *
	 * @param array<string, mixed> $record      The source member.
	 * @param array<string, mixed> $person      The member, read.
	 * @param string               $personUuid  The person written.
	 * @param string               $cutoverDate End date of an inactive member's memberships.
	 *
	 * @return void
	 */
	private function importMemberships(array $record, array $person, string $personUuid, string $cutoverDate): void {
		$schema = OriExportMapper::SOURCE_SCHEMAS['persons'];
		$tag = self::tag(record: $record);
		$ending = [];
		if ($person['active'] === false) {
			$ending = ['endDate' => $cutoverDate];
		}

		$group = null;
		if ($person['party'] !== null) {
			$group = ($this->slugs[OriExportMapper::SOURCE_SCHEMAS['bodies']][$person['party']] ?? null);
			if ($group === null) {
				$this->report->problem(schema: $schema, source: $record['id'], problem: 'political group ' . $person['party'] . ' does not exist');
			}
		}

		if ($person['role'] === null) {
			$this->report->problem(schema: $schema, source: $record['id'], problem: 'unknown role ' . json_encode($person['roleSource']));
		}

		if ($person['role'] !== null) {
			$membership = ['role' => $person['role'], 'person' => $personUuid, 'governanceBody' => $this->bodyFor(kind: (string)$person['body'])] + $ending;
			if ($group !== null) {
				$membership['party'] = $this->partyNames[(string)$person['party']];
			}

			$this->upsert(schema: 'membership', tag: $tag, data: $membership, label: $person['name']);
		}

		if ($group !== null) {
			$this->upsert(
				schema: 'membership',
				tag: $tag . '#group',
				data: ['role' => 'member', 'person' => $personUuid, 'governanceBody' => $group] + $ending,
				label: $person['name']
			);
		}
	}//end importMemberships()

	/**
	 * The council, or the executive board created on first use.
	 *
	 * @param string $kind council or executive.
	 *
	 * @return string The body's uuid.
	 */
	private function bodyFor(string $kind): string {
		if ($kind !== 'executive') {
			return $this->council['uuid'];
		}

		return $this->body(
			kind: 'executive',
			name: $this->council['name'] . ' executive board',
			bodyType: 'executive-board',
			parent: $this->council['uuid']
		);
	}//end bodyFor()

	/**
	 * Meetings, held by a committee when they name one, else by their organisation's council.
	 *
	 * @param array<int, array<string, mixed>> $records The source meetings.
	 *
	 * @return void
	 */
	private function importMeetings(array $records): void {
		$schema = OriExportMapper::SOURCE_SCHEMAS['meetings'];
		foreach ($records as $record) {
			$meeting = $this->mapper->readMeeting(record: $record);
			$refusal = null;
			if ($meeting['meetingType'] === null) {
				$refusal = 'unknown type ' . json_encode($meeting['typeSource']);
			} else if ($meeting['lifecycle'] === null) {
				$refusal = 'unknown status ' . json_encode($meeting['statusSource']);
			} else if ($meeting['scheduledDate'] === null) {
				$refusal = 'has no start date';
			}

			if ($refusal !== null) {
				$this->report->problem(schema: $schema, source: $record['id'], problem: $refusal);
				continue;
			}

			$body = $this->council['uuid'];
			if ($meeting['organisation'] !== null && $meeting['organisation'] !== $this->council['name']) {
				$body = $this->body(kind: 'council', name: $meeting['organisation'], bodyType: 'legislative');
			}

			if ($meeting['committee'] !== null) {
				$body = $this->body(kind: 'committee', name: $meeting['committee'], bodyType: 'advisory-body', parent: $body);
			}

			$data = [
				'title' => $meeting['title'],
				'meetingType' => $meeting['meetingType'],
				'lifecycle' => $meeting['lifecycle'],
				'scheduledDate' => $meeting['scheduledDate'],
				'governanceBody' => $body,
				'meetingMode' => 'in-person',
				'isPublic' => true,
			] + self::present(fields: $meeting, keys: ['endDate', 'location']);

			$uuid = $this->upsert(schema: 'meeting', tag: self::tag(record: $record), data: $data, label: $meeting['title']);
			$this->remember(schema: $schema, record: $record, uuid: $uuid);
		}//end foreach
	}//end importMeetings()

	/**
	 * Council documents become digital documents.
	 *
	 * @param array<int, array<string, mixed>> $records The source documents.
	 *
	 * @return void
	 */
	private function importDocuments(array $records): void {
		$schema = OriExportMapper::SOURCE_SCHEMAS['documents'];
		foreach ($records as $record) {
			$document = $this->mapper->readDocument(record: $record);
			if ($document['documentType'] === null) {
				$this->report->problem(schema: $schema, source: $record['id'], problem: 'unknown type ' . json_encode($document['typeSource']));
				continue;
			}

			$data = ['name' => $document['name'], 'documentType' => $document['documentType']]
				+ self::present(fields: $document, keys: ['classification', 'url', 'fileName', 'contentSize', 'encodingFormat']);

			$uuid = $this->upsert(schema: 'digital-document', tag: self::tag(record: $record), data: $data, label: $document['name']);
			$this->remember(schema: $schema, record: $record, uuid: $uuid);
		}
	}//end importDocuments()

	/**
	 * Agenda items, parents before their children.
	 *
	 * The item type decidiq requires is derived: decision when a vote was taken
	 * on the item, discussion when it has a description or documents, else
	 * informational.
	 *
	 * @param array<int, array<string, mixed>> $records The source agenda items.
	 * @param array<int, array<string, mixed>> $votes   The source votes.
	 *
	 * @return void
	 */
	private function importAgendaItems(array $records, array $votes): void {
		$schema = OriExportMapper::SOURCE_SCHEMAS['agendaItems'];
		$voted = [];
		foreach ($votes as $vote) {
			$slug = $this->mapper->readVote(record: $vote)['agendaItem'];
			if ($slug !== null) {
				$voted[$slug] = true;
			}
		}

		$items = [];
		foreach ($records as $record) {
			$items[] = ['record' => $record, 'item' => $this->mapper->readAgendaItem(record: $record)];
		}

		foreach (self::parentsFirst(items: $items) as $entry) {
			$record = $entry['record'];
			$item = $entry['item'];
			$meeting = $this->resolve(sourceRole: 'meetings', slug: $item['meeting']);
			if ($meeting === null) {
				$this->report->problem(schema: $schema, source: $record['id'], problem: 'meeting ' . json_encode($item['meeting']) . ' does not exist');
				continue;
			}

			$data = [
				'title' => $item['title'],
				'itemType' => self::itemType(item: $item, voted: isset($voted[$record['slug']])),
				'orderNumber' => $item['orderNumber'],
				'meeting' => $meeting,
			] + self::present(fields: $item, keys: ['description']) + $this->itemReferences(record: $record, item: $item);

			$uuid = $this->upsert(schema: 'agenda-item', tag: self::tag(record: $record), data: $data, label: $item['title']);
			$this->remember(schema: $schema, record: $record, uuid: $uuid);
			$this->itemTitles[$uuid] = $item['title'];
		}//end foreach
	}//end importAgendaItems()

	/**
	 * The item type decidiq requires, derived from what the source item holds.
	 *
	 * @param array<string, mixed> $item  The source item, read.
	 * @param bool                 $voted Whether a vote was taken on it.
	 *
	 * @return string decision, discussion or informational.
	 */
	private static function itemType(array $item, bool $voted): string {
		if ($voted === true) {
			return 'decision';
		}

		if ($item['description'] !== null || $item['documents'] !== []) {
			return 'discussion';
		}

		return 'informational';
	}//end itemType()

	/**
	 * An agenda item's documents and parent, resolved; each that resolves to nothing is reported.
	 *
	 * @param array<string, mixed> $record The source record.
	 * @param array<string, mixed> $item   The source item, read.
	 *
	 * @return array<string, mixed> `documents` and `parentItem`, when there are any.
	 */
	private function itemReferences(array $record, array $item): array {
		$schema = OriExportMapper::SOURCE_SCHEMAS['agendaItems'];
		$references = [];
		foreach ($item['documents'] as $slug) {
			$document = $this->resolve(sourceRole: 'documents', slug: $slug);
			if ($document === null) {
				$this->report->problem(schema: $schema, source: $record['id'], problem: 'document ' . $slug . ' does not exist');
				continue;
			}

			$references['documents'][] = $document;
		}

		if ($item['parentItem'] === null) {
			return $references;
		}

		$parent = $this->resolve(sourceRole: 'agendaItems', slug: $item['parentItem']);
		if ($parent === null) {
			$this->report->problem(schema: $schema, source: $record['id'], problem: 'parent agenda item ' . $item['parentItem'] . ' does not exist');
			return $references;
		}

		$references['parentItem'] = $parent;

		return $references;
	}//end itemReferences()

	/**
	 * Votes become a decision on the agenda item and the voting round that decided it.
	 *
	 * @param array<int, array<string, mixed>> $records The source votes.
	 *
	 * @return void
	 */
	private function importVotes(array $records): void {
		$schema = OriExportMapper::SOURCE_SCHEMAS['votes'];
		foreach ($records as $record) {
			$vote = $this->mapper->readVote(record: $record);
			$item = $this->resolve(sourceRole: 'agendaItems', slug: $vote['agendaItem']);
			$refusal = null;
			if ($item === null) {
				$refusal = 'agenda item ' . json_encode($vote['agendaItem']) . ' does not exist';
			} else if ($vote['decisionType'] === null) {
				$refusal = 'unknown type ' . json_encode($vote['typeSource']);
			} else if ($vote['outcome'] === null && $vote['resultSource'] !== null) {
				$refusal = 'unknown result ' . json_encode($vote['resultSource']);
			}

			if ($refusal !== null) {
				$this->report->problem(schema: $schema, source: $record['id'], problem: $refusal);
				continue;
			}

			$tag = self::tag(record: $record);
			$decision = [
				'title' => $vote['subject'],
				'text' => $vote['subject'],
				'decisionType' => $vote['decisionType'],
				'lifecycle' => 'decided',
				'subjectSchema' => 'agenda-item',
				'subjectId' => $item,
				'subjectLabel' => ($this->itemTitles[(string)$item] ?? ''),
			];
			$round = [
				'votingMethod' => 'for-against-abstain',
				'isSecret' => false,
				'partyResults' => $this->partyResults(record: $record, results: $vote['partyResults']),
			] + self::present(fields: $vote, keys: ['votesFor', 'votesAgainst', 'votesAbstain']);
			if ($vote['outcome'] !== null) {
				$decision['outcome'] = $vote['outcome'];
				$round['result'] = $vote['outcome'];
			}

			$round['subjectDecision'] = $this->upsert(schema: 'decision', tag: $tag, data: $decision, label: $vote['subject']);
			$this->upsert(schema: 'voting-round', tag: $tag, data: $round, label: $vote['subject']);
		}//end foreach
	}//end importVotes()

	/**
	 * Party results as recorded: the party's body when it is known, else its name as given.
	 *
	 * @param array<string, mixed>             $record  The source vote.
	 * @param array<int, array<string, mixed>> $results The party results read from it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function partyResults(array $record, array $results): array {
		$rows = [];
		foreach ($results as $result) {
			$row = [];
			if ($result['party'] !== null) {
				$row['party'] = ($this->resolve(sourceRole: 'bodies', slug: $result['party']) ?? $result['party']);
			}

			if ($result['value'] !== null) {
				$row['value'] = $result['value'];
			}

			if ($result['value'] === null) {
				$this->report->problem(
					schema: OriExportMapper::SOURCE_SCHEMAS['votes'],
					source: $record['id'],
					problem: 'unknown party vote ' . json_encode($result['valueSource'])
				);
			}

			if ($result['seatCount'] !== null) {
				$row['seatCount'] = $result['seatCount'];
			}

			$rows[] = $row;
		}

		return $rows;
	}//end partyResults()

	/**
	 * Create the tagged object, or update it when a run already wrote it.
	 *
	 * @param string               $schema The target schema.
	 * @param string               $tag    The import tag.
	 * @param array<string, mixed> $data   The object.
	 * @param string               $label  A readable name for the report.
	 *
	 * @return string The object's uuid; in a dry run a placeholder when it does not exist yet.
	 */
	private function upsert(string $schema, string $tag, array $data, string $label): string {
		$existing = ($this->tagged[$schema][$tag] ?? null);
		if ($existing !== null && isset($this->written[$schema][$tag]) === true) {
			// A derived body (the council, a committee) is reached once per meeting or member; write it once.
			return $existing;
		}

		$action = 'updated';
		if ($existing === null) {
			$action = 'created';
		}
		$data['externalReference'] = $tag;

		$uuid = ($existing ?? 'dry-run:' . $tag);
		if ($this->dryRun === false) {
			$saved = $this->objectService->saveObject(
				object: $data,
				register: self::TARGET_REGISTER,
				schema: $schema,
				uuid: $existing,
				_rbac: false,
				_multitenancy: false
			);
			$uuid = (string)$saved->getUuid();
		}

		$this->tagged[$schema][$tag] = $uuid;
		$this->written[$schema][$tag] = true;
		$this->report->wrote(action: $action, schema: $schema, reference: $tag, label: $label);

		return $uuid;
	}//end upsert()

	/**
	 * Remember which uuid a source slug was written to.
	 *
	 * @param string               $schema The source schema.
	 * @param array<string, mixed> $record The source record.
	 * @param string               $uuid   The uuid written.
	 *
	 * @return void
	 */
	private function remember(string $schema, array $record, string $uuid): void {
		if ($record['slug'] !== '') {
			$this->slugs[$schema][$record['slug']] = $uuid;
		}
	}//end remember()

	/**
	 * The uuid a source slug was written to, or null.
	 *
	 * @param string      $sourceRole The source schema's role in OriExportMapper::SOURCE_SCHEMAS.
	 * @param string|null $slug       The slug.
	 *
	 * @return string|null
	 */
	private function resolve(string $sourceRole, ?string $slug): ?string {
		if ($slug === null) {
			return null;
		}

		return ($this->slugs[OriExportMapper::SOURCE_SCHEMAS[$sourceRole]][$slug] ?? null);
	}//end resolve()

	/**
	 * Every object of one schema in one register, as arrays with `id` and `slug`.
	 *
	 * @param string $register The register's slug.
	 * @param string $schema   The schema's slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function records(string $register, string $schema): array {
		$rows = $this->objectService->findAll(
			config: ['filters' => ['register' => $register, 'schema' => $schema]],
			_rbac: false,
			_multitenancy: false
		);

		$records = [];
		foreach ($rows as $row) {
			if ($row instanceof ObjectEntityInterface) {
				$row = ['id' => (string)$row->getUuid()] + $row->getObject();
			}

			$row = (array)$row;
			$self = (array)($row['@self'] ?? []);
			$row['id'] = (string)($row['id'] ?? ($row['uuid'] ?? ($self['id'] ?? '')));
			$row['slug'] = (string)($row['slug'] ?? ($self['slug'] ?? ''));
			$records[] = $row;
		}

		return $records;
	}//end records()

	/**
	 * The import tag of a source record.
	 *
	 * @param array<string, mixed> $record The source record.
	 *
	 * @return string
	 */
	private static function tag(array $record): string {
		return self::TAG_PREFIX . $record['id'];
	}//end tag()

	/**
	 * The named fields that carry a value.
	 *
	 * @param array<string, mixed> $fields The fields.
	 * @param array<int, string>   $keys   The names to keep.
	 *
	 * @return array<string, mixed>
	 */
	private static function present(array $fields, array $keys): array {
		$present = [];
		foreach ($keys as $key) {
			if (($fields[$key] ?? null) !== null) {
				$present[$key] = $fields[$key];
			}
		}

		return $present;
	}//end present()

	/**
	 * Agenda items ordered so a parent comes before its children; an item whose
	 * parent never appears keeps its place at the end.
	 *
	 * @param array<int, array{record: array<string, mixed>, item: array<string, mixed>}> $items The items.
	 *
	 * @return array<int, array{record: array<string, mixed>, item: array<string, mixed>}>
	 */
	private static function parentsFirst(array $items): array {
		$ordered = [];
		$placed = [];
		$pending = $items;
		do {
			$progress = false;
			foreach ($pending as $index => $entry) {
				$parent = $entry['item']['parentItem'];
				if ($parent === null || isset($placed[$parent]) === true) {
					$ordered[] = $entry;
					$placed[$entry['record']['slug']] = true;
					unset($pending[$index]);
					$progress = true;
				}
			}
		} while ($progress === true && $pending !== []);

		return array_merge($ordered, array_values($pending));
	}//end parentsFirst()
}//end class
