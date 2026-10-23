<?php

/**
 * Decidiq public meeting and decision records
 *
 * The data behind the portal's meetings and decisions pages. A visitor only
 * ever reads what staff published: the PublicationPayload objects whose
 * publicationDate has passed and that are not withdrawn, exactly the rows
 * OpenRegister's public read rule on that schema admits. Nothing is read
 * from the source meeting or decision, so an unpublished one never shows.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
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
 * @spec openspec/changes/portal-public-council-records/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IL10N;
use OCP\L10N\IFactory;

/**
 * Lists the published meetings and decisions, and builds one of each as a record.
 *
 * @spec openspec/changes/portal-public-council-records/specs/portal-contribution/spec.md
 */
class PublicMeetingRecords {

	/**
	 * The register every object lives in.
	 */
	private const REGISTER = 'decidiq';

	/**
	 * How many payloads one list reads.
	 */
	private const LIMIT = 500;

	/**
	 * A decision's outcome as the reader sees it.
	 *
	 * @var array<string, string>
	 */
	private const OUTCOMES = [
		'adopted' => 'Adopted',
		'rejected' => 'Rejected',
	];

	/**
	 * Decidiq's catalogue in the reader's language.
	 *
	 * @var IL10N
	 */
	private readonly IL10N $l10n;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service
	 * @param IFactory               $l10nFactory   The reader's language
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		IFactory $l10nFactory,
	) {
		$this->l10n = $l10nFactory->get('decidiq');
	}//end __construct()

	/**
	 * The published meetings as `{id, title, subtitle}`, the latest first.
	 *
	 * @return list<array<string, string>>
	 *
	 * @spec openspec/changes/portal-public-council-records/specs/portal-contribution/spec.md#requirement-req-pcr-001-a-visitor-finds-the-published-meetings-with-their-agenda
	 */
	public function meetings(): array {
		$entries = [];
		foreach ($this->published(oriType: 'Vergadering', dateField: 'meetingDate') as $id => $meeting) {
			$entries[] = [
				'id' => $id,
				'title' => $this->join(parts: [(string)($meeting['title'] ?? ''), $this->date(value: (string)($meeting['meetingDate'] ?? ''))], glue: ' '),
				'subtitle' => $this->join(parts: [(string)($meeting['bodyName'] ?? ''), (string)($meeting['location'] ?? '')], glue: ', '),
			];
		}

		return $entries;
	}//end meetings()

	/**
	 * One published meeting with its agenda, or null when it is not published.
	 *
	 * @param string $id The payload id
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/portal-public-council-records/specs/portal-contribution/spec.md#requirement-req-pcr-001-a-visitor-finds-the-published-meetings-with-their-agenda
	 */
	public function meeting(string $id): ?array {
		$meeting = ($this->published(oriType: 'Vergadering', dateField: 'meetingDate')[$id] ?? null);
		if ($meeting === null) {
			return null;
		}

		$items = array_values(
			array_filter(
				(array)($meeting['agendaItems'] ?? []),
				static fn ($item): bool => is_array($item) === true && trim((string)($item['title'] ?? '')) !== ''
			)
		);
		usort($items, static fn (array $a, array $b): int => ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0)));
		$rows = array_map(static fn (array $item): array => ['order' => (string)($item['order'] ?? ''), 'item' => (string)$item['title']], $items);

		$time = strtotime((string)($meeting['meetingDate'] ?? ''));
		$summary = [['label' => $this->l10n->t('Date'), 'value' => $this->date(value: (string)($meeting['meetingDate'] ?? ''))]];
		if ($time !== false) {
			$summary[] = ['label' => $this->l10n->t('Time'), 'value' => (string)$this->l10n->l('time', $time, ['width' => 'short'])];
		}

		if (trim((string)($meeting['location'] ?? '')) !== '') {
			$summary[] = ['label' => $this->l10n->t('Location'), 'value' => (string)$meeting['location']];
		}

		$record = [
			'title' => (string)($meeting['title'] ?? ''),
			'subtitle' => (string)($meeting['bodyName'] ?? ''),
			'summary' => $summary,
			'columns' => [
				['key' => 'order', 'label' => $this->l10n->t('No.')],
				['key' => 'item', 'label' => $this->l10n->t('Agenda item')],
			],
			'rows' => $rows,
		];
		if ($rows === []) {
			$record['note'] = $this->l10n->t('The agenda of this meeting has not been published yet.');
		}

		return $record;
	}//end meeting()

	/**
	 * The published decisions as `{id, title, subtitle}`, the latest first.
	 *
	 * @return list<array<string, string>>
	 *
	 * @spec openspec/changes/portal-public-council-records/specs/portal-contribution/spec.md#requirement-req-pcr-002-a-visitor-reads-the-published-decisions-with-their-outcome
	 */
	public function decisions(): array {
		$entries = [];
		foreach ($this->published(oriType: 'Besluit', dateField: 'decisionDate') as $id => $decision) {
			$entries[] = [
				'id' => $id,
				'title' => (string)($decision['title'] ?? ''),
				'subtitle' => $this->join(
					parts: [(string)($decision['bodyName'] ?? ''), $this->date(value: (string)($decision['decisionDate'] ?? ''))],
					glue: ', '
				),
			];
		}

		return $entries;
	}//end decisions()

	/**
	 * One published decision with its outcome and vote totals, or null when it is not published.
	 *
	 * Only the totals the payload carries are shown; who voted how is on the
	 * members' voting records, under the stricter rule of the ORI votes.
	 *
	 * @param string $id The payload id
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/portal-public-council-records/specs/portal-contribution/spec.md#requirement-req-pcr-002-a-visitor-reads-the-published-decisions-with-their-outcome
	 */
	public function decision(string $id): ?array {
		$decision = ($this->published(oriType: 'Besluit', dateField: 'decisionDate')[$id] ?? null);
		if ($decision === null) {
			return null;
		}

		$summary = [['label' => $this->l10n->t('Date'), 'value' => $this->date(value: (string)($decision['decisionDate'] ?? ''))]];
		$outcome = (self::OUTCOMES[(string)($decision['outcome'] ?? '')] ?? null);
		if ($outcome !== null) {
			$summary[] = ['label' => $this->l10n->t('Outcome'), 'value' => $this->l10n->t($outcome)];
		}

		// The flow always writes totals; all zero means the decision had no vote.
		$totals = $decision['voteTotals'] ?? null;
		if (is_array($totals) === true && ((int)($totals['for'] ?? 0) + (int)($totals['against'] ?? 0) + (int)($totals['abstain'] ?? 0)) > 0) {
			$summary[] = ['label' => $this->l10n->t('For'), 'value' => (string)(int)($totals['for'] ?? 0)];
			$summary[] = ['label' => $this->l10n->t('Against'), 'value' => (string)(int)($totals['against'] ?? 0)];
			$summary[] = ['label' => $this->l10n->t('Abstained'), 'value' => (string)(int)($totals['abstain'] ?? 0)];
		}

		return [
			'title' => (string)($decision['title'] ?? ''),
			'subtitle' => (string)($decision['bodyName'] ?? ''),
			'summary' => $summary,
			'columns' => [],
			'rows' => [],
			'note' => (string)($decision['text'] ?? ''),
		];
	}//end decision()

	/**
	 * The live payloads of one ORI type by id, the latest first: published
	 * (publicationDate in the past) and not withdrawn (no depublicationDate,
	 * or one still ahead). The same test OpenRegister's public read rule makes.
	 *
	 * @param string $oriType   `Vergadering` or `Besluit`
	 * @param string $dateField The field to sort on
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function published(string $oriType, string $dateField): array {
		$entities = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => 'publication-payload', 'oriType' => $oriType], 'limit' => self::LIMIT],
			_rbac: false,
			_multitenancy: false
		);

		$now = time();
		$live = [];
		foreach ($entities as $entity) {
			$payload = $entity->jsonSerialize();
			$id = ($payload['id'] ?? ($payload['@self']['id'] ?? null));
			if (is_string($id) === false || $id === '' || ($payload['oriType'] ?? null) !== $oriType) {
				continue;
			}

			$published = strtotime((string)($payload['publicationDate'] ?? ''));
			$withdrawn = strtotime((string)($payload['depublicationDate'] ?? ''));
			if ($published === false || $published > $now || ($withdrawn !== false && $withdrawn <= $now)) {
				continue;
			}

			$live[$id] = $payload;
		}

		uasort($live, static fn (array $a, array $b): int => strcmp((string)($b[$dateField] ?? ''), (string)($a[$dateField] ?? '')));

		return $live;
	}//end published()

	/**
	 * A date as the reader reads it ("9 oktober 2026" in Dutch), '' when empty.
	 *
	 * @param string $value An ISO date or date-time
	 *
	 * @return string
	 */
	private function date(string $value): string {
		$time = strtotime($value);
		if ($value === '' || $time === false) {
			return '';
		}

		return (string)$this->l10n->l('date', $time, ['width' => 'long']);
	}//end date()

	/**
	 * The non-empty parts joined.
	 *
	 * @param list<string> $parts The parts
	 * @param string       $glue  The separator
	 *
	 * @return string
	 */
	private function join(array $parts, string $glue): string {
		return implode($glue, array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));
	}//end join()
}//end class
