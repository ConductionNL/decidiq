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
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
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
     * Uses jsonSerialize() (not getObject()) so that @self metadata —
     * including @self.owner (the NC UID of the creator) — is included in the
     * returned array. The IDOR guard in checkMotionAccess() depends on @self.owner.
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
        // M4: use named-arg find() instead of setRegister/setSchema pattern.
        $entity = $objectService->find(id: $motionId, register: 'decidesk', schema: 'motion');
        if ($entity === null) {
            throw new RuntimeException("Motion $motionId not found");
        }

        return $entity->jsonSerialize();

    }//end findMotion()

    /**
     * Resolve the OpenRegister participant UUID for a given Nextcloud user ID.
     *
     * Returns null when no participant record is linked to this user.
     *
     * @param string $nextcloudUid Nextcloud UID
     *
     * @return string|null
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
     */
    private function resolveParticipantUuid(string $nextcloudUid): ?string
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('participant');
        $entities = $objectService->findAll(['filters' => ['nextcloudUserId' => $nextcloudUid]]);

        foreach ($entities as $participantEntity) {
            $participant = $participantEntity->jsonSerialize();
            return ($participant['uuid'] ?? $participant['id'] ?? null);
        }

        return null;

    }//end resolveParticipantUuid()

    /**
     * Assert the caller is allowed to mutate a motion's co-author list or text.
     *
     * A caller is allowed when:
     *   (a) callerUid is null — caller has already been authorised (admin path), OR
     *   (b) $callerIsAdmin === true, OR
     *   (c) the motion's @self.owner field matches the callerUid (proposer = creator), OR
     *   (d) the caller's participant UUID is in the motion's coAuthors list.
     *
     * Throws \InvalidArgumentException on access denied.
     *
     * @param array<string,mixed> $motion        Serialised motion object (with @self)
     * @param string|null         $callerUid     NC UID of the requester (null = skip check)
     * @param bool                $callerIsAdmin Whether the caller is an NC admin
     *
     * @return void
     *
     * @throws \InvalidArgumentException When the caller is not authorised
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
     */
    private function checkMotionAccess(array $motion, ?string $callerUid, bool $callerIsAdmin): void
    {
        if ($callerUid === null || $callerIsAdmin === true) {
            return;
        }

        // Check whether caller is the motion's owner (proposer, stored in @self.owner).
        $owner = (string) ($motion['@self']['owner'] ?? $motion['owner'] ?? '');
        if ($owner !== '' && $owner === $callerUid) {
            return;
        }

        // Check whether caller's participant UUID is listed as a coAuthor.
        $participantUuid = $this->resolveParticipantUuid(nextcloudUid: $callerUid);
        $coauthors       = ($motion['coAuthors'] ?? []);
        if ($participantUuid !== null && in_array($participantUuid, $coauthors, true) === true) {
            return;
        }

        throw new InvalidArgumentException(
            'Only the motion proposer or an existing co-author may modify this motion'
        );

    }//end checkMotionAccess()

    /**
     * Add a co-author to a motion.
     *
     * Only the motion proposer (owner), an existing co-author, or an admin may
     * add co-authors (OWASP A01:2021 — Broken Access Control).
     * Pass `$callerUid = null` to bypass the ownership check (admin/background-job paths).
     *
     * @param string      $motionId  Motion UUID
     * @param string      $personId  Person UUID to add as co-author
     * @param string|null $callerUid NC UID of the requester (null = skip access check)
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When caller is not authorised
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
     */
    public function addCoauthor(
        string $motionId,
        string $personId,
        ?string $callerUid=null,
    ): array {
        $motion = $this->findMotion(motionId: $motionId);
        $this->checkMotionAccess(motion: $motion, callerUid: $callerUid, callerIsAdmin: false);

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
     * Only the motion proposer (owner), an existing co-author, or an admin may
     * remove co-authors (OWASP A01:2021 — Broken Access Control).
     * Pass `$callerUid = null` to bypass the ownership check (admin/background-job paths).
     *
     * @param string      $motionId  Motion UUID
     * @param string      $personId  Person UUID to remove
     * @param string|null $callerUid NC UID of the requester (null = skip access check)
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When caller is not authorised
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
     */
    public function removeCoauthor(
        string $motionId,
        string $personId,
        ?string $callerUid=null,
    ): array {
        $motion = $this->findMotion(motionId: $motionId);
        $this->checkMotionAccess(motion: $motion, callerUid: $callerUid, callerIsAdmin: false);
        $coauthors           = ($motion['coAuthors'] ?? []);
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
     * Only the motion proposer (owner), an existing co-author, or an admin may
     * update the text (OWASP A01:2021 — Broken Access Control).
     *
     * @param string      $motionId      Motion UUID
     * @param string      $newText       New motion text
     * @param string      $author        NC UID of the author making the change (recorded in history)
     * @param string      $changeSummary Human-readable change summary
     * @param string|null $callerUid     NC UID to check for access (null = skip check for admin paths)
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When the caller is not authorised
     * @throws RuntimeException          When an overlapping edit conflict is detected
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.2
     */
    public function updateMotionText(
        string $motionId,
        string $newText,
        string $author,
        string $changeSummary,
        ?string $callerUid=null,
    ): array {
        $motion = $this->findMotion(motionId: $motionId);
        $this->checkMotionAccess(motion: $motion, callerUid: $callerUid, callerIsAdmin: false);
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
        if ($this->isConcurrentForeignEdit(history: $history, currentAuthor: $currentAuthor) === false) {
            return null;
        }

        // Within 5 min, different author — diff paragraphs.
        return $this->firstChangedParagraph(previousText: $previousText, newText: $newText);

    }//end detectParagraphConflict()

    /**
     * Whether the latest history entry is another author's edit within 5 minutes.
     *
     * `$history` is declared as `array<int, mixed>` rather than
     * `array<int, array<string, mixed>>` on purpose: it arrives as
     * `$motion['versionHistory']`, straight off an OpenRegister object, so an
     * entry that is not an array is a shape the store can actually hand us.
     * The narrower docblock made the `is_array()` guard below provably dead —
     * phpstan reported it as a comparison that can never be true — which is a
     * docblock that over-promises, not a guard that is redundant.
     *
     * @param array<int, mixed> $history       Existing version history
     * @param string            $currentAuthor Author of the new change
     *
     * @return bool True when the new change collides with a recent foreign edit.
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.6
     */
    private function isConcurrentForeignEdit(array $history, string $currentAuthor): bool
    {
        if (count($history) === 0) {
            return false;
        }

        $latest = end($history);
        if (is_array($latest) === false) {
            return false;
        }

        $latestTimestamp = ($latest['timestamp'] ?? null);
        if (($latest['author'] ?? '') === $currentAuthor || $latestTimestamp === null) {
            return false;
        }

        try {
            $latestTime = new DateTimeImmutable((string) $latestTimestamp);
        } catch (Throwable $e) {
            return false;
        }

        return ((new DateTimeImmutable())->getTimestamp() - $latestTime->getTimestamp()) <= 300;

    }//end isConcurrentForeignEdit()

    /**
     * The index and opening snippet of the first paragraph that differs.
     *
     * @param string $previousText Previous full text
     * @param string $newText      New full text
     *
     * @return string|null The "<index>:<snippet>" marker, or null when identical.
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.6
     */
    private function firstChangedParagraph(string $previousText, string $newText): ?string
    {
        $prevPars = $this->paragraphs(text: $previousText);
        $newPars  = $this->paragraphs(text: $newText);
        $count    = max(count($prevPars), count($newPars));

        for ($i = 0; $i < $count; $i++) {
            $current = ($newPars[$i] ?? '');
            if (($prevPars[$i] ?? '') !== $current) {
                return $i.':'.substr(trim($current), 0, 60);
            }
        }

        return null;

    }//end firstChangedParagraph()

    /**
     * Split a text into paragraphs on blank lines.
     *
     * @param string $text The full text
     *
     * @return array<int, string> The paragraphs.
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-9.6
     */
    private function paragraphs(string $text): array
    {
        $paragraphs = preg_split('/\n\s*\n/', $text);
        if ($paragraphs === false) {
            return [];
        }

        return $paragraphs;

    }//end paragraphs()

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
