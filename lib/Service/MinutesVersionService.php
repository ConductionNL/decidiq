<?php

/**
 * Decidesk Minutes Version Service
 *
 * Manages version snapshots for Minutes objects via FileService attachments.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;

/**
 * Stateless service for managing version snapshots of Minutes content.
 *
 * Snapshots are stored as JSON file attachments to Minutes objects.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
 */
class MinutesVersionService
{
    /**
     * Constructor for MinutesVersionService.
     *
     * @param ContainerInterface $container The DI container (lazy-loads OpenRegister services)
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
     */
    public function __construct(
        private ContainerInterface $container,
    ) {
    }//end __construct()

    /**
     * Create a snapshot of Minutes content and increment version.
     *
     * @param string $minutesId  UUID of the Minutes object
     * @param string $oldContent The previous content before changes
     * @param string $actorId    User ID of who saved the change
     *
     * @return void
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
     */
    public function createSnapshot(string $minutesId, string $oldContent, string $actorId): void
    {
        $objectService = $this->getObjectService();
        $fileService   = $this->getFileService();

        // Fetch current Minutes to get version number
        $objectService->setRegister('decidesk');
        $objectService->setSchema('minutes');
        $minutesEntity = $objectService->find($minutesId);

        if ($minutesEntity === null) {
            return;
            // Minutes not found, skip snapshot
        }

        $minutes        = $minutesEntity->getObject();
        $currentVersion = $minutes['version'] ?? 1;

        // Create snapshot JSON
        $snapshot = [
            'version' => $currentVersion,
            'content' => $oldContent,
            'savedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'savedBy' => $actorId,
        ];

        // Upload snapshot as file attachment
        $filename = sprintf('minutes-v%d.json', $currentVersion);
        $fileService->upload(
            register: 'decidesk',
            schema: 'minutes',
            objectId: $minutesId,
            filename: $filename,
            content: json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Increment version
        $minutes['version'] = $currentVersion + 1;
        $objectService->saveObject(
            object: $minutes,
            register: 'decidesk',
            schema: 'minutes',
            uuid: $minutesId
        );
    }//end createSnapshot()

    /**
     * Get version history for a Minutes object.
     *
     * @param string $minutesId UUID of the Minutes object
     *
     * @return array<int, array<string, mixed>> Array of version entries sorted descending
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
     */
    public function getVersionHistory(string $minutesId): array
    {
        $fileService = $this->getFileService();

        try {
            $files = $fileService->getFilesByPattern(
                register: 'decidesk',
                schema: 'minutes',
                objectId: $minutesId,
                pattern: 'minutes-v*.json'
            );

            $versions = [];
            foreach ($files as $file) {
                if (preg_match('/minutes-v(\d+)\.json/', $file['name'] ?? '', $matches)) {
                    $version = (int) $matches[1];
                    $content = $file['content'] ?? '{}';
                    $decoded = json_decode($content, true);

                    if (is_array($decoded)) {
                        $versions[] = [
                            'version'  => $version,
                            'savedAt'  => $decoded['savedAt'] ?? '',
                            'savedBy'  => $decoded['savedBy'] ?? '',
                            'filename' => $file['name'] ?? '',
                        ];
                    }
                }
            }

            // Sort by version descending
            usort(
                    $versions,
                    static function (array $a, array $b): int {
                        return $b['version'] <=> $a['version'];
                    }
                    );

            return $versions;
        } catch (\Throwable) {
            return [];
        }//end try
    }//end getVersionHistory()

    /**
     * Get content of a specific version.
     *
     * @param string $minutesId UUID of the Minutes object
     * @param int    $version   Version number to retrieve
     *
     * @return array<string, mixed>|null The version snapshot or null if not found
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
     */
    public function getVersionContent(string $minutesId, int $version): ?array
    {
        $fileService = $this->getFileService();
        $filename    = sprintf('minutes-v%d.json', $version);

        try {
            $file = $fileService->getFile(
                register: 'decidesk',
                schema: 'minutes',
                objectId: $minutesId,
                filename: $filename
            );

            if ($file === null) {
                return null;
            }

            $content = $file['content'] ?? '{}';
            $decoded = json_decode($content, true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }//end getVersionContent()

    /**
     * Compute a line-level diff between two content strings.
     *
     * @param string $contentA First content string
     * @param string $contentB Second content string
     *
     * @return array<int, array<string, string>> Array of diff entries with type and text
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
     */
    public function diffVersions(string $contentA, string $contentB): array
    {
        $linesA = explode("\n", $contentA);
        $linesB = explode("\n", $contentB);

        // Simple line-level diff using array_diff
        $added   = array_diff($linesB, $linesA);
        $removed = array_diff($linesA, $linesB);

        // Create diff entries
        $diff = [];

        // Track which lines we've already processed
        $processedAdded   = [];
        $processedRemoved = [];

        // Add unchanged lines and diffs in order
        foreach ($linesB as $lineB) {
            if (!in_array($lineB, $added, true)) {
                $diff[] = [
                    'type' => 'unchanged',
                    'text' => $lineB,
                ];
            } else {
                if (!isset($processedAdded[$lineB])) {
                    $diff[] = [
                        'type' => 'added',
                        'text' => $lineB,
                    ];
                    $processedAdded[$lineB] = true;
                }
            }
        }

        // Add removed lines that weren't in B
        foreach ($linesA as $lineA) {
            if (in_array($lineA, $removed, true)) {
                if (!isset($processedRemoved[$lineA])) {
                    $diff[] = [
                        'type' => 'removed',
                        'text' => $lineA,
                    ];
                    $processedRemoved[$lineA] = true;
                }
            }
        }

        return $diff;
    }//end diffVersions()

    /**
     * Get the ObjectService from the DI container.
     *
     * @return mixed The ObjectService instance
     *
     * @throws \Exception When service is not available
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
     */
    private function getObjectService()
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()

    /**
     * Get the FileService from the DI container.
     *
     * @return mixed The FileService instance
     *
     * @throws \Exception When service is not available
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-2
     */
    private function getFileService()
    {
        return $this->container->get('OCA\OpenRegister\Service\FileService');
    }//end getFileService()
}//end class
