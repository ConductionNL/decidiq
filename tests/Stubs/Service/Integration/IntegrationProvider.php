<?php

/**
 * Test stub for OpenRegister IntegrationProvider.
 *
 * Stands in for OCA\OpenRegister\Service\Integration\IntegrationProvider when
 * OpenRegister is not installed (standalone CI), so the `?IntegrationProvider`
 * type hint on `RegisterLeafProvidersEvent::registerLeaf()` resolves.
 *
 * decidesk's decisions leaf is render-only and passes `null` for the provider, so
 * no method here is ever exercised. The FULL interface surface is mirrored
 * anyway: an interface stub that declares fewer methods than the real one would
 * let a class that does not actually satisfy the contract be written against it,
 * and the shortfall would only surface as a fatal on a live instance. The real
 * interface ships with OpenRegister
 * (lib/Service/Integration/IntegrationProvider.php).
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * IntegrationProvider interface stub for standalone unit runs.
 */
interface IntegrationProvider {
	/**
	 * Stable id used to address this integration.
	 *
	 * @return string Stable identifier.
	 */
	public function getId(): string;

	/**
	 * Translatable label shown to end users.
	 *
	 * @return string The label.
	 */
	public function getLabel(): string;

	/**
	 * Material Design Icons name.
	 *
	 * @return string The icon name.
	 */
	public function getIcon(): string;

	/**
	 * Optional admin-UI grouping.
	 *
	 * @return string|null The group.
	 */
	public function getGroup(): ?string;

	/**
	 * NC app id that must be installed/enabled.
	 *
	 * @return string|null NC app id or null when always-available.
	 */
	public function getRequiredApp(): ?string;

	/**
	 * Where this integration's links are stored.
	 *
	 * @return string One of magic-column|link-table|external|query-time|app-local.
	 */
	public function getStorageStrategy(): string;

	/**
	 * OpenConnector source id for `storage='external'` providers.
	 *
	 * @return string|null OpenConnector source id, or null.
	 */
	public function getOpenConnectorSource(): ?string;

	/**
	 * Whether the integration is currently usable on this instance.
	 *
	 * @return bool True when the integration may be invoked.
	 */
	public function isEnabled(): bool;

	/**
	 * Optional permission required to use this integration.
	 *
	 * @return string|null Permission string or null.
	 */
	public function requiresPermission(): ?string;

	/**
	 * Credential requirements for the integration.
	 *
	 * @return array<string,mixed> Auth-requirements descriptor.
	 */
	public function authRequirements(): array;

	/**
	 * List linked things for an OR object.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $objectId The object id.
	 * @param array<string,mixed> $filters Optional filters.
	 *
	 * @return array<string,mixed>|array<int,array<string,mixed>> The linked things.
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array;

	/**
	 * Read one linked thing.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $objectId The object id.
	 * @param string $entityId The linked entity id.
	 *
	 * @return array<string,mixed> The linked thing.
	 */
	public function get(string $register, string $schema, string $objectId, string $entityId): array;

	/**
	 * Append a linked thing.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $objectId The object id.
	 * @param array<string,mixed> $payload The new entity.
	 *
	 * @return array<string,mixed> The created entity.
	 */
	public function create(string $register, string $schema, string $objectId, array $payload): array;

	/**
	 * Update a linked thing.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $objectId The object id.
	 * @param string $entityId The linked entity id.
	 * @param array<string,mixed> $payload The changed fields.
	 *
	 * @return array<string,mixed> The updated entity.
	 */
	public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array;

	/**
	 * Delete a linked thing.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $objectId The object id.
	 * @param string $entityId The linked entity id.
	 *
	 * @return void
	 */
	public function delete(string $register, string $schema, string $objectId, string $entityId): void;

	/**
	 * Health of the integration's backing source.
	 *
	 * @return array<string,mixed> The health report.
	 */
	public function health(): array;
}//end interface
