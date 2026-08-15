<?php

/**
 * Translates the Dutch ENUM VALUES stored in this app's shard tables.
 *
 * The schema edit changes the DECLARATION; every row already written still
 * holds the Dutch string, and a filter on the new value then returns NULL
 * rather than an error — so the feature reports "nothing found" instead of
 * failing. This rewrites the stored rows.
 *
 * Scoped by COLUMN, never by the value alone. The same string means different
 * things on different columns, and a migration matching on the string would
 * corrupt every column that shares it.
 *
 * NOT migrated, deliberately: `oriType` (`Besluit`/`Vergadering`/`Verslag`).
 * That is the ORI standard's vocabulary and decidesk's own OriSerializer
 * consumes it — a mapping is configuration, so the standard's terms stay in
 * the standard's language.
 *
 * The Dutch LEGAL terms this migration replaces are not lost: each is written
 * into l10n/nl.json against its English key, so a Dutch-rendered UI still shows
 * `Splitsingsakte`, `Statuten`, `Verordening` and the rest.
 *
 * Idempotent: an already-migrated row matches no WHERE clause.
 *
 * @category  Repair
 * @package   OCA\Decidesk\Repair
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Decidesk\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Migrates stored Dutch enum values to their English spelling.
 */
class RenameDutchDecideskValues implements IRepairStep {

	/**
	 * Property name => old value => new value.
	 *
	 * @var array<string, array<string, string>>
	 */
	public const VALUE_MAP = [
		'ancillaryPositionDisclosureDefault' => [
			'intern' => 'internal',
			'openbaar' => 'public',
		],
		'beëdigingsType' => [
			'belofte' => 'affirmation',
			'eed' => 'oath',
		],
		'category' => [
			'brief-inwoner' => 'letter-resident',
			'brief-organisatie' => 'letter-organisation',
			'collegestuk' => 'executive-board-paper',
			'gemeentewet' => 'municipalities-act',
			'overig' => 'other',
			'petitie' => 'petition',
			'uitnodiging' => 'invitation',
			'woo-absoluut' => 'woo-absolute',
			'woo-relatief' => 'woo-relative',
		],
		'countersignStatus' => [
			'getekend' => 'signed',
			'geweigerd' => 'refused',
			'ongetekend' => 'unsigned',
		],
		'signatureStatus' => [
			'getekend' => 'signed',
			'geweigerd' => 'refused',
			'ongetekend' => 'unsigned',
		],
		'revocationSignatureStatus' => [
			'getekend' => 'signed',
			'geweigerd' => 'refused',
			'ongetekend' => 'unsigned',
		],
		'decision' => [
			'aanvaard' => 'accepted',
			'geweigerd' => 'refused',
			'overgedragen' => 'transferred',
		],
		'decisionCategory' => [
			'decharge' => 'discharge',
			'jaarrekening' => 'annual-accounts',
			'machtiging-boven-drempel' => 'authorisation-above-threshold',
			'mjop-vaststelling' => 'mjop-adoption',
			'overige' => 'other',
			'reservefonds-dotatie' => 'reserve-fund-contribution',
			'wijziging-huishoudelijk-reglement' => 'amendment-internal-regulations',
		],
		'decisionOutcome' => [
			'afwijkend-van-advies' => 'contrary-to-advice',
			'conform-advies' => 'in-line-with-advice',
			'conform-instemming' => 'in-line-with-consent',
			'niet-doorgezet' => 'not-pursued',
		],
		'endReason' => [
			'einde-raadslidmaatschap' => 'end-of-council-membership',
			'einde-termijn' => 'end-of-term',
			'ontslag-op-eigen-verzoek' => 'resignation',
			'overlijden' => 'death',
			'verhuizing' => 'relocation',
		],
		'expectedType' => [
			'begrotingsstuk' => 'budget-document',
			'raadsinformatiebrief' => 'council-information-letter',
			'raadsvoorstel' => 'council-proposal',
			'themabijeenkomst' => 'theme-session',
		],
		'imposedBy' => [
			'college' => 'executive-board',
		],
		'ownerType' => [
			'college' => 'executive-board',
			'griffie' => 'council-registry',
			'portefeuillehouder' => 'portfolio-holder',
		],
		'interpellationSupportThresholdType' => [
			'aantal' => 'count',
			'breukdeel' => 'fraction',
		],
		'objectType' => [
			'besluitenlijst' => 'decision-list',
			'motie' => 'motion',
			'raadsinformatiebrief' => 'council-information-letter',
			'regeling' => 'arrangement',
			'toezegging' => 'commitment',
		],
		'position' => [
			'geen-zienswijze' => 'no-view',
			'negatief' => 'negative',
			'positief' => 'positive',
			'positief-met-kanttekeningen' => 'positive-with-reservations',
		],
		'tenor' => [
			'geen-advies' => 'no-advice',
			'negatief' => 'negative',
			'positief' => 'positive',
			'positief-met-kanttekeningen' => 'positive-with-reservations',
		],
		'processing' => [
			'gedeeltelijk-overgenomen' => 'partly-adopted',
			'niet-overgenomen' => 'not-adopted',
			'overgenomen' => 'adopted',
		],
		'rappelStatus' => [
			'geen' => 'none',
			'rappel-3-maanden' => 'reminder-3-months',
			'rappel-6-maanden' => 'reminder-6-months',
		],
		'responseOutcome' => [
			'advies-met-voorwaarden' => 'advice-with-conditions',
			'instemming-geweigerd' => 'consent-refused',
			'instemming-verleend' => 'consent-granted',
			'negatief-advies' => 'negative-advice',
			'positief-advies' => 'positive-advice',
		],
		'routingAdvice' => [
			'betrekken-bij-agendapunt' => 'include-with-agenda-item',
			'in-handen-college-ter-afdoening' => 'referred-to-executive-board-for-disposal',
			'in-handen-college-ter-voorbereiding' => 'referred-to-executive-board-for-preparation',
			'voor-kennisgeving-aannemen' => 'noted-for-information',
		],
		'senderType' => [
			'natuurlijk-persoon' => 'natural-person',
		],
		'stepType' => [
			'account-koppeling' => 'account-linking',
			'beediging' => 'swearing-in',
			'exit-bevestiging' => 'exit-confirmation',
			'fractie-toewijzing' => 'political-group-assignment',
			'groepen-intrekken' => 'revoke-groups',
			'groepen-toewijzen' => 'assign-groups',
			'introductiepakket' => 'induction-pack',
			'lidmaatschap-beeindigen' => 'end-membership',
			'nevenfuncties-intake' => 'ancillary-positions-intake',
			'persoonsgegevens-notitie' => 'personal-data-note',
		],
		'subjectType' => [
			'begrotingswijziging' => 'budget-amendment',
			'jaarrekening' => 'annual-accounts',
			'kadernota' => 'framework-memorandum',
			'ontwerpbegroting' => 'draft-budget',
			'overig' => 'other',
			'toetreding-uittreding' => 'accession-withdrawal',
			'wijziging-regeling' => 'amendment-of-arrangement',
		],
		'trigger' => [
			'individueel' => 'individual',
			'nieuw-lid' => 'new-member',
			'raadswisseling-batch' => 'council-turnover-batch',
			'tussentijdse-opvolging' => 'interim-succession',
		],
		'type' => [
			'beleidsregel' => 'policy-rule',
			'delegatie' => 'delegation',
			'directiestatuut' => 'management-charter',
			'geschenk' => 'gift',
			'huishoudelijk-reglement' => 'internal-regulations',
			'instemmingsverzoek' => 'consent-request',
			'machtiging' => 'authorisation',
			'mandaat' => 'mandate',
			'nadere-regel' => 'detailed-rule',
			'orgaan' => 'body',
			'reglement' => 'regulations',
			'reglement-van-orde' => 'rules-of-procedure',
			'splitsingsakte' => 'deed-of-division',
			'statuten' => 'articles-of-association',
			'statuut-extern' => 'external-charter',
			'uitnodiging' => 'invitation',
			'verordening' => 'by-law',
			'volmacht' => 'power-of-attorney',
		],
		'verdict' => [
			'afkeurend' => 'adverse',
			'goedkeurend' => 'approving',
			'met-voorbehoud' => 'qualified',
		],
		'lifecycle' => [
			'aangehouden' => 'deferred',
			'advies-uitgebracht' => 'advice-issued',
			'afgedaan' => 'disposed',
			'afgerond' => 'completed',
			'afgewezen' => 'rejected',
			'beantwoord' => 'answered',
			'behandeld' => 'handled',
			'bekrachtigd' => 'ratified',
			'benoemd' => 'appointed',
			'besluit-ontvangen' => 'decision-received',
			'betrokken-bij-behandeling' => 'involved-in-handling',
			'beëindigd' => 'ended',
			'concept' => 'draft',
			'geagendeerd' => 'agendised',
			'gemeld' => 'reported',
			'gepland' => 'planned',
			'gerealiseerd' => 'realised',
			'geregistreerd' => 'registered',
			'gesloten' => 'closed',
			'gestart' => 'started',
			'gesteld' => 'put',
			'in-behandeling' => 'in-progress',
			'in-uitvoering' => 'in-execution',
			'ingediend' => 'submitted',
			'ingepland' => 'scheduled',
			'ingetrokken' => 'withdrawn',
			'intern' => 'internal',
			'niet-behandeld' => 'not-handled',
			'niet-benoemd' => 'not-appointed',
			'niet-uitgebracht' => 'not-issued',
			'ontvangen' => 'received',
			'openbaar' => 'public',
			'opgeheven' => 'dissolved',
			'opgelegd' => 'imposed',
			'routering-vastgesteld' => 'routing-determined',
			'toegelaten' => 'admitted',
			'vastgesteld' => 'adopted',
			'verantwoord' => 'accounted-for',
			'verschoven' => 'postponed',
			'vervallen' => 'lapsed',
			'verwerkt' => 'processed',
			'verzonden' => 'sent',
		],
		'status' => [
			'afgerond' => 'completed',
			'concept' => 'draft',
			'geldend' => 'in-force',
			'geopend' => 'opened',
			'gepland' => 'planned',
			'in-behandeling' => 'in-progress',
			'in-uitvoering' => 'in-execution',
			'in-voorbereiding' => 'in-preparation',
			'in-werking' => 'in-effect',
			'ingediend' => 'submitted',
			'ingetrokken' => 'withdrawn',
			'niet-ingediend' => 'not-submitted',
			'overgeslagen' => 'skipped',
			'stukken-ontvangen' => 'documents-received',
			'uitstaand' => 'outstanding',
			'van-kracht' => 'effective',
			'vastgesteld' => 'adopted',
			'vervallen' => 'lapsed',
			'vervangen' => 'replaced',
			'verwerkt' => 'processed',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Translate stored Dutch Decidesk enum values';
	}//end getName()

	/**
	 * Convert a property name to the column MagicMapper materialised.
	 *
	 * Mirrors `MagicMapper::sanitizeColumnName()`, which applies ONLY the
	 * ([a-z0-9])([A-Z]) boundary — no acronym rule. A column name spelled any
	 * other way matches nothing and the migration is a silent no-op.
	 *
	 * @param string $name Property name.
	 *
	 * @return string Column name.
	 */
	private function columnFor(string $name): string {
		$column = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
		$column = strtolower((string)$column);
		$column = preg_replace('/[^a-z0-9_]/', '_', $column);
		$column = preg_replace('/_+/', '_', (string)$column);
		return rtrim((string)$column, '_');
	}//end columnFor()

	/**
	 * Rewrite the stored values, one column at a time.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 */
	public function run(IOutput $output): void {
		$tables = $this->shardTables();
		if ($tables === []) {
			$output->info('RenameDutchDecideskValues: no Decidesk shard tables on this install; nothing to do.');
			return;
		}

		$updated = 0;
		foreach ($tables as $table) {
			$columns = $this->columnsOf(table: $table);
			foreach (self::VALUE_MAP as $property => $values) {
				$column = $this->columnFor(name: $property);
				if (in_array($column, $columns, true) === false) {
					continue;
				}

				foreach ($values as $old => $new) {
					$updated += $this->rewrite(table: $table, column: $column, old: $old, new: $new);
				}
			}
		}

		$output->info(sprintf('RenameDutchDecideskValues: %d row value(s) translated.', $updated));
	}//end run()

	/**
	 * Rewrite one value in one column.
	 *
	 * @param string $table  Shard table.
	 * @param string $column Column name.
	 * @param string $old    Stored Dutch value.
	 * @param string $new    English replacement.
	 *
	 * @return int Rows affected.
	 */
	private function rewrite(string $table, string $column, string $old, string $new): int {
		$sql = 'UPDATE ' . $this->quote(identifier: $table)
			. ' SET ' . $this->quote(identifier: $column) . ' = ?'
			. ' WHERE ' . $this->quote(identifier: $column) . ' = ?';

		try {
			return $this->db->executeStatement($sql, [$new, $old]);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchDecideskValues: value rewrite failed.',
				['table' => $table, 'column' => $column, 'exception' => $e->getMessage()]
			);
			return 0;
		}
	}//end rewrite()

	/**
	 * Discover this app's shard tables.
	 *
	 * @return array<int, string>
	 */
	private function shardTables(): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
			);
			$stmt->bindValue('pattern', '%openregister\_table\_%');
			$stmt->execute();
			$rows = $stmt->fetchAll();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchDecideskValues: could not list tables; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		return array_map(static fn (array $r): string => (string)$r['table_name'], $rows);
	}//end shardTables()

	/**
	 * Read a table's column names.
	 *
	 * @param string $table Table name.
	 *
	 * @return array<int, string>
	 */
	private function columnsOf(string $table): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT column_name FROM information_schema.columns WHERE table_name = :table'
			);
			$stmt->bindValue('table', $table);
			$stmt->execute();
			$rows = $stmt->fetchAll();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchDecideskValues: could not read columns; skipping table.',
				['table' => $table, 'exception' => $e->getMessage()]
			);
			return [];
		}

		return array_map(static fn (array $r): string => (string)$r['column_name'], $rows);
	}//end columnsOf()

	/**
	 * Quote an identifier for the active platform.
	 *
	 * @param string $identifier Table or column name.
	 *
	 * @return string
	 */
	private function quote(string $identifier): string {
		return $this->db->getDatabasePlatform()->quoteSingleIdentifier($identifier);
	}//end quote()
}//end class
