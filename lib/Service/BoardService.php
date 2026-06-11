<?php
/**
 * Decidesk Board Service
 *
 * CRUD on the Board entity (raad-van-commissarissen, raad-van-bestuur,
 * audit-committee, remuneration-committee, nomination-committee,
 * risk-committee, one-tier-board). Enforces enum and minimum-field
 * validation before delegating persistence to OpenRegister's ObjectService.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-service
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Server-side Board CRUD wrapper.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-service
 */
class BoardService
{

    /**
     * Allowed `type` enum values for a Board.
     *
     * @var string[]
     */
    public const TYPES = [
        'raad-van-commissarissen',
        'raad-van-bestuur',
        'audit-committee',
        'remuneration-committee',
        'nomination-committee',
        'risk-committee',
        'one-tier-board',
    ];

    /**
     * Allowed `governanceModel` enum values for a Board.
     *
     * @var string[]
     */
    public const GOVERNANCE_MODELS = ['two-tier', 'one-tier'];


    /**
     * Constructor for BoardService.
     *
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()


    /**
     * List all boards. Optional `type` filter narrows by governance type.
     *
     * @param array{type?: string, limit?: int, offset?: int} $filters Optional filters
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-service
     *
     * @return array{success: bool, boards: array<int, array<string, mixed>>, count: int, message: string}
     */
    public function list(array $filters=[]): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $rows = $objectService->findAll(
                [
                    'register' => 'decidesk',
                    'schema'   => 'board',
                    'limit'    => (int) ($filters['limit'] ?? 100),
                    'offset'   => (int) ($filters['offset'] ?? 0),
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: BoardService::list failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'boards'  => [],
                'count'   => 0,
                'message' => 'Failed to load boards.',
            ];
        }

        $boards = [];
        foreach ((array) $rows as $row) {
            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $row = (array) $row->jsonSerialize();
            }

            if (is_array($row) === false) {
                continue;
            }

            if (isset($filters['type']) === true && ($row['type'] ?? null) !== $filters['type']) {
                continue;
            }

            $boards[] = $row;
        }

        return [
            'success' => true,
            'boards'  => $boards,
            'count'   => count($boards),
            'message' => 'ok',
        ];

    }//end list()


    /**
     * Load a single board by UUID.
     *
     * @param string $boardId UUID of the board
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-service
     *
     * @return array{success: bool, board: array|null, message: string}
     */
    public function get(string $boardId): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(id: $boardId, register: 'decidesk', schema: 'board');
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: BoardService::get failed',
                ['boardId' => $boardId, 'exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'board'   => null,
                'message' => 'Failed to load board.',
            ];
        }

        if ($entity === null) {
            return [
                'success' => false,
                'board'   => null,
                'message' => 'Board not found.',
            ];
        }

        return [
            'success' => true,
            'board'   => (array) $entity->jsonSerialize(),
            'message' => 'ok',
        ];

    }//end get()


    /**
     * Create a new board after validating required fields and enum values.
     *
     * @param array<string, mixed> $data Board attributes
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-service
     *
     * @return array{success: bool, board: array|null, message: string}
     */
    public function create(array $data): array
    {
        $validation = $this->validate($data, requireName: true);
        if ($validation !== null) {
            return [
                'success' => false,
                'board'   => null,
                'message' => $validation,
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $saved         = $objectService->saveObject(
                object: $data,
                register: 'decidesk',
                schema: 'board'
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: BoardService::create failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'board'   => null,
                'message' => 'Failed to create board.',
            ];
        }

        $this->logger->info('Decidesk: board created', ['name' => $data['name'] ?? '?']);

        return [
            'success' => true,
            'board'   => is_object($saved) === true ? (array) $saved->jsonSerialize() : $data,
            'message' => 'Board created.',
        ];

    }//end create()


    /**
     * Update an existing board.
     *
     * @param string               $boardId UUID of the board
     * @param array<string, mixed> $data    Fields to update
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-phase2-board-service
     *
     * @return array{success: bool, board: array|null, message: string}
     */
    public function update(string $boardId, array $data): array
    {
        $validation = $this->validate($data, requireName: false);
        if ($validation !== null) {
            return [
                'success' => false,
                'board'   => null,
                'message' => $validation,
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $entity        = $objectService->find(id: $boardId, register: 'decidesk', schema: 'board');
            if ($entity === null) {
                return [
                    'success' => false,
                    'board'   => null,
                    'message' => 'Board not found.',
                ];
            }

            $current = (method_exists($entity, 'getObject') === true) ? $entity->getObject() : (array) $entity->jsonSerialize();
            $merged  = array_merge($current, $data);

            $saved = $objectService->saveObject(
                object: $merged,
                register: 'decidesk',
                schema: 'board',
                uuid: $boardId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: BoardService::update failed',
                ['boardId' => $boardId, 'exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'board'   => null,
                'message' => 'Failed to update board.',
            ];
        }//end try

        return [
            'success' => true,
            'board'   => is_object($saved) === true ? (array) $saved->jsonSerialize() : $merged,
            'message' => 'Board updated.',
        ];

    }//end update()


    /**
     * Validate a Board payload. Returns an error message string when
     * validation fails or null when the payload is acceptable.
     *
     * @param array<string, mixed> $data        Payload to inspect
     * @param bool                 $requireName Whether the `name` field is mandatory
     *
     * @return string|null
     */
    private function validate(array $data, bool $requireName): ?string
    {
        if ($requireName === true && (isset($data['name']) === false || trim((string) $data['name']) === '')) {
            return 'Board name is required.';
        }

        if (isset($data['type']) === true && in_array($data['type'], self::TYPES, true) === false) {
            return 'Unknown board type: '.$data['type'];
        }

        if (isset($data['governanceModel']) === true && in_array($data['governanceModel'], self::GOVERNANCE_MODELS, true) === false) {
            return 'Unknown governance model: '.$data['governanceModel'];
        }

        return null;

    }//end validate()


}//end class
