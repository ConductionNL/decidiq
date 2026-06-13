<?php
/**
 * Decidesk Meeting Package Service
 *
 * Assembles the documents linked to a meeting's agenda items into a
 * structured "Meeting package" folder (vergaderstukken) inside the
 * meeting's Files folder tree, with a generated table of contents
 * (agenda-management "Agenda Document Package" requirement).
 *
 * @category Service
 * @package  OCA\Decidesk\Service
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
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

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
class MeetingPackageService
{

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
     * @param ContainerInterface   $container            DI container (lazy-loads OpenRegister's ObjectService + FileService)
     * @param LoggerInterface      $logger               The logger
     * @param MeetingFolderService $meetingFolderService Creates / locates the meeting's Files folder tree
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly MeetingFolderService $meetingFolderService,
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
     * @param string $userId    Acting user UID (logged with the assembly)
     *
     * @spec openspec/specs/agenda-management/spec.md
     *
     * @return array{success: bool, path: string|null, items: int, files: int, skipped: array<int, string>, message: string}
     */
    public function assemble(string $meetingId, string $userId): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(id: $meetingId, register: 'decidesk', schema: 'meeting');
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: MeetingPackageService::assemble lookup failed',
                ['meetingId' => $meetingId, 'exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'path'    => null,
                'items'   => 0,
                'files'   => 0,
                'skipped' => [],
                'message' => 'Failed to load meeting.',
            ];
        }

        if ($entity === null) {
            return [
                'success' => false,
                'path'    => null,
                'items'   => 0,
                'files'   => 0,
                'skipped' => [],
                'message' => 'Meeting not found.',
            ];
        }

        $meeting = (array) $entity->jsonSerialize();
        if (method_exists($entity, 'getObject') === true) {
            $meeting = $entity->getObject();
        }

        $items = $this->collectAgendaItems(objectService: $objectService, meetingId: $meetingId);

        $meetingPath = $this->meetingFolderService->ensureMeetingFolders(meeting: array_merge($meeting, ['id' => $meetingId]));
        if ($meetingPath === null) {
            return [
                'success' => false,
                'path'    => null,
                'items'   => count($items),
                'files'   => 0,
                'skipped' => [],
                'message' => 'Could not create the meeting folder (Files unavailable).',
            ];
        }

        $skipped    = [];
        $filesCount = 0;

        try {
            $fileService = $this->container->get('OCA\OpenRegister\Service\FileService');

            $packagePath   = $meetingPath.'/'.self::PACKAGE_FOLDER;
            $packageFolder = $fileService->createFolder($packagePath);

            $this->writeFileDefensively(
                folder: $packageFolder,
                name: self::TOC_FILENAME,
                content: $this->buildTableOfContents(meeting: $meeting, items: $items),
                skipped: $skipped
            );

            $number = 0;
            foreach ($items as $item) {
                $number++;
                $itemFolderName = $this->itemFolderName(number: $number, item: $item);
                $itemFolder     = $fileService->createFolder($packagePath.'/'.$itemFolderName);

                foreach ($this->resolveItemFiles(fileService: $fileService, item: $item) as $fileNode) {
                    try {
                        $written = $this->writeFileDefensively(
                            folder: $itemFolder,
                            name: (string) $fileNode->getName(),
                            content: (string) $fileNode->getContent(),
                            skipped: $skipped
                        );
                        if ($written === true) {
                            $filesCount++;
                        }
                    } catch (\Throwable $copyError) {
                        $skipped[] = $itemFolderName.'/'.$fileNode->getName().' ('.$copyError->getMessage().')';
                    }
                }
            }//end foreach
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: meeting package assembly failed',
                ['meetingId' => $meetingId, 'exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'path'    => $meetingPath,
                'items'   => count($items),
                'files'   => $filesCount,
                'skipped' => $skipped,
                'message' => 'Package assembly failed. See server log for details.',
            ];
        }//end try

        $this->logger->info(
            'Decidesk: meeting package assembled',
            [
                'meetingId' => $meetingId,
                'userId'    => $userId,
                'path'      => $meetingPath.'/'.self::PACKAGE_FOLDER,
                'items'     => count($items),
                'files'     => $filesCount,
                'skipped'   => count($skipped),
            ]
        );

        $skippedSuffix = '';
        if (count($skipped) > 0) {
            $skippedSuffix = sprintf(', %d skipped', count($skipped));
        }

        return [
            'success' => true,
            'path'    => $meetingPath.'/'.self::PACKAGE_FOLDER,
            'items'   => count($items),
            'files'   => $filesCount,
            'skipped' => $skipped,
            'message' => sprintf(
                'Meeting package assembled: %d agenda item(s), %d document(s)%s.',
                count($items),
                $filesCount,
                $skippedSuffix
            ),
        ];

    }//end assemble()

    /**
     * Build the markdown table of contents for a meeting package.
     *
     * Pure (unit-tested): a meeting header followed by the agenda items in
     * orderNumber order, each with its document list, organized by agenda
     * item number and title per the spec scenario.
     *
     * @param array<string, mixed>             $meeting Meeting payload (title, scheduledDate)
     * @param array<int, array<string, mixed>> $items   Agenda items sorted by orderNumber
     *
     * @spec openspec/specs/agenda-management/spec.md
     *
     * @return string Markdown TOC content
     */
    public function buildTableOfContents(array $meeting, array $items): string
    {
        $title = (string) ($meeting['title'] ?? 'Meeting');
        $date  = substr((string) ($meeting['scheduledDate'] ?? ''), 0, 10);

        $header = '# '.$title;
        if ($date !== '') {
            $header .= ' — '.$date;
        }

        $lines   = [];
        $lines[] = $header;
        $lines[] = '';
        $lines[] = 'Meeting package (vergaderstukken) — table of contents.';
        $lines[] = '';

        $number = 0;
        foreach ($items as $item) {
            $number++;
            $lines[] = sprintf('%02d. %s', $number, (string) ($item['title'] ?? 'Agenda item'));

            $itemType = (string) ($item['itemType'] ?? '');
            $duration = $item['estimatedDuration'] ?? null;
            $metaBits = [];
            if ($itemType !== '') {
                $metaBits[] = $itemType;
            }

            if ($duration !== null) {
                $metaBits[] = $duration.' min';
            }

            if (count($metaBits) > 0) {
                $lines[] = '    ('.implode(', ', $metaBits).')';
            }

            foreach ((array) ($item['files'] ?? []) as $file) {
                $fileName = '';
                if (is_array($file) === true) {
                    $fileName = (string) ($file['name'] ?? ($file['title'] ?? ($file['path'] ?? '')));
                } else if (is_string($file) === true) {
                    $fileName = $file;
                }

                if ($fileName !== '') {
                    $lines[] = '    - '.basename($fileName);
                }
            }
        }//end foreach

        if ($number === 0) {
            $lines[] = '_No agenda items._';
        }

        return implode("\n", $lines)."\n";

    }//end buildTableOfContents()

    /**
     * Collect the meeting's agenda items sorted by orderNumber.
     *
     * @param object $objectService OpenRegister ObjectService
     * @param string $meetingId     UUID of the meeting
     *
     * @spec openspec/specs/agenda-management/spec.md
     *
     * @return array<int, array<string, mixed>> Agenda item payloads
     */
    private function collectAgendaItems(object $objectService, string $meetingId): array
    {
        try {
            $rows = $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => 'agenda-item',
                    'filters'  => ['meeting' => $meetingId],
                    'limit'    => 500,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Decidesk: package agenda-item lookup failed',
                ['meetingId' => $meetingId, 'exception' => $e->getMessage()]
            );
            return [];
        }

        $items = [];
        foreach ((array) $rows as $row) {
            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $row = (array) $row->jsonSerialize();
            }

            if (is_array($row) === false) {
                continue;
            }

            // Defensive post-filter: only keep rows actually linked to this
            // meeting when the relation field is present in the payload.
            $linked = $row['meeting'] ?? null;
            if (is_array($linked) === true) {
                $linked = ($linked['id'] ?? null);
            }

            if ($linked !== null && (string) $linked !== $meetingId) {
                continue;
            }

            $items[] = $row;
        }//end foreach

        usort(
            $items,
            static function (array $a, array $b): int {
                return ((int) ($a['orderNumber'] ?? 0)) <=> ((int) ($b['orderNumber'] ?? 0));
            }
        );

        return $items;

    }//end collectAgendaItems()

    /**
     * Resolve the file nodes attached to one agenda item.
     *
     * @param object               $fileService OpenRegister FileService
     * @param array<string, mixed> $item        Agenda item payload
     *
     * @spec openspec/specs/agenda-management/spec.md
     *
     * @return array<int, object> File nodes (possibly empty)
     */
    private function resolveItemFiles(object $fileService, array $item): array
    {
        $itemId = (string) ($item['id'] ?? ($item['@self']['id'] ?? ''));
        if ($itemId === '') {
            return [];
        }

        try {
            $nodes = $fileService->getFiles($itemId);
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Decidesk: package file resolution skipped for agenda item',
                ['itemId' => $itemId, 'error' => $e->getMessage()]
            );
            return [];
        }

        $files = [];
        foreach ((array) $nodes as $node) {
            if (is_object($node) === true
                && method_exists($node, 'getName') === true
                && method_exists($node, 'getContent') === true
            ) {
                $files[] = $node;
            }
        }

        return $files;

    }//end resolveItemFiles()

    /**
     * Write (or overwrite) a file in a folder, recording failures in `skipped`.
     *
     * @param object        $folder  Target folder node (OCP\Files\Folder shape)
     * @param string        $name    File name
     * @param string        $content File content
     * @param array<string> $skipped Skip log (mutated on failure; by-reference)
     *
     * @spec openspec/specs/agenda-management/spec.md
     *
     * @return bool True when the file was written, false when skipped
     */
    private function writeFileDefensively(object $folder, string $name, string $content, array &$skipped): bool
    {
        try {
            $folder->newFile($name, $content);
            return true;
        } catch (\Throwable) {
            // Fall through: the file may already exist (idempotent re-assembly).
        }

        try {
            $existing = $folder->get($name);
            $existing->putContent($content);
            return true;
        } catch (\Throwable $e) {
            $skipped[] = $name.' ('.$e->getMessage().')';
            return false;
        }

    }//end writeFileDefensively()

    /**
     * Compose the package folder name for one agenda item.
     *
     * @param int                  $number Sequential item number (1-based)
     * @param array<string, mixed> $item   Agenda item payload
     *
     * @spec openspec/specs/agenda-management/spec.md
     *
     * @return string Path-safe `NN - <title>` segment
     */
    private function itemFolderName(int $number, array $item): string
    {
        $title = (string) ($item['title'] ?? 'Agenda item');
        $clean = preg_replace('/[\/\\\\:*?"<>|]+/', '-', $title) ?? '';
        $clean = preg_replace('/[[:cntrl:]]+/', '', $clean) ?? '';
        $clean = trim($clean, " .-");
        if ($clean === '') {
            $clean = 'Agenda item';
        }

        return sprintf('%02d - %s', $number, $clean);

    }//end itemFolderName()
}//end class
