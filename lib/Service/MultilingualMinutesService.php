<?php
/**
 * Decidesk Multilingual Minutes Service
 *
 * Creates and links parallel-language board minutes so signatures and
 * reconciliation apply across both legally-equal language versions.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;

/**
 * Parallel-language minutes creation and linking.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 */
class MultilingualMinutesService
{

    /**
     * Register slug.
     *
     * @var string
     */
    private const REGISTER = 'decidesk';

    /**
     * Schema slug.
     *
     * @var string
     */
    private const SCHEMA = 'board-minutes';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container.
     *
     * @return void
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
     */
    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }//end __construct()

    /**
     * Resolve OpenRegister ObjectService.
     *
     * @return object
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()

    /**
     * Create linked minutes records for a meeting in two languages.
     *
     * @param string $meetingId BoardMeeting UUID.
     * @param string $langA     First language enum value.
     * @param string $langB     Second language enum value.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     *
     * @throws \RuntimeException On invalid languages.
     *
     * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
     */
    public function createLinkedMinutes(string $meetingId, string $langA, string $langB): array
    {
        foreach ([$langA, $langB] as $lang) {
            if (in_array($lang, ['nl', 'en'], true) === false) {
                throw new \RuntimeException('Unsupported minutes language: '.$lang);
            }
        }

        $objectService = $this->objectService();

        $base = [
            'version'   => 'draft',
            'content'   => '',
            'relations' => [['schema' => 'board-meeting', 'id' => $meetingId]],
        ];

        $firstObject = ['language' => $langA] + $base;
        $first       = $objectService->saveObject(register: self::REGISTER, schema: self::SCHEMA, object: $firstObject);
        $firstId     = $this->idOf(saved: $first);

        $secondObject = ['language' => $langB, 'linkedMinutes' => $firstId] + $base;
        $second       = $objectService->saveObject(register: self::REGISTER, schema: self::SCHEMA, object: $secondObject);
        $secondId     = $this->idOf(saved: $second);

        // Back-link the first record to the second.
        if ($firstId !== null) {
            $firstData = $this->serialize(item: $first);
            $firstData['linkedMinutes'] = $secondId;
            $first = $objectService->saveObject(
                register: self::REGISTER,
                schema: self::SCHEMA,
                object: $firstData,
                uuid: $firstId
            );
        }

        return [$this->serialize(item: $first), $this->serialize(item: $second)];

    }//end createLinkedMinutes()

    /**
     * Extract the id from a saved object.
     *
     * @param mixed $saved ObjectEntity or array.
     *
     * @return string|null
     */
    private function idOf(mixed $saved): ?string
    {
        $data = $this->serialize(item: $saved);
        $id   = ($data['id'] ?? ($data['uuid'] ?? null));
        if ($id === null) {
            return null;
        }

        return (string) $id;

    }//end idOf()

    /**
     * Normalise an object-or-array into an associative array.
     *
     * @param mixed $item ObjectEntity or array.
     *
     * @return array<string,mixed>
     */
    private function serialize(mixed $item): array
    {
        if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
            return $item->jsonSerialize();
        }

        if (is_array($item) === true) {
            return $item;
        }

        return [];

    }//end serialize()
}//end class
