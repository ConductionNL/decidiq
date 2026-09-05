<?php
/**
 * Decidiq ReadsLegacyRows.
 *
 * The four things every supersession migration in this app needs and had
 * written out for itself: read a superseded schema's rows, normalise an
 * OpenRegister entity to an array, and resolve a governance-body reference that
 * a seed wrote as a slug where the target schema wants a uuid.
 *
 * 🔴 EXTRACTED, NOT INVENTED. These bodies were byte-identical in
 * MigrateVveToBodyConfiguration, MigrateKascommissieToAuditStatement and
 * MigrateQuestionsToAgendaItems — including the two comments that record what
 * they cost to get right. Three copies meant three places to fix the next time
 * OpenRegister changes how a slug resolves, and the copies had already started
 * to drift in their signatures.
 *
 * @category Migration
 * @package  OCA\Decidiq\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Migration;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shared reads for the migrations that copy a superseded schema's rows.
 *
 * The using class must declare a `REGISTER` constant naming the register these
 * schemas belong to, and expose a logger through {@see self::migrationLogger()}.
 *
 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md
 */
trait ReadsLegacyRows {
	/**
	 * The logger the shared reads report through.
	 *
	 * Declared rather than assumed: a trait cannot require a promoted
	 * constructor property, and reaching straight into `$this->logger` would
	 * bind this trait to one spelling of a field it does not own.
	 *
	 * @return LoggerInterface The logger.
	 */
	abstract protected function migrationLogger(): LoggerInterface;

	/**
	 * Every row of one schema, as arrays.
	 *
	 * A schema that does not exist is not an error here: a supersession
	 * migration runs on installs that never had the legacy schema, and on those
	 * "nothing to migrate" is the correct and expected outcome.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $schema        The schema slug to read.
	 * @param int    $limit         How many rows to read at most.
	 *
	 * @return array<int, array<string,mixed>> The rows.
	 */
	protected function readRows(object $objectService, string $schema, int $limit = 1000): array {
		try {
			$objectService->setRegister(self::REGISTER);
			$objectService->setSchema($schema);
			$found = $objectService->findAll(['limit' => $limit]);
		} catch (Throwable $e) {
			$this->migrationLogger()->info(
				'Decidiq: no rows for a superseded schema',
				['schema' => $schema, 'error' => $e->getMessage()]
			);
			return [];
		}

		$rows = [];
		foreach (($found ?? []) as $entity) {
			$object = $this->toArray(entity: $entity);
			if ($object !== null) {
				$rows[] = $object;
			}
		}

		return $rows;

	}//end readRows()

	/**
	 * The identifier to use for a source row's governance body.
	 *
	 * 🔴 THE LEGACY ROWS HOLD A SLUG WHERE THE SCHEMA WANTS A UUID. A seed
	 * writes `governanceBody: vve-parkstaete`, and OpenRegister resolves slug
	 * references at IMPORT time — but a direct saveObject() validates strictly,
	 * so copying the value across fails with "Property 'governanceBody' should
	 * match format 'uuid' but 'vve-parkstaete' does not". Measured on a live
	 * instance.
	 *
	 * An unresolvable slug is returned AS IS rather than blanked: the save then
	 * fails loudly for that one row instead of silently writing a record bound
	 * to nothing.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $reference     The body reference as the source holds it.
	 *
	 * @return string The resolved identifier, or '' when the source named none.
	 */
	protected function resolveBody(object $objectService, string $reference): string {
		return $this->resolveReference(
			objectService: $objectService,
			schema: 'governance-body',
			reference: $reference
		);

	}//end resolveBody()

	/**
	 * The identifier to use for a reference to any schema.
	 *
	 * 🔴 EVERY `$ref` PROPERTY IN THIS REGISTER DECLARES `format: uuid`, AND A
	 * SEED STORES THE SLUG IT WROTE. Measured on a live instance: seeded agenda
	 * items carry `meeting: "raadsvergadering-2025-01-15"`. So a migration that
	 * copies a reference across verbatim hands a slug to a property that
	 * validates as a uuid, `saveObject()` rejects the whole row, and the step
	 * reports it as a warning — which does not fail an upgrade. The migration
	 * then says "0 migrated, N skipped" and nothing anyone reads says why.
	 *
	 * An unresolvable slug is returned AS IS rather than blanked: the save then
	 * fails loudly for that one row instead of silently writing a record bound
	 * to nothing.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $schema        The schema the reference points at.
	 * @param string $reference     The reference as the source holds it.
	 *
	 * @return string The resolved identifier, or '' when the source named none.
	 */
	protected function resolveReference(object $objectService, string $schema, string $reference): string {
		$reference = trim($reference);
		if ($reference === '' || preg_match('/^[0-9a-f-]{36}$/i', $reference) === 1) {
			return $reference;
		}

		return ($this->uuidForSlug(objectService: $objectService, schema: $schema, slug: $reference) ?? $reference);

	}//end resolveReference()

	/**
	 * The UUID a slug names in one schema, or null when it cannot be resolved.
	 *
	 * 🔑 THE SLUG LIVES IN `@self`, NOT IN THE OBJECT BODY. A seeded `slug:` key
	 * is an import-time identifier OpenRegister keeps as metadata, not a stored
	 * property, so filtering `['slug' => …]` matches nothing.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $schema        The schema to look the slug up in.
	 * @param string $slug          The slug.
	 *
	 * @return string|null The UUID, or null.
	 */
	protected function uuidForSlug(object $objectService, string $schema, string $slug): ?string {
		try {
			$objectService->setRegister(self::REGISTER);
			$objectService->setSchema($schema);
			$rows = $objectService->findAll(['filters' => ['@self' => ['slug' => $slug]], 'limit' => 1]);
		} catch (Throwable $e) {
			$this->migrationLogger()->warning(
				'Decidiq: could not resolve a slug during a supersession migration',
				['schema' => $schema, 'slug' => $slug, 'error' => $e->getMessage()]
			);
			return null;
		}

		foreach (($rows ?? []) as $row) {
			$body = $this->toArray(entity: $row);
			if ($body === null) {
				continue;
			}

			$uuid = (string)($body['id'] ?? $body['uuid'] ?? '');
			if ($uuid !== '') {
				return $uuid;
			}
		}

		return null;

	}//end uuidForSlug()

	/**
	 * Normalise an OpenRegister entity to an array.
	 *
	 * @param mixed $entity The entity.
	 *
	 * @return array<string,mixed>|null The array, or null when unusable.
	 */
	protected function toArray(mixed $entity): ?array {
		if (is_array($entity) === true) {
			return $entity;
		}

		if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			$serialised = $entity->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return null;

	}//end toArray()

	/**
	 * The identifier OpenRegister gave a saved or read object.
	 *
	 * @param array<string,mixed>|null $object The object.
	 *
	 * @return string The identifier, or ''.
	 */
	protected function identifierOf(?array $object): string {
		if ($object === null) {
			return '';
		}

		return trim((string)($object['id'] ?? $object['uuid'] ?? ''));

	}//end identifierOf()
}//end trait
