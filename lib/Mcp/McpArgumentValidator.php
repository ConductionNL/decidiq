<?php

/**
 * Decidesk MCP Argument Validator
 *
 * Validates the arguments an LLM passes to a decidesk MCP tool, returning a
 * ready-made error envelope on rejection and null on acceptance.
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

/**
 * Argument validation for the decidesk MCP tools.
 *
 * Extracted from DecideskToolProvider so that argument checking — which is a
 * large share of every handler's branching — is a unit of its own. Each
 * validate* method returns null when the arguments are acceptable, or a
 * structured `invalid_arguments` envelope describing the first violation.
 *
 * @spec openspec/specs/mcp-tools/spec.md
 */
class McpArgumentValidator {
	/**
	 * Constructor for the McpArgumentValidator.
	 *
	 * @param McpSourceFormatter $formatter Builds the error envelopes
	 *
	 * @return void
	 */
	public function __construct(
		private readonly McpSourceFormatter $formatter,
	) {
	}//end __construct()

	/**
	 * Validate that a string is a syntactically valid UUID (8-4-4-4-12 hex).
	 *
	 * @param string $candidate The candidate string to validate
	 *
	 * @return bool True when the string is UUID-shaped.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function isValidUuid(string $candidate): bool {
		return (bool)preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
			$candidate
		);

	}//end isValidUuid()

	/**
	 * Validate that a string is a valid ISO 8601 date (YYYY-MM-DD).
	 *
	 * @param string $candidate The candidate string
	 *
	 * @return bool True when the string is a valid date.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function isValidDate(string $candidate): bool {
		$dateObj = date_create_from_format('Y-m-d', $candidate);
		return $dateObj !== false && date_format($dateObj, 'Y-m-d') === $candidate;
	}//end isValidDate()

	/**
	 * Validate the meetingUuid argument shared by three tools.
	 *
	 * @param mixed $meetingUuid The raw meetingUuid argument
	 *
	 * @return array<string, mixed>|null The error envelope, or null when valid.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function validateMeetingUuid(mixed $meetingUuid): ?array {
		if ($meetingUuid === null || $meetingUuid === '') {
			return $this->formatter->error(
				code: 'invalid_arguments',
				message: 'Required argument meetingUuid is missing.'
			);
		}

		if ($this->isValidUuid(candidate: (string)$meetingUuid) === false) {
			return $this->formatter->error(
				code: 'invalid_arguments',
				message: "Invalid UUID format for meetingUuid: '{$meetingUuid}'."
			);
		}

		return null;
	}//end validateMeetingUuid()

	/**
	 * Validate the scope + limit arguments of decidesk.listOpenActionItems.
	 *
	 * @param mixed $scope The requested scope ("mine" or "all")
	 * @param int $limit The requested result limit
	 *
	 * @return array<string, mixed>|null The error envelope, or null when valid.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function validateScopeAndLimit(mixed $scope, int $limit): ?array {
		if (in_array(needle: $scope, haystack: ['mine', 'all'], strict: true) === false) {
			return $this->formatter->error(
				code: 'invalid_arguments',
				message: "Invalid scope '{$scope}'. Allowed values: mine, all."
			);
		}

		if ($limit < 1 || $limit > 50) {
			return $this->formatter->error(
				code: 'invalid_arguments',
				message: "Invalid limit {$limit}. Must be between 1 and 50."
			);
		}

		return null;
	}//end validateScopeAndLimit()

	/**
	 * Validate the limit + statusFilter arguments of decidesk.listRecentMeetings.
	 *
	 * @param int $limit The requested result limit
	 * @param mixed $statusFilter The requested lifecycle filter
	 *
	 * @return array<string, mixed>|null The error envelope, or null when valid.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function validateLimitAndStatus(int $limit, mixed $statusFilter): ?array {
		if ($limit < 1 || $limit > 20) {
			return $this->formatter->error(
				code: 'invalid_arguments',
				message: "Invalid limit {$limit}. Must be between 1 and 20."
			);
		}

		$validStatuses = ['any', 'scheduled', 'in-progress', 'closed'];
		if (in_array(needle: $statusFilter, haystack: $validStatuses, strict: true) === false) {
			return $this->formatter->error(
				code: 'invalid_arguments',
				message: "Invalid statusFilter '{$statusFilter}'. Allowed: "
					. implode(separator: ', ', array: $validStatuses) . '.'
			);
		}

		return null;
	}//end validateLimitAndStatus()

	/**
	 * Validate every argument of decidesk.addActionItem.
	 *
	 * Checks run in the documented order: meetingUuid, title, dueDate.
	 *
	 * @param array<string, mixed> $args The raw tool arguments
	 *
	 * @return array<string, mixed>|null The error envelope, or null when valid.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	public function validateActionItemArgs(array $args): ?array {
		$meetingError = $this->validateMeetingUuid(meetingUuid: ($args['meetingUuid'] ?? null));
		if ($meetingError !== null) {
			return $meetingError;
		}

		$titleError = $this->validateTitle(title: ($args['title'] ?? null));
		if ($titleError !== null) {
			return $titleError;
		}

		return $this->validateDueDate(dueDate: ($args['dueDate'] ?? null));
	}//end validateActionItemArgs()

	/**
	 * Validate the action item title (present, 3-200 characters).
	 *
	 * @param mixed $title The raw title argument
	 *
	 * @return array<string, mixed>|null The error envelope, or null when valid.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	private function validateTitle(mixed $title): ?array {
		if ($title === null || $title === '') {
			return $this->formatter->error(
				code: 'invalid_arguments',
				message: 'Required argument title is missing.'
			);
		}

		$titleLen = mb_strlen((string)$title);
		if ($titleLen < 3 || $titleLen > 200) {
			return $this->formatter->error(
				code: 'invalid_arguments',
				message: "Title must be between 3 and 200 characters (got {$titleLen})."
			);
		}

		return null;
	}//end validateTitle()

	/**
	 * Validate the optional dueDate argument.
	 *
	 * @param mixed $dueDate The raw dueDate argument
	 *
	 * @return array<string, mixed>|null The error envelope, or null when valid.
	 *
	 * @spec openspec/specs/mcp-tools/spec.md
	 */
	private function validateDueDate(mixed $dueDate): ?array {
		if ($dueDate === null || $dueDate === '') {
			return null;
		}

		if ($this->isValidDate(candidate: (string)$dueDate) === false) {
			return $this->formatter->error(
				code: 'invalid_arguments',
				message: "Invalid dueDate '{$dueDate}'. Expected ISO 8601 date (YYYY-MM-DD)."
			);
		}

		return null;
	}//end validateDueDate()
}//end class
