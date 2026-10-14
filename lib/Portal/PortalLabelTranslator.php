<?php

/**
 * Decidiq portal label translator
 *
 * Puts the words a portal visitor reads in a contribution manifest into the
 * reader's language. Portaliq shows a contribution's `label` exactly as the
 * app sends it, so the app answers in the reader's language itself, through
 * its own catalogue (l10n/<lang>.json). Nextcloud picks that language per
 * request: the account's language for a signed-in resident, the browser's
 * Accept-Language for a visitor without one.
 *
 * @category Portal
 * @package  OCA\Decidiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-labels-in-the-readers-language/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Portal;

use OCP\IL10N;

/**
 * Translates the visible strings of a portal manifest, and nothing else.
 *
 * Identifiers, field names, schema slugs and filter values are never touched:
 * portaliq matches on them.
 *
 * @spec openspec/changes/portal-labels-in-the-readers-language/specs/portal-contribution/spec.md
 */
class PortalLabelTranslator {

	/**
	 * The manifest keys whose string value a reader sees.
	 *
	 * @var array<int, string>
	 */
	private const VISIBLE_KEYS = ['label', 'group'];

	/**
	 * Manifest keys that hold machine values only; nothing below them is read.
	 *
	 * @var array<int, string>
	 */
	private const MACHINE_KEYS = ['fields', 'defaults', 'defaultFilters', 'defaultSort', 'parentConstraint', 'set', 'filter'];

	/**
	 * Constructor.
	 *
	 * @param IL10N|null $l10n Decidiq's catalogue in the reader's language, or null to keep the English source.
	 */
	public function __construct(
		private readonly ?IL10N $l10n=null,
	) {
	}//end __construct()

	/**
	 * The manifest with every visible string in the reader's language.
	 *
	 * @param array<array-key, mixed> $manifest The manifest, in English.
	 *
	 * @return array<array-key, mixed> The same manifest, its visible strings translated.
	 *
	 * @spec openspec/changes/portal-labels-in-the-readers-language/specs/portal-contribution/spec.md
	 */
	public function translate(array $manifest): array {
		if ($this->l10n === null) {
			return $manifest;
		}

		foreach ($manifest as $key => $value) {
			if (is_array($value) === true && in_array($key, self::MACHINE_KEYS, true) === false) {
				$manifest[$key] = $this->translate(manifest: $value);
				continue;
			}

			if (is_string($value) === true && $value !== '' && in_array($key, self::VISIBLE_KEYS, true) === true) {
				$manifest[$key] = $this->l10n->t($value);
			}
		}

		return $manifest;
	}//end translate()
}//end class
