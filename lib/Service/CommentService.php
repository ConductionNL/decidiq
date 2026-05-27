<?php
/**
 * Decidesk Comment Service
 *
 * Stateless service handling threaded discussion comments on governance
 * artifacts (agenda items, motions, amendments, decisions). Uses a
 * polymorphic target reference ('{register}:{schema}:{uuid}') so a single
 * Comment schema can attach to any target.
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
 * @spec openspec/changes/p4-collaboration/tasks.md#task-5
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
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
 * Service for creating, querying, resolving, and deleting comments.
 *
 * Targets are encoded as 'decidesk:{schema}:{uuid}'. The service does
 * a soft existence check on the target object before saving, but
 * referential integrity is not enforced at the DB level.
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-5.1
 */
class CommentService
{
    /**
     * Construct the CommentService.
     *
     * @param ContainerInterface $container DI container (lazy-loads OR services)
     * @param LoggerInterface    $logger    Logger interface
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-5.1
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
     * @spec openspec/changes/p4-collaboration/tasks.md#task-5.1
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end getObjectService()

    /**
     * Parse a polymorphic target reference.
     *
     * @param string $target Target reference 'register:schema:uuid'
     *
     * @return array{register: string, schema: string, uuid: string}|null
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-5.1
     */
    private function parseTarget(string $target): ?array
    {
        $parts = explode(':', $target);
        if (count($parts) !== 3) {
            return null;
        }

        return [
            'register' => $parts[0],
            'schema'   => $parts[1],
            'uuid'     => $parts[2],
        ];

    }//end parseTarget()

    /**
     * Validate that the target object exists (soft check).
     *
     * @param string $target Target reference
     *
     * @return bool
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-5.1
     */
    private function targetExists(string $target): bool
    {
        $parsed = $this->parseTarget(target: $target);
        if ($parsed === null) {
            return false;
        }

        try {
            $objectService = $this->getObjectService();
            $objectService->setRegister($parsed['register']);
            $objectService->setSchema($parsed['schema']);
            $entity = $objectService->find($parsed['uuid']);
            return $entity !== null;
        } catch (Throwable $e) {
            $this->logger->debug(
                'Decidesk: Could not validate comment target',
                ['target' => $target, 'error' => $e->getMessage()]
            );
            return false;
        }

    }//end targetExists()

    /**
     * Persist a comment.
     *
     * @param array<string, mixed> $comment Comment properties
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When required fields missing
     * @throws RuntimeException         When the target does not exist
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-5.1
     */
    public function saveComment(array $comment): array
    {
        if (empty($comment['text']) === true) {
            throw new InvalidArgumentException('Comment text is required');
        }

        if (isset($comment['createdAt']) === false) {
            $comment['createdAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        }

        if (isset($comment['updatedAt']) === false) {
            $comment['updatedAt'] = $comment['createdAt'];
        }

        $target = ($comment['target'] ?? null);
        if ($target !== null && $this->targetExists(target: (string) $target) === false) {
            throw new RuntimeException("Comment target '$target' does not exist");
        }

        $objectService = $this->getObjectService();
        $saved         = $objectService->saveObject(
            object: $comment,
            register: 'decidesk',
            schema: 'comment',
        );

        if (is_array($saved) === true) {
            return $saved;
        }

        if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {
            return (array) $saved->getObject();
        }

        return (array) $saved;

    }//end saveComment()

    /**
     * Find a comment by UUID.
     *
     * @param string $commentId UUID of the Comment object
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-5.1
     */
    public function findComment(string $commentId): ?array
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('comment');

        $entity = $objectService->find($commentId);
        if ($entity === null) {
            return null;
        }

        return $entity->getObject();

    }//end findComment()

    /**
     * Find comments by target.
     *
     * @param string $target Polymorphic target reference
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-5.1
     */
    public function findCommentsForTarget(string $target): array
    {
        try {
            $objectService = $this->getObjectService();
            $objectService->setRegister('decidesk');
            $objectService->setSchema('comment');

            $results = $objectService->findAll(
                limit: 200,
                offset: 0,
                filters: ['target' => $target],
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Decidesk: findCommentsForTarget failed',
                ['target' => $target, 'error' => $e->getMessage()]
            );
            return [];
        }

        $out = [];
        foreach ($results as $entity) {
            if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
                $out[] = $entity->getObject();
            } else if (is_array($entity) === true) {
                $out[] = $entity;
            }
        }

        return $out;

    }//end findCommentsForTarget()

    /**
     * Mark a comment thread as resolved.
     *
     * Only the comment's author, a chair/secretary (checked by the controller),
     * or an NC admin should call this. Pass `$callerUid` so the service can
     * enforce that non-admin callers resolve only their own threads
     * (OWASP A01 — Broken Access Control).
     *
     * @param string      $commentId UUID of the root Comment object
     * @param string|null $callerUid Nextcloud UID of the requester (null = skip author check)
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException         When the comment cannot be found
     * @throws \InvalidArgumentException When the caller is not the comment author
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-5.1
     */
    public function resolveThread(string $commentId, ?string $callerUid = null): array
    {
        $comment = $this->findComment(commentId: $commentId);
        if ($comment === null) {
            throw new RuntimeException("Comment $commentId not found");
        }

        // OWASP A01 — verify the caller is the comment author.
        if ($callerUid !== null) {
            $author = (string) ($comment['author'] ?? '');
            if ($author === '' || $author !== $callerUid) {
                throw new \InvalidArgumentException('Only the comment author may resolve this thread');
            }
        }

        $comment['resolvedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        $objectService = $this->getObjectService();
        $objectService->saveObject(
            object: $comment,
            register: 'decidesk',
            schema: 'comment',
            uuid: $commentId,
        );

        return $comment;

    }//end resolveThread()

    /**
     * Update a comment's text (e.g. edit) and mentions.
     *
     * @param string               $commentId UUID of the Comment object
     * @param array<string, mixed> $changes   Fields to update
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the comment cannot be found
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-5.1
     */
    public function updateComment(string $commentId, array $changes): array
    {
        $comment = $this->findComment(commentId: $commentId);
        if ($comment === null) {
            throw new RuntimeException("Comment $commentId not found");
        }

        $allowed = ['text', 'mentions', 'resolvedAt'];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $changes) === true) {
                $comment[$key] = $changes[$key];
            }
        }

        $comment['updatedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        $objectService = $this->getObjectService();
        $objectService->saveObject(
            object: $comment,
            register: 'decidesk',
            schema: 'comment',
            uuid: $commentId,
        );

        return $comment;

    }//end updateComment()

    /**
     * Delete a comment.
     *
     * @param string $commentId UUID of the Comment object
     *
     * @return void
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-5.1
     */
    public function deleteComment(string $commentId): void
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('comment');
        $objectService->deleteObject($commentId);

        $this->logger->info('Decidesk: Comment deleted', ['commentId' => $commentId]);

    }//end deleteComment()
}//end class
