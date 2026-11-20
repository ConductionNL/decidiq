<?php

/**
 * Decidiq Annotation Input
 *
 * Checks and shapes the request body of an annotation for AnnotationService:
 * the anchor (type, page and the position the type needs), the editable
 * content (note text and colour) and the agenda item, meeting and document it
 * belongs to. Every refusal is an AnnotationRefusedException with the reason.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\AnnotationRefusedException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Checks and shapes an annotation request body.
 *
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
 */
class AnnotationInput {

	/**
	 * The annotation types.
	 */
	public const TYPES = ['highlight', 'sticky-note', 'drawing'];

	/**
	 * The colours.
	 */
	public const COLORS = ['yellow', 'blue', 'red', 'green'];

	/**
	 * Most highlighted areas on one highlight.
	 */
	private const MAX_QUADS = 200;

	/**
	 * Most points on one drawing.
	 */
	private const MAX_PATH = 5000;

	/**
	 * Longest note.
	 */
	private const MAX_BODY = 4000;

	/**
	 * Longest quote.
	 */
	private const MAX_QUOTE = 2000;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Resolves the agenda item and meeting as the acting user
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The anchor of a new annotation: type, page and the position the type needs.
	 *
	 * @param array<string, mixed> $data The request body
	 *
	 * @return array<string, mixed>
	 *
	 * @throws AnnotationRefusedException When the anchor is incomplete or off the page.
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
	 */
	public function anchor(array $data): array {
		$type = (string)($data['annotationType'] ?? '');
		if (in_array(needle: $type, haystack: self::TYPES, strict: true) === false) {
			throw new AnnotationRefusedException(message: 'Annotation type must be highlight, sticky-note or drawing.');
		}

		$page = $data['page'] ?? null;
		if (is_numeric($page) === false || (int)$page < 1 || (float)$page !== (float)(int)$page) {
			throw new AnnotationRefusedException(message: 'Page must be a whole number of at least 1.');
		}

		$anchor = ['annotationType' => $type, 'page' => (int)$page];

		return match ($type) {
			'highlight' => $anchor + $this->highlight(data: $data),
			'sticky-note' => $anchor + $this->stickyNote(data: $data),
			default => $anchor + $this->drawing(data: $data),
		};
	}//end anchor()

	/**
	 * The position of a highlight: its areas, and the quoted text when given.
	 *
	 * @param array<string, mixed> $data The request body
	 *
	 * @return array<string, mixed>
	 *
	 * @throws AnnotationRefusedException When an area is malformed or the quote too long.
	 */
	private function highlight(array $data): array {
		$position = ['quads' => $this->points(value: ($data['quads'] ?? null), size: 8, max: self::MAX_QUADS, label: 'quads')];
		$quote    = $data['quote'] ?? null;
		if (is_string($quote) === true && trim($quote) !== '') {
			$position['quote'] = $this->bounded(text: $quote, max: self::MAX_QUOTE, label: 'Quote');
		}

		return $position;
	}//end highlight()

	/**
	 * The position of a sticky note: one point on the page.
	 *
	 * @param array<string, mixed> $data The request body
	 *
	 * @return array<string, mixed>
	 *
	 * @throws AnnotationRefusedException When the point is missing or off the page.
	 */
	private function stickyNote(array $data): array {
		$point = $data['point'] ?? null;
		if (is_array($point) === false
			|| $this->normalised(value: ($point['x'] ?? null)) === false
			|| $this->normalised(value: ($point['y'] ?? null)) === false
		) {
			throw new AnnotationRefusedException(message: 'A sticky note needs a point with x and y between 0 and 1.');
		}

		return ['point' => ['x' => (float)$point['x'], 'y' => (float)$point['y']]];
	}//end stickyNote()

	/**
	 * The position of a drawing: a path of at least two points.
	 *
	 * @param array<string, mixed> $data The request body
	 *
	 * @return array<string, mixed>
	 *
	 * @throws AnnotationRefusedException When the path is malformed or too short.
	 */
	private function drawing(array $data): array {
		$path = $this->points(value: ($data['path'] ?? null), size: 2, max: self::MAX_PATH, label: 'path');
		if (count($path) < 2) {
			throw new AnnotationRefusedException(message: 'A drawing needs at least two points.');
		}

		return ['path' => $path];
	}//end drawing()


	/**
	 * The editable content: note text and colour.
	 *
	 * @param array<string, mixed> $data   The request body
	 * @param array<string, mixed> $stored The stored annotation, empty when new
	 *
	 * @return array<string, mixed>
	 *
	 * @throws AnnotationRefusedException When the text is too long or the colour unknown.
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
	 */
	public function content(array $data, array $stored): array {
		$content = [];
		if (array_key_exists('bodyText', $data) === true) {
			$content['bodyText'] = $this->bounded(text: (string)$data['bodyText'], max: self::MAX_BODY, label: 'Note');
		}

		$color = (string)($data['color'] ?? ($stored['color'] ?? 'yellow'));
		if (in_array(needle: $color, haystack: self::COLORS, strict: true) === false) {
			throw new AnnotationRefusedException(message: 'Colour must be yellow, blue, red or green.');
		}

		$content['color'] = $color;

		return $content;
	}//end content()

	/**
	 * The agenda item, meeting and document the annotation belongs to.
	 *
	 * A meeting not given is taken from the agenda item. The meeting must be
	 * one the author can read, so a note cannot be shared with a body the author
	 * has nothing to do with.
	 *
	 * @param array<string, mixed> $data The request body
	 *
	 * @return array<string, string>
	 *
	 * @throws AnnotationRefusedException When a reference does not resolve.
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
	 */
	public function context(array $data): array {
		$context = [];
		foreach (['document', 'agendaItem', 'meeting'] as $field) {
			$value = trim((string)($data[$field] ?? ''));
			if ($value !== '') {
				$context[$field] = $value;
			}
		}

		if (isset($context['agendaItem']) === true) {
			$item = $this->readable(schema: 'agenda-item', id: $context['agendaItem']);
			if ($item === null) {
				throw new AnnotationRefusedException(message: 'The agenda item was not found.');
			}

			$context['meeting'] = ($context['meeting'] ?? trim((string)($item['meeting'] ?? '')));
			if ($context['meeting'] === '') {
				unset($context['meeting']);
			}
		}

		if (isset($context['meeting']) === true && $this->readable(schema: 'meeting', id: $context['meeting']) === null) {
			throw new AnnotationRefusedException(message: 'The meeting was not found.');
		}

		return $context;
	}//end context()

	/**
	 * Read an object as the acting user, null when it resolves to nothing.
	 *
	 * @param string $schema The schema slug
	 * @param string $id     The object id
	 *
	 * @return array<string, mixed>|null
	 */
	private function readable(string $schema, string $id): ?array {
		try {
			$entity = $this->objectService->find(id: $id, options: new ObjectFindOptions(register: 'decidiq', schema: $schema));
		} catch (DoesNotExistException) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $entity->getObject();
	}//end readable()

	/**
	 * A list of fixed-size point arrays with every value between 0 and 1.
	 *
	 * @param mixed  $value The request value
	 * @param int    $size  Numbers per entry
	 * @param int    $max   Most entries
	 * @param string $label The field name, for the message
	 *
	 * @return list<list<float>>
	 *
	 * @throws AnnotationRefusedException When the shape or a value is wrong.
	 */
	private function points(mixed $value, int $size, int $max, string $label): array {
		if (is_array($value) === false || $value === [] || count($value) > $max) {
			throw new AnnotationRefusedException(message: ucfirst($label) . ' must hold between 1 and ' . $max . ' entries.');
		}

		$points = [];
		foreach ($value as $entry) {
			if (is_array($entry) === false || count($entry) !== $size) {
				throw new AnnotationRefusedException(message: 'Each entry of ' . $label . ' must hold ' . $size . ' numbers.');
			}

			$numbers = [];
			foreach ($entry as $number) {
				if ($this->normalised(value: $number) === false) {
					throw new AnnotationRefusedException(message: 'Positions in ' . $label . ' must lie between 0 and 1.');
				}

				$numbers[] = (float)$number;
			}

			$points[] = $numbers;
		}

		return $points;
	}//end points()

	/**
	 * Whether a value is a number between 0 and 1.
	 *
	 * @param mixed $value The value
	 *
	 * @return bool
	 */
	private function normalised(mixed $value): bool {
		return (is_int($value) === true || is_float($value) === true) && $value >= 0 && $value <= 1;
	}//end normalised()

	/**
	 * Text no longer than a maximum.
	 *
	 * @param string $text  The text
	 * @param int    $max   Most characters
	 * @param string $label The field name, for the message
	 *
	 * @return string
	 *
	 * @throws AnnotationRefusedException When it is longer.
	 */
	private function bounded(string $text, int $max, string $label): string {
		if (mb_strlen($text) > $max) {
			throw new AnnotationRefusedException(message: $label . ' can be at most ' . $max . ' characters.');
		}

		return $text;
	}//end bounded()
}//end class
