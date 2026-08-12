<?php

/**
 * Decidesk MCP Source Formatter
 *
 * Normalises OpenRegister objects and builds the `sources` descriptors and
 * error envelopes every MCP tool result shares, so the individual tool
 * handlers stay free of presentation branching.
 *
 * @category Mcp
 * @package  OCA\Decidesk\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/mcp-tools/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Mcp;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Shapes MCP tool results.
 *
 * Extracted from DecideskToolProvider so the source-descriptor cap
 * (REQ-DMCP-006), object normalisation and the error envelope live in one
 * collaborator instead of being repeated across five tool handlers.
 *
 * @spec openspec/specs/mcp-tools/spec.md
 */
class McpSourceFormatter {

	/**
	 * Maximum number of source descriptors per tool result (REQ-DMCP-006).
	 *
	 * @var int
	 */
	private const SOURCES_CAP = 20;

	/**
	 * Normalise an OpenRegister object to a plain PHP array.
	 *
	 * @param mixed $item Raw item from ObjectService
	 *
	 * @return array<string, mixed> The normalised array.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function toArray(mixed $item): array {
		if (is_array(value: $item) === true) {
			return $item;
		}

		if (is_object(value: $item) === true && method_exists($item, 'getObject') === true) {
			return $item->getObject();
		}

		if (is_object(value: $item) === true && method_exists($item, 'jsonSerialize') === true) {
			return $item->jsonSerialize();
		}

		return (array)$item;
	}//end toArray()

	/**
	 * Extract the UUID from a normalised object array.
	 *
	 * Checks multiple common field names to handle different OR object shapes.
	 *
	 * @param array<string, mixed> $item The normalised object array
	 *
	 * @return string The UUID, or empty string when not found.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function extractUuid(array $item): string {
		$uuid = $item['uuid'] ?? $item['id'] ?? ($item['@self']['uuid'] ?? ($item['@self']['id'] ?? ''));
		return (string)$uuid;
	}//end extractUuid()

	/**
	 * Build a deep link URL for a decidesk resource.
	 *
	 * @param string $type One of: meeting, agendaItem, decision, actionItem
	 * @param string $uuid The object UUID
	 *
	 * @return string The deep link path, e.g. /apps/decidesk/meetings/<uuid>.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function deepLink(string $type, string $uuid): string {
		$paths = [
			'meeting' => '/apps/decidesk/meetings',
			'agendaItem' => '/apps/decidesk/agenda-items',
			'decision' => '/apps/decidesk/decisions',
			'actionItem' => '/apps/decidesk/action-items',
		];

		$base = $paths[$type] ?? "/apps/decidesk/{$type}s";
		return "{$base}/{$uuid}";
	}//end deepLink()

	/**
	 * Build one source descriptor.
	 *
	 * @param string $type The source type, e.g. "decidesk.meeting"
	 * @param string $uuid The object UUID
	 * @param string $label The human-readable label
	 *
	 * @return array<string, mixed> The source descriptor.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function source(string $type, string $uuid, string $label): array {
		$linkType = $type;
		$dotPos = strrpos($type, '.');
		if ($dotPos !== false) {
			$linkType = substr($type, ($dotPos + 1));
		}

		return [
			'type' => $type,
			'uuid' => $uuid,
			'url' => $this->deepLink(type: $linkType, uuid: $uuid),
			'label' => $label,
		];

	}//end source()

	/**
	 * Attach a (possibly capped) sources array to a successful tool payload.
	 *
	 * When the source list exceeds SOURCES_CAP the payload additionally carries
	 * `sourcesTruncated` and `sourcesTotalCount` (REQ-DMCP-006).
	 *
	 * @param array<string, mixed> $payload The success payload
	 * @param array<int, array<string, mixed>> $sources The full sources array
	 *
	 * @return array<string, mixed> The payload with sources attached.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function withSources(array $payload, array $sources): array {
		$totalCount = count($sources);
		$capped = $sources;

		if ($totalCount > self::SOURCES_CAP) {
			$capped = array_slice(array: $sources, offset: 0, length: self::SOURCES_CAP);
			$payload['sources'] = $capped;
			$payload['sourcesTruncated'] = true;
			$payload['sourcesTotalCount'] = $totalCount;

			return $payload;
		}

		$payload['sources'] = $capped;

		return $payload;
	}//end withSources()

	/**
	 * Build a structured MCP error envelope.
	 *
	 * @param string $code The machine-readable error code
	 * @param string $message The human-readable message
	 *
	 * @return array<string, mixed> The error envelope.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function error(string $code, string $message): array {
		return [
			'isError' => true,
			'error' => $code,
			'message' => $message,
		];

	}//end error()

	/**
	 * Current timestamp in ISO 8601 (ATOM) form.
	 *
	 * @return string The formatted timestamp.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function nowIso(): string {
		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
	}//end nowIso()
}//end class
