<?php

/**
 * Delivers a body's induction documents into a joining member's own Files.
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
 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeInterface;
use OCA\Decidiq\Service\Pathway\PathwayResult;
use OCA\Decidiq\Service\Pathway\PathwaySteps;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * The body lists its induction documents (`GovernanceBody.inductionDocuments`):
 * files and links. Delivery makes one folder in the member's Files, copies
 * each file into it and writes the links into one Links.md, the way the
 * meeting package is assembled. A file is looked up in the Files of whoever
 * confirms the delivery, so nobody can hand out a document they cannot open
 * themselves. An item that cannot be delivered is listed as skipped and the
 * rest still goes; when nothing at all could be delivered the step stays
 * open. The step's reference is the folder.
 *
 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
 */
class InductionPackService {

	/**
	 * The onboarding step this service completes.
	 *
	 * @var string
	 */
	public const STEP = 'induction-pack';

	/**
	 * The file the links are written to.
	 *
	 * @var string
	 */
	public const LINKS_FILE = 'Links.md';

	/**
	 * Reads and completes the checklist steps on a pathway record.
	 *
	 * @var PathwaySteps
	 */
	private readonly PathwaySteps $pathwaySteps;

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store       The register's objects.
	 * @param IRootFolder         $rootFolder  Nextcloud Files.
	 * @param IUserManager        $userManager The Nextcloud user manager.
	 * @param ITimeFactory        $time        The clock.
	 * @param LoggerInterface     $logger      The logger.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
		private readonly IRootFolder $rootFolder,
		private readonly IUserManager $userManager,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
		$this->pathwaySteps = new PathwaySteps();
	}//end __construct()

	/**
	 * Deliver the body's induction documents to the joining member.
	 *
	 * @param string $onboardingId The member onboarding uuid.
	 * @param string $actor        The uid confirming the delivery.
	 *
	 * @return PathwayResult
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
	 */
	public function deliver(string $onboardingId, string $actor): PathwayResult {
		$open = $this->openStep(onboardingId: $onboardingId);
		if ($open instanceof PathwayResult) {
			return $open;
		}

		$pack = $this->packFor(record: $open['record']);
		if ($pack instanceof PathwayResult) {
			return $pack;
		}

		try {
			$folder = $this->folderFor(uid: $pack['uid'], name: $this->folderName(body: $pack['body']));
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: could not create the induction folder', ['exception' => $e]);
			return new PathwayResult(outcome: PathwayResult::FAILED, message: 'The folder could not be created in the member\'s Files.');
		}

		$tally = $this->copyAll(target: ['folder' => $folder, 'actor' => $actor], items: $pack['items']);
		if ($tally['delivered'] === []) {
			return new PathwayResult(
				outcome: PathwayResult::FAILED,
				message: 'Nothing could be delivered. Skipped: ' . implode('; ', $tally['skipped']) . '.'
			);
		}

		$path = '/' . $folder->getName();
		$note = $this->noteFor(path: $path, tally: $tally);
		$this->completeStep(open: $open, done: ['by' => $actor, 'note' => $note, 'reference' => $path]);

		return new PathwayResult(
			outcome: PathwayResult::DONE,
			message: $note,
			details: ['folder' => $path, 'delivered' => $tally['delivered'], 'skipped' => $tally['skipped']]
		);
	}//end deliver()

	/**
	 * The step note: how many items went where, and what was skipped.
	 *
	 * @param string                                                $path  The folder path in the member's Files.
	 * @param array{delivered: list<string>, skipped: list<string>} $tally The tally.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
	 */
	private function noteFor(string $path, array $tally): string {
		$note = sprintf('Delivered %d item(s) to %s.', count($tally['delivered']), $path);
		if ($tally['skipped'] !== []) {
			$note .= ' Skipped: ' . implode('; ', $tally['skipped']) . '.';
		}

		return $note;
	}//end noteFor()

	/**
	 * Complete the induction pack step.
	 *
	 * @param array{uuid: string, steps: list<array<string,mixed>>, index: int} $open The open step.
	 * @param array{by: string, note: string, reference: string}              $done Who completed it, the note and the folder.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
	 */
	private function completeStep(array $open, array $done): void {
		$this->store->patch(
			schema: OnboardingProvisioningService::ONBOARDING,
			data: [
				'steps' => $this->pathwaySteps->complete(
					steps: $open['steps'],
					index: $open['index'],
					completedBy: $done['by'],
					completedAt: $this->time->getDateTime()->format(DateTimeInterface::ATOM),
					note: $done['note'],
					reference: $done['reference']
				),
			],
			uuid: $open['uuid']
		);
	}//end completeStep()

	/**
	 * The onboarding and its open induction pack step, or why there is none.
	 *
	 * @param string $onboardingId The onboarding uuid.
	 *
	 * @return array{uuid: string, record: array<string,mixed>, steps: list<array<string,mixed>>, index: int}|PathwayResult
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
	 */
	private function openStep(string $onboardingId): array|PathwayResult {
		$record = $this->store->find(schema: OnboardingProvisioningService::ONBOARDING, uuid: $onboardingId);
		if ($record === null) {
			return new PathwayResult(outcome: PathwayResult::NOT_FOUND, message: 'Onboarding not found.');
		}

		$steps = $this->pathwaySteps->listOf(record: $record);
		$index = $this->pathwaySteps->indexOf(steps: $steps, stepType: self::STEP);
		if ($index === null) {
			return new PathwayResult(outcome: PathwayResult::REFUSED, message: 'This onboarding has no induction pack step.');
		}

		if ($this->pathwaySteps->isCompleted(steps: $steps, index: $index) === true) {
			return new PathwayResult(outcome: PathwayResult::REFUSED, message: 'The induction pack has already been delivered.');
		}

		return ['uuid' => $onboardingId, 'record' => $record, 'steps' => $steps, 'index' => $index];
	}//end openStep()

	/**
	 * The member's account, the body and its induction items, or why the pack cannot go.
	 *
	 * @param array<string,mixed> $record The onboarding.
	 *
	 * @return array{uid: string, body: array<string,mixed>, items: list<array{title: string, fileId: int|null, url: string|null}>}|PathwayResult
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
	 */
	private function packFor(array $record): array|PathwayResult {
		$uid = trim((string) ($record['ncAccount'] ?? ''));
		if ($uid === '' || $this->userManager->get($uid) === null) {
			return new PathwayResult(
				outcome: PathwayResult::REFUSED,
				message: 'Link the member to a Nextcloud account first (the account linking step).'
			);
		}

		$body  = ($this->store->find(schema: 'governance-body', uuid: $this->pathwaySteps->relationId(value: ($record['targetBody'] ?? null))) ?? []);
		$items = $this->items(body: $body);
		if ($items === []) {
			return new PathwayResult(outcome: PathwayResult::REFUSED, message: 'No induction documents are configured for this body.');
		}

		return ['uid' => $uid, 'body' => $body, 'items' => $items];
	}//end packFor()

	/**
	 * The body's induction items with a title and a file or a link.
	 *
	 * @param array<string,mixed> $body The governance body.
	 *
	 * @return list<array{title: string, fileId: int|null, url: string|null}>
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
	 */
	private function items(array $body): array {
		$listed = ($body['inductionDocuments'] ?? []);
		if (is_array($listed) === false) {
			return [];
		}

		$items = [];
		foreach ($listed as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$items[] = $this->item(item: $item);
		}

		return $items;
	}//end items()

	/**
	 * One induction item with its title, file id and link, each cleaned.
	 *
	 * @param array<string,mixed> $item The configured item.
	 *
	 * @return array{title: string, fileId: int|null, url: string|null}
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
	 */
	private function item(array $item): array {
		$fileId = null;
		if (is_numeric($item['fileId'] ?? null) === true) {
			$fileId = (int) $item['fileId'];
		}

		$url = null;
		if (is_string($item['url'] ?? null) === true && trim($item['url']) !== '') {
			$url = trim($item['url']);
		}

		return ['title' => trim((string) ($item['title'] ?? '')), 'fileId' => $fileId, 'url' => $url];
	}//end item()

	/**
	 * Copy every file, write the links, and tally what went and what was skipped.
	 *
	 * @param array{folder: Folder, actor: string}                            $target The induction folder and the reading user.
	 * @param list<array{title: string, fileId: int|null, url: string|null}> $items  The items.
	 *
	 * @return array{delivered: list<string>, skipped: list<string>}
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
	 */
	private function copyAll(array $target, array $items): array {
		$tally = ['delivered' => [], 'skipped' => []];
		$links = [];
		foreach ($items as $item) {
			if ($item['fileId'] === null && $item['url'] !== null) {
				$links[] = '- [' . $this->labelOr(label: $item['title'], fallback: $item['url']) . '](' . $item['url'] . ')';
				continue;
			}

			[$outcome, $label] = $this->copyItem(target: $target, item: $item);
			$tally[$outcome][] = $label;
		}

		if ($links === []) {
			return $tally;
		}

		try {
			$this->writeFile(folder: $target['folder'], name: self::LINKS_FILE, content: "# Links\n\n" . implode("\n", $links) . "\n");
			$tally['delivered'][] = self::LINKS_FILE;
		} catch (\Throwable $e) {
			$tally['skipped'][] = self::LINKS_FILE . ' (' . $e->getMessage() . ')';
		}

		return $tally;
	}//end copyAll()

	/**
	 * Copy one item that is not a link, and say whether it went.
	 *
	 * @param array{folder: Folder, actor: string}                     $target The member's induction folder, and whose Files the file is read from.
	 * @param array{title: string, fileId: int|null, url: string|null} $item   The item.
	 *
	 * @return array{0: 'delivered'|'skipped', 1: string} The outcome and the label to tally.
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
	 */
	private function copyItem(array $target, array $item): array {
		if ($item['fileId'] === null) {
			return ['skipped', $this->labelOr(label: $item['title'], fallback: 'untitled item') . ' (no file or link)'];
		}

		$label  = $this->labelOr(label: $item['title'], fallback: (string) $item['fileId']);
		$copied = $this->copyFile(target: $target, fileId: $item['fileId']);
		if ($copied === null) {
			return ['delivered', $label];
		}

		return ['skipped', $label . ' (' . $copied . ')'];
	}//end copyItem()

	/**
	 * Copy one file into the folder.
	 *
	 * @param array{folder: Folder, actor: string} $target The member's induction folder, and whose Files the source is read from.
	 * @param int                                  $fileId The source file id.
	 *
	 * @return string|null Null when copied, else why it was skipped.
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
	 */
	private function copyFile(array $target, int $fileId): ?string {
		try {
			$nodes = $this->rootFolder->getUserFolder($target['actor'])->getById($fileId);
		} catch (\Throwable $e) {
			return 'not readable';
		}

		foreach ($nodes as $node) {
			if ($node instanceof File === false) {
				continue;
			}

			try {
				$this->writeFile(folder: $target['folder'], name: $node->getName(), content: $node->getContent());
				return null;
			} catch (\Throwable $e) {
				return $e->getMessage();
			}
		}

		return 'no longer exists';
	}//end copyFile()

	/**
	 * Write a file, replacing it when it is already there (a delivery run again).
	 *
	 * @param Folder $folder  The folder.
	 * @param string $name    The file name.
	 * @param string $content The content.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
	 */
	private function writeFile(Folder $folder, string $name, string $content): void {
		if ($folder->nodeExists($name) === true) {
			$existing = $folder->get($name);
			if ($existing instanceof File === true) {
				$existing->putContent($content);
				return;
			}
		}

		$folder->newFile($name, $content);
	}//end writeFile()

	/**
	 * The member's induction folder, made when it is not there yet.
	 *
	 * @param string $uid  The member's uid.
	 * @param string $name The folder name.
	 *
	 * @return Folder
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
	 */
	private function folderFor(string $uid, string $name): Folder {
		$home = $this->rootFolder->getUserFolder($uid);
		if ($home->nodeExists($name) === true) {
			$existing = $home->get($name);
			if ($existing instanceof Folder === true) {
				return $existing;
			}
		}

		return $home->newFolder($name);
	}//end folderFor()

	/**
	 * The folder name: "Welcome - <body name>", safe for a file name.
	 *
	 * @param array<string,mixed> $body The governance body.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
	 */
	private function folderName(array $body): string {
		$name = trim(str_replace(['/', '\\'], '-', (string) ($body['name'] ?? '')));
		if ($name === '') {
			return 'Welcome';
		}

		return 'Welcome - ' . $name;
	}//end folderName()

	/**
	 * The label, or a fallback when it is empty.
	 *
	 * @param string $label    The label.
	 * @param string $fallback The fallback.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-24-member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
	 */
	private function labelOr(string $label, string $fallback): string {
		if ($label === '') {
			return $fallback;
		}

		return $label;
	}//end labelOr()

}//end class
