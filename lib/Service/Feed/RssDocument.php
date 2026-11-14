<?php

/**
 * An RSS 2.0 document: a channel and its items.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\Feed
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service\Feed;

use DateTimeImmutable;
use DateTimeInterface;
use DOMDocument;
use DOMElement;
use Exception;

/**
 * Renders a channel title, description and items as RSS 2.0 XML.
 *
 * Every value is written as a text node, so markup in a title or a
 * description is escaped rather than interpreted.
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */
class RssDocument {

	/**
	 * The media type of an RSS document.
	 */
	public const CONTENT_TYPE = 'application/rss+xml; charset=utf-8';

	/**
	 * Render a channel.
	 *
	 * @param string                                  $title       The channel title.
	 * @param string                                  $description The channel description.
	 * @param array<int, array<string, string|null>> $items       Items with title, guid, description, pubDate and category.
	 *
	 * @return string The XML.
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function render(string $title, string $description, array $items): string {
		$xml = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$xml->formatOutput = true;
		$rss = $xml->createElement(localName: 'rss');
		$rss->setAttribute(qualifiedName: 'version', value: '2.0');
		$xml->appendChild(node: $rss);

		$channel = $xml->createElement(localName: 'channel');
		$rss->appendChild(node: $channel);
		self::text(xml: $xml, parent: $channel, name: 'title', value: $title);
		self::text(xml: $xml, parent: $channel, name: 'description', value: $description);

		foreach ($items as $item) {
			$element = $xml->createElement(localName: 'item');
			$channel->appendChild(node: $element);
			self::text(xml: $xml, parent: $element, name: 'title', value: ($item['title'] ?? ''));
			self::text(xml: $xml, parent: $element, name: 'description', value: ($item['description'] ?? null));
			self::text(xml: $xml, parent: $element, name: 'category', value: ($item['category'] ?? null));
			self::text(xml: $xml, parent: $element, name: 'pubDate', value: self::rfc822(value: ($item['pubDate'] ?? null)));
			if (($item['guid'] ?? null) !== null) {
				$guid = self::text(xml: $xml, parent: $element, name: 'guid', value: $item['guid']);
				$guid?->setAttribute(qualifiedName: 'isPermaLink', value: 'false');
			}
		}

		return (string)$xml->saveXML();
	}//end render()

	/**
	 * Append a text element, or nothing when the value is null.
	 *
	 * @param DOMDocument $xml    The document.
	 * @param DOMElement  $parent Where to append it.
	 * @param string      $name   The element name.
	 * @param string|null $value  The text.
	 *
	 * @return DOMElement|null The element appended.
	 */
	private static function text(DOMDocument $xml, DOMElement $parent, string $name, ?string $value): ?DOMElement {
		if ($value === null) {
			return null;
		}

		$element = $xml->createElement(localName: $name);
		$element->appendChild(node: $xml->createTextNode(data: $value));
		$parent->appendChild(node: $element);

		return $element;
	}//end text()

	/**
	 * An ISO 8601 moment as the RFC 822 date RSS uses, or null when it does not parse.
	 *
	 * @param string|null $value The moment.
	 *
	 * @return string|null
	 */
	private static function rfc822(?string $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->format(DateTimeInterface::RSS);
		} catch (Exception $e) {
			return null;
		}
	}//end rfc822()
}//end class
