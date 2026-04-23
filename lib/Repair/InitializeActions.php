<?php

/**
 * Decidesk Initialize Actions Repair Step
 *
 * Seeds the ADR-023 action-authorization matrix on fresh install if empty.
 * Preserves any admin-customized matrix on upgrade — existing non-empty
 * matrix values are left untouched.
 *
 * @category Repair
 * @package  OCA\Decidesk\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */

declare(strict_types=1);

namespace OCA\Decidesk\Repair;

use OCA\Decidesk\Service\ActionAuthService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Seed the action-authorization matrix from lib/actions.seed.json on install.
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */
class InitializeActions implements IRepairStep
{
    private const SEED_PATH = __DIR__ . '/../actions.seed.json';

    /**
     * Constructor.
     *
     * @param ActionAuthService $actionAuth The action authorization service.
     * @param LoggerInterface   $logger     Logger.
     */
    public function __construct(
        private ActionAuthService $actionAuth,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Repair-step name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Initialize Decidesk action-authorization matrix (ADR-023)';

    }//end getName()

    /**
     * Seed the matrix if empty; preserve any existing admin-customized matrix.
     *
     * @param IOutput $output Repair output channel.
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        $existing = $this->actionAuth->getMatrix();
        if (count($existing) > 0) {
            $output->info(
                sprintf(
                    'Decidesk action matrix already has %d entr%s — preserving.',
                    count($existing),
                    (count($existing) === 1 ? 'y' : 'ies')
                )
            );
            return;
        }

        if (file_exists(self::SEED_PATH) === false) {
            $output->warning('actions.seed.json not found — matrix left empty (default-deny).');
            $this->logger->warning('[decidesk] ADR-023 seed file missing at ' . self::SEED_PATH);
            return;
        }

        $raw = file_get_contents(self::SEED_PATH);
        if ($raw === false) {
            $output->warning('Could not read actions.seed.json — matrix left empty (default-deny).');
            return;
        }

        try {
            $parsed = json_decode($raw, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $output->warning('actions.seed.json invalid JSON: ' . $e->getMessage());
            $this->logger->error('[decidesk] ADR-023 seed malformed: ' . $e->getMessage());
            return;
        }

        $actions = ($parsed['actions'] ?? null);
        if (is_array($actions) === false) {
            $output->warning('actions.seed.json missing `actions` object — matrix left empty.');
            return;
        }

        try {
            $this->actionAuth->setMatrix($actions);
        } catch (\JsonException $e) {
            $output->warning('Failed to write matrix: ' . $e->getMessage());
            return;
        }

        $output->info(
            sprintf(
                'Seeded Decidesk action matrix with %d action%s (default: admin-only).',
                count($actions),
                (count($actions) === 1 ? '' : 's')
            )
        );

    }//end run()

}//end class
