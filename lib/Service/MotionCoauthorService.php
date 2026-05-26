<?php
/**
 * Decidesk Motion Coauthor Service
 *
 * Stateless service handling motion co-authoring: adding/removing co-authors,
 * updating motion text with version capture, and detecting overlapping edits.
 * Conflict resolution is paragraph-level (last writer wins per paragraph;
 * overlapping ranges on the same paragraph are flagged before save, not
 * merged automatically).
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
 * @spec openspec/changes/p4-collaboration/tasks.md#task-9
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service for motion co-authoring: members, text updates, version history,
 * and conflict detection on overlapping paragraph edits.
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
 */
class MotionCoauthorService
{
    /**
     * Construct the MotionCoauthorService.
     *
     * @param ContainerInterface $container DI container (lazy-loads OR services)
     * @param LoggerInterface    $logger    Logger interface
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the OpenRegister ObjectService from the container.
     *
     * @return object
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end getObjectService()

    /**
     * Find a motion by UUID.
     *
     * @param string $motionId Motion UUID
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the motion cannot be found
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
     */
    private function findMotion(string $motionId): array
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');

        $entity = $objectService->find($motionId);
        if ($entity === null) {
            throw new RuntimeException("Motion $motionId not found");
        }

        return $entity->getObject();

    }//end findMotion()

    /**
     * Add a co-author to a motion.
     *
     * @param string $motionId Motion UUID
     * @param string $personId Person UUID to add as co-author
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
     */
    public function addCoauthor(string $motionId, string $personId): array
    {
        $motion    = $this->findMotion(motionId: $motionId);
        $coauthors = ($motion['coAuthors'] ?? []);

        if (in_array($personId, $coauthors, true) === false) {
            $coauthors[]         = $personId;
            $motion['coAuthors'] = $coauthors;

            $objectService = $this->getObjectService();
            $objectService->saveObject(
                object: $motion,
                register: 'decidesk',
                schema: 'motion',
                uuid: $motionId,
            );

            $this->logger->info(
                'Decidesk: Motion coauthor added',
                ['motionId' => $motionId, 'personId' => $personId]
            );
        }

        return $motion;

    }//end addCoauthor()

    /**
     * Remove a co-author from a motion.
     *
     * @param string $motionId Motion UUID
     * @param string $personId Person UUID to remove
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
     */
    public function removeCoauthor(string $motionId, string $personId): array
    {
        $motion    = $this->findMotion(motionId: $motionId);
        $coauthors = ($motion['coAuthors'] ?? []);
        $motion['coAuthors'] = array_values(
            array_filter(
                $coauthors,
                static fn($id) => $id !== $personId
            )
        );

        $objectService = $this->getObjectService();
        $objectService->saveObject(
            object: $motion,
            register: 'decidesk',
            schema: 'motion',
            uuid: $motionId,
        );

        return $motion;

    }//end removeCoauthor()

    /**
     * Update the motion text and capture a new version snapshot.
     *
     * Detects overlapping edits with the previous version: if any paragraph
     * (split by double newline) is changed twice within 5 minutes by different
     * authors, the operation throws a RuntimeException with the conflict
     * marker so the caller can prompt for manual resolution.
     *
     * @param string $motionId      Motion UUID
     * @param string $newText       New motion text
     * @param string $author        Author UUID making the change
     * @param string $changeSummary Human-readable change summary
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When an overlapping edit conflict is detected
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
     */
    public function updateMotionText(
        string $motionId,
        string $newText,
        string $author,
        string $changeSummary
    ): array {
        $motion          = $this->findMotion(motionId: $motionId);
        $previousText    = (string) ($motion['text'] ?? '');
        $previousHistory = ($motion['versionHistory'] ?? []);

        $conflict = $this->detectParagraphConflict(
            previousText: $previousText,
            newText: $newText,
            history: $previousHistory,
            currentAuthor: $author,
        );

        if ($conflict !== null) {
            $this->logger->warning(
                'Decidesk: Motion edit conflict detected',
                ['motionId' => $motionId, 'paragraph' => $conflict]
            );
            throw new RuntimeException(
                "Overlapping edit conflict on paragraph: $conflict"
            );
        }

        $previousHistory[] = [
            'author'    => $author,
            'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'text'      => $previousText,
            'summary'   => $changeSummary,
        ];

        $motion['text']           = $newText;
        $motion['versionHistory'] = $previousHistory;

        $objectService = $this->getObjectService();
        $objectService->saveObject(
            object: $motion,
            register: 'decidesk',
            schema: 'motion',
            uuid: $motionId,
        );

        $this->logger->info(
            'Decidesk: Motion text updated',
            ['motionId' => $motionId, 'author' => $author]
        );

        return $motion;

    }//end updateMotionText()

    /**
     * Detect paragraph-level conflicts between previous and new text.
     *
     * Compares paragraphs (split by double newline) — if the same paragraph
     * was changed by another author in the last 5 minutes, returns a short
     * conflict marker for the calling layer to surface to the user.
     *
     * @param string                           $previousText  Previous full text
     * @param string                           $newText       New full text
     * @param array<int, array<string, mixed>> $history       Existing version history
     * @param string                           $currentAuthor Author of the new change
     *
     * @return string|null Paragraph conflict marker, or null when none
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.6
     */
    private function detectParagraphConflict(
        string $previousText,
        string $newText,
        array $history,
        string $currentAuthor
    ): ?string {
        if (count($history) === 0) {
            return null;
        }

        $latest = end($history);
        if (is_array($latest) === false) {
            return null;
        }

        $latestAuthor    = ($latest['author'] ?? '');
        $latestTimestamp = ($latest['timestamp'] ?? null);
        if ($latestAuthor === $currentAuthor || $latestTimestamp === null) {
            return null;
        }

        try {
            $latestTime = new DateTimeImmutable((string) $latestTimestamp);
        } catch (Throwable $e) {
            return null;
        }

        $diff = (new DateTimeImmutable())->getTimestamp() - $latestTime->getTimestamp();
        if ($diff > 300) {
            return null;
        }

        // Within 5 min, different author — diff paragraphs.
        $prevPars = preg_split('/\n\s*\n/', $previousText);
        if ($prevPars === false) {
            $prevPars = [];
        }

        $newPars = preg_split('/\n\s*\n/', $newText);
        if ($newPars === false) {
            $newPars = [];
        }

        $count = max(count($prevPars), count($newPars));

        for ($i = 0; $i < $count; $i++) {
            $prev    = ($prevPars[$i] ?? '');
            $current = ($newPars[$i] ?? '');
            if ($prev !== $current) {
                $snippet = substr(trim($current), 0, 60);
                return "$i:$snippet";
            }
        }

        return null;

    }//end detectParagraphConflict()

    /**
     * Capture a manual version snapshot without changing the text.
     *
     * @param string $motionId      Motion UUID
     * @param string $author        Author UUID
     * @param string $changeSummary Human-readable summary
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
     */
    public function captureVersion(string $motionId, string $author, string $changeSummary): array
    {
        $motion  = $this->findMotion(motionId: $motionId);
        $history = ($motion['versionHistory'] ?? []);

        $history[] = [
            'author'    => $author,
            'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'text'      => (string) ($motion['text'] ?? ''),
            'summary'   => $changeSummary,
        ];

        $motion['versionHistory'] = $history;

        $objectService = $this->getObjectService();
        $objectService->saveObject(
            object: $motion,
            register: 'decidesk',
            schema: 'motion',
            uuid: $motionId,
        );

        return $motion;

    }//end captureVersion()

    /**
     * Get the version history of a motion.
     *
     * @param string $motionId Motion UUID
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
     */
    public function getHistory(string $motionId): array
    {
        $motion = $this->findMotion(motionId: $motionId);
        return ($motion['versionHistory'] ?? []);

    }//end getHistory()
}//end class
