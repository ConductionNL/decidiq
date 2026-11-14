<?php

/**
 * Decidiq PDF Structure Inspector
 *
 * Reads the structural facts of a PDF that an accessibility scan needs: the
 * structure tree, the language, the title, the outline, the page count, how
 * much text the content streams show and how many images and figures there
 * are. A heuristic reading of the raw object graph, without any binary
 * dependency; it never renders the document.
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
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use RuntimeException;

/**
 * The structural facts of one PDF.
 *
 * Compressed streams (FlateDecode, including object streams) are inflated
 * within a fixed budget, so a hostile file cannot make the reading grow
 * without bound; past the budget the remaining streams are left unread.
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
 */
class PdfStructureInspector {

	/**
	 * The most bytes all inflated streams together may take.
	 *
	 * @var int
	 */
	public const INFLATE_BUDGET = 64 * 1024 * 1024;

	/**
	 * The most streams read.
	 *
	 * @var int
	 */
	public const MAX_STREAMS = 20000;

	/**
	 * Read the structural facts of a PDF.
	 *
	 * @param string $bytes The file's content.
	 *
	 * The facts: pages, tagged, marked, language, title, outlines,
	 * textCharacters, images, figures and figuresWithAlt.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the content is not a readable PDF.
	 *
	 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-001-heuristic-accessibility-scan-of-uploaded-pdf-documents
	 */
	public function inspect(string $bytes): array {
		if (str_starts_with(ltrim(substr($bytes, 0, 1024)), '%PDF-') === false) {
			throw new RuntimeException('The file does not start with a PDF header');
		}

		$streams = $this->inflatedStreams(bytes: $bytes);
		$body    = $bytes . "\n" . implode("\n", $streams);

		if (preg_match('/\/Type\s*\/Catalog\b/', $body) !== 1) {
			throw new RuntimeException('No document catalog was found');
		}

		$figures = $this->figures(body: $body);

		return [
			'pages' => (int)preg_match_all('/\/Type\s*\/Page(?![a-zA-Z])/', $body),
			'tagged' => preg_match('/\/StructTreeRoot\s+\d+\s+\d+\s+R/', $body) === 1,
			'marked' => preg_match('/\/MarkInfo\s*<<[^>]*\/Marked\s+true/', $body) === 1,
			'language' => $this->language(body: $body),
			'title' => $this->title(body: $body),
			'outlines' => preg_match('/\/Outlines\s+\d+\s+\d+\s+R/', $body) === 1 && preg_match('/\/First\s+\d+\s+\d+\s+R/', $body) === 1,
			'textCharacters' => $this->textCharacters(streams: $streams),
			'images' => (int)preg_match_all('/\/Subtype\s*\/Image\b/', $body),
			'figures' => $figures['figures'],
			'figuresWithAlt' => $figures['withAlt'],
		];
	}//end inspect()

	/**
	 * The decoded content of every stream, inflated where it is FlateDecode.
	 *
	 * @param string $bytes The file's content.
	 *
	 * @return list<string>
	 */
	private function inflatedStreams(string $bytes): array {
		$streams = [];
		$budget  = self::INFLATE_BUDGET;
		$offset  = 0;
		$count   = 0;
		while ($count < self::MAX_STREAMS && preg_match('/(?<!end)stream\r?\n/', $bytes, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
			$start = ($match[0][1] + strlen($match[0][0]));
			$end   = strpos($bytes, 'endstream', $start);
			if ($end === false) {
				break;
			}

			$count++;
			$offset = ($end + 9);

			$dictionary = substr($bytes, max(0, ($match[0][1] - 512)), min(512, $match[0][1]));
			$raw        = rtrim(substr($bytes, $start, ($end - $start)), "\r\n");
			if (preg_match('/\/FlateDecode/', $dictionary) !== 1) {
				$streams[] = $raw;
				continue;
			}

			if ($budget <= 0) {
				continue;
			}

			$decoded = @gzuncompress($raw, $budget);
			if ($decoded === false) {
				continue;
			}

			$budget   -= strlen($decoded);
			$streams[] = $decoded;
		}//end while

		return $streams;
	}//end inflatedStreams()

	/**
	 * The document language from the catalog's /Lang, or null.
	 *
	 * @param string $body The file with its inflated streams.
	 *
	 * @return string|null
	 */
	private function language(string $body): ?string {
		if (preg_match('/\/Lang\s*\(([^)]*)\)/', $body, $match) === 1) {
			return $this->nonEmpty(value: $match[1]);
		}

		if (preg_match('/\/Lang\s*<([0-9A-Fa-f\s]*)>/', $body, $match) === 1) {
			return $this->nonEmpty(value: $this->hexText(hex: $match[1]));
		}

		return null;
	}//end language()

	/**
	 * The document title: the Info dictionary's /Title, else the XMP dc:title.
	 *
	 * Outline items also carry /Title, so only the object the trailer names as
	 * /Info is read for it.
	 *
	 * @param string $body The file with its inflated streams.
	 *
	 * @return string|null
	 */
	private function title(string $body): ?string {
		if (preg_match('/\/Info\s+(\d+)\s+(\d+)\s+R/', $body, $ref) === 1
			&& preg_match('/(?<!\d)' . $ref[1] . '\s+' . $ref[2] . '\s+obj\s*<<(.*?)>>\s*endobj/s', $body, $info) === 1
		) {
			if (preg_match('/\/Title\s*\(((?:\\\\.|[^\\\\)])*)\)/s', $info[1], $match) === 1) {
				$title = $this->nonEmpty(value: $match[1]);
				if ($title !== null) {
					return $title;
				}
			}

			if (preg_match('/\/Title\s*<([0-9A-Fa-f\s]*)>/', $info[1], $match) === 1) {
				$title = $this->nonEmpty(value: $this->hexText(hex: $match[1]));
				if ($title !== null) {
					return $title;
				}
			}
		}

		if (preg_match('/<dc:title>(.*?)<\/dc:title>/s', $body, $match) === 1) {
			return $this->nonEmpty(value: strip_tags($match[1]));
		}

		return null;
	}//end title()

	/**
	 * How many characters the text-showing operators of the content streams show.
	 *
	 * @param list<string> $streams The decoded streams.
	 *
	 * @return int
	 */
	private function textCharacters(array $streams): int {
		$characters = 0;
		foreach ($streams as $stream) {
			if (preg_match_all('/\bBT\b(.*?)\bET\b/s', $stream, $blocks) === false) {
				continue;
			}

			foreach ($blocks[1] as $block) {
				// Literal strings, then hex strings (two hex digits per byte).
				preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)/s', $block, $literals);
				foreach ($literals[1] as $literal) {
					$characters += strlen(trim($literal));
				}

				preg_match_all('/<([0-9A-Fa-f\s]+)>/', $block, $hexes);
				foreach ($hexes[1] as $hex) {
					$characters += intdiv(strlen((string)preg_replace('/\s+/', '', $hex)), 2);
				}
			}
		}//end foreach

		return $characters;
	}//end textCharacters()

	/**
	 * Count figure structure elements and those that carry alternative text.
	 *
	 * @param string $body The file with its inflated streams.
	 *
	 * @return array{figures: int, withAlt: int}
	 */
	private function figures(string $body): array {
		$figures = 0;
		$withAlt = 0;
		if (preg_match_all('/<<((?:(?!<<|>>).)*\/S\s*\/Figure\b(?:(?!<<|>>).)*)>>/s', $body, $matches) > 0) {
			foreach ($matches[1] as $dictionary) {
				$figures++;
				if (preg_match('/\/Alt\s*(\((?:\\\\.|[^\\\\)])+\)|<[0-9A-Fa-f\s]+>)/s', $dictionary) === 1) {
					$withAlt++;
				}
			}
		}

		return ['figures' => $figures, 'withAlt' => $withAlt];
	}//end figures()

	/**
	 * Decode a PDF hex string, dropping a UTF-16 byte order mark.
	 *
	 * @param string $hex The hex digits.
	 *
	 * @return string
	 */
	private function hexText(string $hex): string {
		$hex = (string)preg_replace('/\s+/', '', $hex);
		if (strlen($hex) % 2 === 1) {
			$hex .= '0';
		}

		$raw = (string)hex2bin($hex);
		if (str_starts_with($raw, "\xFE\xFF") === true) {
			return (string)mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
		}

		return $raw;
	}//end hexText()

	/**
	 * A trimmed value, or null when nothing is left.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null
	 */
	private function nonEmpty(string $value): ?string {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end nonEmpty()
}//end class
