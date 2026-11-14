<?php

/**
 * Decidiq Meeting Package Service
 *
 * Assembles the documents linked to a meeting's agenda items into a
 * structured "Meeting package" folder (vergaderstukken) inside the
 * meeting's Files folder tree, with a generated table of contents
 * (agenda-management "Agenda Document Package" requirement).
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
 * @spec openspec/specs/agenda-management/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Kernel\Contracts\MeetingPackageServiceInterface;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the per-meeting document package.
 *
 * Reuses MeetingFolderService (nextcloud-integration spec) to locate /
 * create the meeting's folder tree, then assembles a "Meeting package"
 * subtree: one `NN - <item title>/` folder per agenda item containing
 * copies of the item's linked documents, plus a generated markdown table
 * of contents. The folder lives in normal Nextcloud Files, so download
 * and distribution via convocation fall out of the platform (share link).
 *
 * File copying is defensive: unresolvable or unreadable documents are
 * reported in `skipped` instead of failing the assembly, because
 * OpenRegister attachment serialization differs across versions.
 *
 * @spec openspec/specs/agenda-management/spec.md
 */
class MeetingPackageService implements MeetingPackageServiceInterface {

	/**
	 * Name of the package subfolder created under the meeting folder.
	 *
	 * @var string
	 */
	public const PACKAGE_FOLDER = 'Meeting package';

	/**
	 * Name of the generated table-of-contents file.
	 *
	 * @var string
	 */
	public const TOC_FILENAME = '00 - Table of contents.md';

	/**
	 * Constructor for MeetingPackageService.
	 *
	 * @param ContainerInterface $container DI container (lazy-loads OpenRegister's ObjectService + FileService)
	 * @param LoggerInterface $logger The logger
	 * @param MeetingFolderService $meetingFolderService Creates / locates the meeting's Files folder tree
	 * @param PaperStamper $stamper Stamps a paper for the person who takes the package
	 * @param IUserManager $userManager Names that person on the stamp
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly MeetingFolderService $meetingFolderService,
		private readonly PaperStamper $stamper,
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * Assemble the meeting document package.
	 *
	 * Loads the meeting via ObjectService (OpenRegister RBAC: callers
	 * without read access get null → "not found"), collects its agenda
	 * items sorted by orderNumber, ensures the meeting folder exists, and
	 * builds the package subtree with the table of contents.
	 *
	 * @param string $meetingId UUID of the meeting
	 * @param string $userId Acting user UID (logged with the assembly)
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return array{success: bool, path: string|null, items: int, files: int, skipped: array<int, string>, message: string}
	 */
	public function assemble(string $meetingId, string $userId): array {
		$loaded = $this->loadMeeting(meetingId: $meetingId);
		if (isset($loaded['failure']) === true) {
			return $loaded['failure'];
		}

		$items = $this->collectAgendaItems(objectService: $loaded['objectService'], meetingId: $meetingId);
		$meetingPath = $this->meetingFolderService->ensureMeetingFolders(meeting: array_merge($loaded['meeting'], ['id' => $meetingId]));
		if ($meetingPath === null) {
			return $this->failure(message: 'Could not create the meeting folder (Files unavailable).', result: ['items' => count($items)]);
		}

		$package = ['meeting' => $loaded['meeting'], 'items' => $items, 'meetingPath' => $meetingPath, 'meetingId' => $meetingId, 'userId' => $userId];
		$package['stamp'] = $this->stampFor(meeting: $loaded['meeting'], userId: $userId);
		return $this->writePackage(package: $package);

	}//end assemble()

	/**
	 * The stamp the package's papers get: the paper as it is when the
	 * meeting's policy does not ask for one, else stamped for the person who
	 * takes the package. A paper that must be stamped and cannot be is
	 * thrown out of the package (the writer lists it as skipped), never
	 * copied unstamped.
	 *
	 * @param array<string, mixed> $meeting The meeting
	 * @param string               $userId  The person who takes the package
	 *
	 * @return \Closure(array<string, mixed>, object, string): string
	 *
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-003-the-meeting-package-is-stamped-for-the-person-who-takes-it
	 */
	private function stampFor(array $meeting, string $userId): \Closure {
		$user   = $this->userManager->get($userId);
		$reader = ['uid' => $userId, 'displayName' => $userId];
		if ($user !== null) {
			$reader['displayName'] = $user->getDisplayName();
		}

		return function (array $item, object $node, string $content) use ($meeting, $reader): string {
			$itemId = (string)($item['id'] ?? ($item['@self']['id'] ?? ''));
			if ($this->stamper->mustStamp(meeting: $meeting, agendaItemId: $itemId) === false) {
				return $content;
			}

			$paper = [
				'fileId'  => (int)$node->getId(),
				'etag'    => (string)$node->getEtag(),
				'content' => $content,
			];
			return $this->stamper->stamped(meeting: $meeting, paper: $paper, reader: $reader);
		};

	}//end stampFor()

	/**
	 * Load the meeting through ObjectService.
	 *
	 * @param string $meetingId UUID of the meeting
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return array<string, mixed> The objectService and meeting, or ['failure' => envelope]
	 */
	private function loadMeeting(string $meetingId): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$entity = $objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting');
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: MeetingPackageService::assemble lookup failed', ['meetingId' => $meetingId, 'exception' => $e->getMessage()]);
			return ['failure' => $this->failure(message: 'Failed to load meeting.')];
		}

		if ($entity === null) {
			return ['failure' => $this->failure(message: 'Meeting not found.')];
		}

		return ['objectService' => $objectService, 'meeting' => $this->toMeetingArray(entity: $entity)];

	}//end loadMeeting()

	/**
	 * Build the failure envelope every unhappy path in assemble() returns.
	 *
	 * @param string $message Human-readable failure message
	 * @param array<string, mixed> $result What is known so far: path, items, files, skipped
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return array{success: bool, path: string|null, items: int, files: int, skipped: array<int, string>, message: string}
	 */
	private function failure(string $message, array $result = []): array {
		return [
			'success' => false,
			'path' => ($result['path'] ?? null),
			'items' => ($result['items'] ?? 0),
			'files' => ($result['files'] ?? 0),
			'skipped' => ($result['skipped'] ?? []),
			'message' => $message,
		];

	}//end failure()

	/**
	 * Read a meeting entity as a plain array, preferring getObject() when the
	 * ObjectService entity exposes it.
	 *
	 * @param object $entity Meeting entity returned by ObjectService::find()
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return array<string, mixed>
	 */
	private function toMeetingArray(object $entity): array {
		if (method_exists($entity, 'getObject') === true) {
			return (array)$entity->getObject();
		}

		return (array)$entity->jsonSerialize();
	}//end toMeetingArray()

	/**
	 * Write the package subtree: the table of contents plus one folder per
	 * agenda item holding that item's documents.
	 *
	 * @param array<string, mixed> $package The meeting, its items, meetingPath, meetingId and userId
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return array{success: bool, path: string|null, items: int, files: int, skipped: array<int, string>, message: string}
	 */
	private function writePackage(array $package): array {
		$package['packagePath'] = $package['meetingPath'] . '/' . self::PACKAGE_FOLDER;
		$package['toc'] = $this->buildTableOfContents(meeting: $package['meeting'], items: $package['items']);
		$writer = new MeetingPackageWriter(logger: $this->logger);
		try {
			$writer->write(fileService: $this->container->get('OCA\OpenRegister\Service\FileService'), package: $package);
		} catch (\Throwable $e) {
			$this->logger->error('Decidiq: meeting package assembly failed', ['meetingId' => $package['meetingId'], 'exception' => $e->getMessage()]);
			$result = ['path' => $package['meetingPath'], 'items' => count($package['items'])] + $writer->tally();
			return $this->failure(message: 'Package assembly failed. See server log for details.', result: $result);
		}

		return $this->assembled(package: $package, tally: $writer->tally());

	}//end writePackage()

	/**
	 * Log the assembled package and build the successful envelope.
	 *
	 * @param array<string, mixed> $package The package (items, packagePath, meetingId, userId)
	 * @param array{files: int, skipped: string[]} $tally The files written and the files skipped
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return array{success: bool, path: string, items: int, files: int, skipped: string[], message: string}
	 */
	private function assembled(array $package, array $tally): array {
		$result = ['path' => $package['packagePath'], 'items' => count($package['items'])] + $tally;
		$context = ['meetingId' => $package['meetingId'], 'userId' => $package['userId']] + array_merge($result, ['skipped' => count($tally['skipped'])]);
		$this->logger->info('Decidiq: meeting package assembled', $context);
		$skippedSuffix = '';
		if (count($tally['skipped']) > 0) {
			$skippedSuffix = sprintf(', %d skipped', count($tally['skipped']));
		}

		$message = sprintf('Meeting package assembled: %d agenda item(s), %d document(s)%s.', $result['items'], $tally['files'], $skippedSuffix);
		return ['success' => true] + $result + ['message' => $message];

	}//end assembled()

	/**
	 * Build the markdown table of contents for a meeting package.
	 *
	 * Pure (unit-tested): a meeting header followed by the agenda items in
	 * orderNumber order, each with its document list, organized by agenda
	 * item number and title per the spec scenario.
	 *
	 * @param array<string, mixed> $meeting Meeting payload (title, scheduledDate)
	 * @param array<int, array<string, mixed>> $items Agenda items sorted by orderNumber
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return string Markdown TOC content
	 */
	public function buildTableOfContents(array $meeting, array $items): string {
		$lines = [$this->tocHeader(meeting: $meeting), '', 'Meeting package (vergaderstukken) — table of contents.', ''];
		$number = 0;
		foreach ($items as $item) {
			$number++;
			array_push($lines, ...$this->tocLinesForItem(number: $number, item: $item));
		}

		if ($number === 0) {
			$lines[] = '_No agenda items._';
		}

		return implode("\n", $lines) . "\n";
	}//end buildTableOfContents()

	/**
	 * The table of contents' title line: the meeting title and its date.
	 *
	 * @param array<string, mixed> $meeting Meeting payload
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return string
	 */
	private function tocHeader(array $meeting): string {
		$header = '# ' . (string)($meeting['title'] ?? 'Meeting');
		$date = substr((string)($meeting['scheduledDate'] ?? ''), 0, 10);
		if ($date !== '') {
			$header .= ' — ' . $date;
		}

		return $header;

	}//end tocHeader()

	/**
	 * Render the table-of-contents lines for a single agenda item: its
	 * numbered title, the optional meta line, and one line per attached file.
	 *
	 * @param int $number Sequential item number (1-based)
	 * @param array<string, mixed> $item Agenda item payload
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return array<int, string> Markdown lines for this item
	 */
	private function tocLinesForItem(int $number, array $item): array {
		$lines = [sprintf('%02d. %s', $number, (string)($item['title'] ?? 'Agenda item'))];

		$metaBits = $this->tocMetaBits(item: $item);
		if (count($metaBits) > 0) {
			$lines[] = '    (' . implode(', ', $metaBits) . ')';
		}

		foreach ((array)($item['files'] ?? []) as $file) {
			$fileName = $this->resolveFileLabel(file: $file);
			if ($fileName !== '') {
				$lines[] = '    - ' . basename($fileName);
			}
		}

		return $lines;
	}//end tocLinesForItem()

	/**
	 * Collect the optional meta descriptors (type, estimated duration) shown
	 * under an agenda item in the table of contents.
	 *
	 * @param array<string, mixed> $item Agenda item payload
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return array<int, string> Meta descriptors, possibly empty
	 */
	private function tocMetaBits(array $item): array {
		$metaBits = [];

		$itemType = (string)($item['itemType'] ?? '');
		if ($itemType !== '') {
			$metaBits[] = $itemType;
		}

		$duration = ($item['estimatedDuration'] ?? null);
		if ($duration !== null) {
			$metaBits[] = $duration . ' min';
		}

		return $metaBits;
	}//end tocMetaBits()

	/**
	 * Resolve the displayable name of an attached file reference, which may be
	 * a plain string or a `{name|title|path}` array.
	 *
	 * @param mixed $file A single entry of the agenda item's `files` list
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return string The file label, or '' when none can be resolved
	 */
	private function resolveFileLabel(mixed $file): string {
		if (is_array($file) === true) {
			return (string)($file['name'] ?? ($file['title'] ?? ($file['path'] ?? '')));
		}

		if (is_string($file) === true) {
			return $file;
		}

		return '';
	}//end resolveFileLabel()

	/**
	 * Collect the meeting's agenda items sorted by orderNumber.
	 *
	 * @param object $objectService OpenRegister ObjectService
	 * @param string $meetingId UUID of the meeting
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return array<int, array<string, mixed>> Agenda item payloads
	 */
	private function collectAgendaItems(object $objectService, string $meetingId): array {
		$items = [];
		foreach ($this->agendaRows(objectService: $objectService, meetingId: $meetingId) as $row) {
			$row = $this->rowArray(row: $row);
			if ($row !== null && $this->belongsTo(row: $row, meetingId: $meetingId) === true) {
				$items[] = $this->withoutClosedPapers(item: $row);
			}
		}

		usort($items, static fn (array $a, array $b): int => ((int)($a['orderNumber'] ?? 0)) <=> ((int)($b['orderNumber'] ?? 0)));
		return $items;
	}//end collectAgendaItems()

	/**
	 * A non-public agenda item as it may appear in the pack: listed as
	 * "Besloten punt", without its id, title or papers, so nothing of it is
	 * copied. A public item is returned unchanged.
	 *
	 * @param array<string, mixed> $item Agenda item row
	 *
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md
	 *
	 * @return array<string, mixed>
	 */
	private function withoutClosedPapers(array $item): array {
		if (($item['public'] ?? true) !== false) {
			return $item;
		}

		return ['title' => 'Besloten punt', 'orderNumber' => ($item['orderNumber'] ?? 0), 'public' => false, 'files' => []];

	}//end withoutClosedPapers()

	/**
	 * The agenda item rows of a meeting; none (logged as a warning) when the query fails.
	 *
	 * @param object $objectService OpenRegister ObjectService
	 * @param string $meetingId UUID of the meeting
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return array<int|string, mixed>
	 */
	private function agendaRows(object $objectService, string $meetingId): array {
		try {
			$query = ['register' => 'decidiq', 'schema' => 'agenda-item', 'filters' => ['meeting' => $meetingId], 'limit' => 500];
			return (array)$objectService->findAll($query);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: package agenda-item lookup failed', ['meetingId' => $meetingId, 'exception' => $e->getMessage()]);
			return [];
		}

	}//end agendaRows()

	/**
	 * A row as an array, or null when it is neither an array nor serialisable.
	 *
	 * @param mixed $row The row
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return array<string, mixed>|null
	 */
	private function rowArray(mixed $row): ?array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = (array)$row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return null;
		}

		return $row;

	}//end rowArray()

	/**
	 * Whether a row belongs to the meeting: rows without the relation in the
	 * payload are kept, rows linked to another meeting are not.
	 *
	 * @param array<string, mixed> $row The agenda item row
	 * @param string $meetingId UUID of the meeting
	 *
	 * @spec openspec/specs/agenda-management/spec.md
	 *
	 * @return bool
	 */
	private function belongsTo(array $row, string $meetingId): bool {
		$linked = $row['meeting'] ?? null;
		if (is_array($linked) === true) {
			$linked = ($linked['id'] ?? null);
		}

		return $linked === null || (string)$linked === $meetingId;

	}//end belongsTo()

}//end class
