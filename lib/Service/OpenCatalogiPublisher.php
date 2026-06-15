<?php
/**
 * Decidesk OpenCatalogi Publisher
 *
 * Routes derived publication payloads into a configured OpenCatalogi catalog
 * and retracts them on withdraw. Degrades gracefully when OpenCatalogi is
 * absent (the caller surfaces a staff-visible warning).
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Thin adapter over OpenCatalogi's publication API.
 *
 * Mirrors OriPublicationService's OpenCatalogi-absent graceful-degrade posture:
 * when the OpenCatalogi publication service cannot be resolved, publish/retract
 * fail honestly (returning '' / false) so the caller can mark the
 * PublicationRecord pending and warn staff — never a silent success.
 *
 * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
 */
class OpenCatalogiPublisher
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container  DI container (lazy OpenCatalogi service).
     * @param IAppManager        $appManager Detects OpenCatalogi presence.
     * @param LoggerInterface    $logger     Logger.
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a publication for a payload in the given catalog.
     *
     * @param string              $catalogId Target OpenCatalogi catalog id.
     * @param string              $payloadId UUID of the published PublicationPayload object.
     * @param array<string,mixed> $payload   The payload data (for catalog metadata).
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     *
     * @return string The catalog publication reference, or '' on failure/degrade.
     */
    public function publish(string $catalogId, string $payloadId, array $payload): string
    {
        if ($this->appManager->isInstalled('opencatalogi') === false) {
            return '';
        }

        $service = $this->resolvePublicationService();
        if ($service === null) {
            return '';
        }

        try {
            $publication = $service->saveObject(
                object: [
                    'title'     => (string) ($payload['title'] ?? ''),
                    'summary'   => (string) ($payload['title'] ?? ''),
                    'catalog'   => $catalogId,
                    'published' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'reference' => $payloadId,
                    'register'  => 'decidesk',
                    'schema'    => 'publication-payload',
                ],
                register: 'opencatalogi',
                schema: 'publication',
            );

            if (is_object($publication) === true && method_exists($publication, 'getUuid') === true) {
                $uuid = $publication->getUuid();
                if (is_string($uuid) === true && $uuid !== '') {
                    return $uuid;
                }
            }

            if (is_object($publication) === true && method_exists($publication, 'jsonSerialize') === true) {
                $data = $publication->jsonSerialize();
                $id   = ($data['id'] ?? $data['uuid'] ?? ($data['@self']['id'] ?? null));
                if (is_string($id) === true && $id !== '') {
                    return $id;
                }
            }

            return '';
        } catch (\Throwable $e) {
            $this->logger->warning('Decidesk publication: OpenCatalogi publish failed', ['exception' => $e->getMessage()]);
            return '';
        }//end try

    }//end publish()

    /**
     * Retract a previously-created catalog publication.
     *
     * @param string $catalogId          Target catalog id (informational).
     * @param string $catalogPublication The catalog publication reference.
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     *
     * @return bool True when retraction succeeded; false to mark pending + warn.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $catalogId kept for symmetry/logging.
     */
    public function retract(string $catalogId, string $catalogPublication): bool
    {
        if ($catalogPublication === '') {
            return true;
        }

        if ($this->appManager->isInstalled('opencatalogi') === false) {
            return false;
        }

        $service = $this->resolvePublicationService();
        if ($service === null) {
            return false;
        }

        try {
            $service->deleteObject(
                uuid: $catalogPublication,
                register: 'opencatalogi',
                schema: 'publication',
            );
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Decidesk publication: OpenCatalogi retraction failed', ['exception' => $e->getMessage()]);
            return false;
        }

    }//end retract()

    /**
     * Resolve OpenCatalogi's ObjectService, or null when unavailable.
     *
     * @spec openspec/changes/publish-decisions-via-opencatalogi/specs/public-publication/spec.md
     *
     * @return object|null
     */
    private function resolvePublicationService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->warning('Decidesk publication: OpenCatalogi ObjectService unresolvable', ['exception' => $e->getMessage()]);
            return null;
        }

    }//end resolvePublicationService()
}//end class
