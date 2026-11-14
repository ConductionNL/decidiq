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
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
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
 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
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
	}//end __construct()

	/**
	 * Deliver the body's induction documents to the joining member.
	 *
	 * @param string $onboardingId The member onboarding uuid.
	 * @param string $actor        The uid confirming the delivery.
	 *
	 * @return PathwayResult
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
	 */
	public function deliver(string $onboardingId, string $actor): PathwayResult {
		$record = $this->store->find(schema: OnboardingProvisioningService::ONBOARDING, uuid: $onboardingId);
		if ($record === null) {
			return PathwayResult::notFound(message: 'Onboarding not found.');
		}

		$steps = PathwaySteps::of(record: $record);
		$index = PathwaySteps::indexOf(steps: $steps, stepType: self::STEP);
		if ($index === null) {
			return PathwayResult::refused(message: 'This onboarding has no induction pack step.');
		}

		if (PathwaySteps::isCompleted(steps: $steps, index: $index) === true) {
			return PathwayResult::refused(message: 'The induction pack has already been delivered.');
		}

		$uid = trim((string) ($record['ncAccount'] ?? ''));
		if ($uid === '' || $this->userManager->get($uid) === null) {
			return PathwayResult::refused(
				message: 'Link the member to a Nextcloud account first (the account linking step).'
			);
		}

		$body  = $this->store->find(schema: 'governance-body', uuid: PathwaySteps::relationId(value: ($record['targetBody'] ?? null)));
		$items = $this->items(body: ($body ?? []));
		if ($items === []) {
			return PathwayResult::refused(message: 'No induction documents are configured for this body.');
		}

		try {
			$folder = $this->folderFor(uid: $uid, name: $this->folderName(body: ($body ?? [])));
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: could not create the induction folder', ['exception' => $e]);
			return PathwayResult::failed(message: 'The folder could not be created in the member\'s Files.');
		}

		$tally = $this->copyAll(folder: $folder, items: $items, actor: $actor);
		if ($tally['delivered'] === []) {
			return PathwayResult::failed(
				message: 'Nothing could be delivered. Skipped: ' . implode('; ', $tally['skipped']) . '.'
			);
		}

		$path = '/' . $folder->getName();
		$note = sprintf('Delivered %d item(s) to %s.', count($tally['delivered']), $path);
		if ($tally['skipped'] !== []) {
			$note .= ' Skipped: ' . implode('; ', $tally['skipped']) . '.';
		}

		$this->store->patch(
			schema: OnboardingProvisioningService::ONBOARDING,
			data: [
				'steps' => PathwaySteps::complete(
					steps: $steps,
					index: $index,
					completedBy: $actor,
					completedAt: $this->time->getDateTime()->format(DateTimeInterface::ATOM),
					note: $note,
					reference: $path
				),
			],
			uuid: $onboardingId
		);

		return PathwayResult::done(
			message: $note,
			details: ['folder' => $path, 'delivered' => $tally['delivered'], 'skipped' => $tally['skipped']]
		);
	}//end deliver()

	/**
	 * The body's induction items with a title and a file or a link.
	 *
	 * @param array<string,mixed> $body The governance body.
	 *
	 * @return list<array{title: string, fileId: int|null, url: string|null}>
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
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

			$fileId = null;
			if (is_numeric($item['fileId'] ?? null) === true) {
				$fileId = (int) $item['fileId'];
			}

			$url = null;
			if (is_string($item['url'] ?? null) === true && trim($item['url']) !== '') {
				$url = trim($item['url']);
			}

			$items[] = ['title' => trim((string) ($item['title'] ?? '')), 'fileId' => $fileId, 'url' => $url];
		}

		return $items;
	}//end items()

	/**
	 * Copy every file, write the links, and tally what went and what was skipped.
	 *
	 * @param Folder                                                         $folder The member's induction folder.
	 * @param list<array{title: string, fileId: int|null, url: string|null}> $items  The items.
	 * @param string                                                         $actor  Whose Files the files are read from.
	 *
	 * @return array{delivered: list<string>, skipped: list<string>}
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
	 */
	private function copyAll(Folder $folder, array $items, string $actor): array {
		$delivered = [];
		$skipped   = [];
		$links     = [];
		foreach ($items as $item) {
			$label = $item['title'];
			if ($item['fileId'] !== null) {
				$copied = $this->copyFile(folder: $folder, fileId: $item['fileId'], actor: $actor);
				if ($copied === null) {
					$delivered[] = $this->labelOr(label: $label, fallback: (string) $item['fileId']);
					continue;
				}

				$skipped[] = $this->labelOr(label: $label, fallback: (string) $item['fileId']) . ' (' . $copied . ')';
				continue;
			}

			if ($item['url'] !== null) {
				$links[] = '- [' . $this->labelOr(label: $label, fallback: $item['url']) . '](' . $item['url'] . ')';
				continue;
			}

			$skipped[] = $this->labelOr(label: $label, fallback: 'untitled item') . ' (no file or link)';
		}//end foreach

		if ($links !== []) {
			try {
				$this->writeFile(folder: $folder, name: self::LINKS_FILE, content: "# Links\n\n" . implode("\n", $links) . "\n");
				$delivered[] = self::LINKS_FILE;
			} catch (\Throwable $e) {
				$skipped[] = self::LINKS_FILE . ' (' . $e->getMessage() . ')';
			}
		}

		return ['delivered' => $delivered, 'skipped' => $skipped];
	}//end copyAll()

	/**
	 * Copy one file into the folder.
	 *
	 * @param Folder $folder The member's induction folder.
	 * @param int    $fileId The source file id.
	 * @param string $actor  Whose Files the source is read from.
	 *
	 * @return string|null Null when copied, else why it was skipped.
	 *
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
	 */
	private function copyFile(Folder $folder, int $fileId, string $actor): ?string {
		try {
			$nodes = $this->rootFolder->getUserFolder($actor)->getById($fileId);
		} catch (\Throwable $e) {
			return 'not readable';
		}

		foreach ($nodes as $node) {
			if ($node instanceof File === false) {
				continue;
			}

			try {
				$this->writeFile(folder: $folder, name: $node->getName(), content: $node->getContent());
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
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
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
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
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
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
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
	 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-007-induction-pack-delivered-into-the-members-files
	 */
	private function labelOr(string $label, string $fallback): string {
		if ($label === '') {
			return $fallback;
		}

		return $label;
	}//end labelOr()

}//end class
