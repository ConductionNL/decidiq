<?php

/**
 * Decidesk Meeting Folder Service
 *
 * Creates the structured Files folder tree for a meeting:
 * "Decidesk/<body>/<date> <title>/" with "Agenda Documents" and
 * "Minutes" subfolders (nextcloud-integration spec, Files requirement).
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the per-meeting document folder structure via OpenRegister's
 * FileService (the motion dossier-folder pattern, extended with the
 * spec's body/date/title hierarchy and the two standard subfolders).
 *
 * ## Access-control note (documented limitation)
 *
 * Folders are created in the OpenRegister app storage. Per-member
 * read/write differentiation (body members read, secretary/chair write)
 * requires the groupfolders app with ACL support; plain app storage
 * cannot express per-object ACLs. Until a groupfolders integration is
 * configured, visibility follows the register's sharing configuration.
 * This limitation is recorded in the spec delta.
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class MeetingFolderService
{

    /**
     * Subfolders created under every meeting folder.
     *
     * @var string[]
     */
    public const SUBFOLDERS = [
        'Agenda Documents',
        'Minutes',
    ];

    /**
     * Constructor for MeetingFolderService.
     *
     * @param ContainerInterface $container DI container (lazy-loads OpenRegister's FileService + ObjectService)
     * @param LoggerInterface    $logger    The logger
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Ensure the folder tree for a meeting exists (idempotent, fail-soft).
     *
     * @param array<string, mixed> $meeting Meeting object payload (post-create)
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return string|null The created meeting folder path, or null on failure
     */
    public function ensureMeetingFolders(array $meeting): ?string
    {
        $title = (string) ($meeting['title'] ?? ($meeting['name'] ?? ''));
        $uuid  = (string) ($meeting['id'] ?? ($meeting['@self']['id'] ?? ''));
        if ($title === '' && $uuid === '') {
            return null;
        }

        try {
            $bodyName = $this->resolveBodyName(meeting: $meeting);
            $date     = $this->resolveDate(meeting: $meeting);

            $segments = ['Decidesk'];
            if ($bodyName !== '') {
                $segments[] = $this->sanitize(name: $bodyName);
            }

            $leafName = $uuid;
            if ($title !== '') {
                $leafName = $title;
            }

            $segments[] = trim($date.' '.$this->sanitize(name: $leafName));

            $fileService = $this->container->get('OCA\OpenRegister\Service\FileService');

            $path = '';
            foreach ($segments as $segment) {
                if ($path === '') {
                    $path = $segment;
                } else {
                    $path = $path.'/'.$segment;
                }

                $fileService->createFolder($path);
            }

            foreach (self::SUBFOLDERS as $subfolder) {
                $fileService->createFolder($path.'/'.$subfolder);
            }

            $this->logger->info('Decidesk: meeting folder tree ensured', ['path' => $path, 'meetingId' => $uuid]);
            return $path;
        } catch (\Throwable $e) {
            // Fail soft: a meeting must never fail to save because Files is unavailable.
            $this->logger->warning(
                'Decidesk: meeting folder creation failed',
                ['meetingId' => $uuid, 'error' => $e->getMessage()]
            );
            return null;
        }//end try

    }//end ensureMeetingFolders()

    /**
     * Write a file into a subfolder of a meeting's folder tree (fail-soft).
     *
     * Ensures the meeting folder tree exists (idempotent), resolves the
     * requested subfolder node via the OpenRegister FileService, and writes
     * the given content. An existing file with the same name is overwritten
     * (putContent) so re-generation updates the document in place.
     *
     * @param array<string, mixed> $meeting   Meeting object payload
     * @param string               $subfolder Subfolder name (e.g. 'Minutes')
     * @param string               $fileName  File name including extension
     * @param string               $content   File content (text or binary)
     *
     * @spec openspec/specs/resolution-minutes/spec.md
     *
     * @return string|null The full file path on success, or null on failure
     */
    public function writeMeetingFile(array $meeting, string $subfolder, string $fileName, string $content): ?string
    {
        $meetingPath = $this->ensureMeetingFolders(meeting: $meeting);
        if ($meetingPath === null) {
            return null;
        }

        try {
            $fileService = $this->container->get('OCA\OpenRegister\Service\FileService');

            $safeName   = $this->sanitize(name: $fileName);
            $folderPath = $meetingPath.'/'.$this->sanitize(name: $subfolder);

            // CreateFolder is idempotent and returns the (existing or new) Folder node.
            $folderNode = $fileService->createFolder($folderPath);

            try {
                $existing = $folderNode->get($safeName);
                $existing->putContent($content);
            } catch (\OCP\Files\NotFoundException) {
                $folderNode->newFile($safeName, $content);
            }

            $filePath = $folderPath.'/'.$safeName;
            $this->logger->info('Decidesk: meeting file written', ['path' => $filePath]);
            return $filePath;
        } catch (\Throwable $e) {
            // Fail soft, same posture as ensureMeetingFolders: document generation
            // surfaces the failure to the caller (null), nothing fatals.
            $this->logger->warning(
                'Decidesk: meeting file write failed',
                ['subfolder' => $subfolder, 'fileName' => $fileName, 'error' => $e->getMessage()]
            );
            return null;
        }//end try

    }//end writeMeetingFile()

    /**
     * Resolve the governance-body display name from the meeting's relation.
     *
     * @param array<string, mixed> $meeting Meeting object payload
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return string Body name, or '' when not linked / not resolvable
     */
    private function resolveBodyName(array $meeting): string
    {
        $bodyId = $meeting['governanceBody'] ?? ($meeting['body'] ?? ($meeting['relations']['GovernanceBody'][0] ?? null));
        if (is_array($bodyId) === true) {
            $bodyId = ($bodyId['id'] ?? null);
        }

        if (is_string($bodyId) === false || $bodyId === '') {
            return '';
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(id: $bodyId, register: 'decidesk', schema: 'governance-body');
        } catch (\Throwable) {
            return '';
        }

        if ($entity === null) {
            return '';
        }

        $body = (array) $entity->jsonSerialize();
        return (string) ($body['name'] ?? ($body['title'] ?? ''));

    }//end resolveBodyName()

    /**
     * Resolve the meeting date as YYYY-MM-DD for the folder name.
     *
     * @param array<string, mixed> $meeting Meeting object payload
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return string Date prefix, or '' when no parseable date exists
     */
    private function resolveDate(array $meeting): string
    {
        $raw = (string) ($meeting['scheduledDate'] ?? ($meeting['startDate'] ?? ($meeting['date'] ?? '')));
        if ($raw === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($raw))->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }

    }//end resolveDate()

    /**
     * Sanitize a name for use as a single Files path segment.
     *
     * @param string $name Raw display name
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     *
     * @return string Path-safe segment (no slashes/backslashes/control chars)
     */
    private function sanitize(string $name): string
    {
        $clean = preg_replace('/[\/\\\\:*?"<>|]+/', '-', $name) ?? '';
        $clean = preg_replace('/[[:cntrl:]]+/', '', $clean) ?? '';
        $clean = trim($clean, " .-");
        if ($clean === '') {
            return 'meeting';
        }

        return $clean;

    }//end sanitize()
}//end class
