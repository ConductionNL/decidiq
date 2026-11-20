<?php

/**
 * FilinqStamp — put a text on every page of a PDF through filinq.
 *
 * The sibling of FilinqPdf. filinq is an optional app; this class resolves its
 * PdfStampService under every name filinq has had (FleetAppId) and answers
 * null when it is not there, so the caller can refuse to hand out an unstamped
 * copy. A stamp filinq refuses (not a PDF, encrypted, too large) is thrown as
 * a PaperStampRefusedException with status 422.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\Decidiq\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 */

declare(strict_types=1);

namespace OCA\Decidiq\Support;

use OCA\Decidiq\Exception\PaperStampRefusedException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves filinq's stamping service and stamps through it.
 *
 * Not final, like FilinqPdf: callers double it in their tests.
 *
 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 */
class FilinqStamp
{

    /**
     * Wire collaborators.
     *
     * @param ContainerInterface $container Resolves filinq's service, whatever it is called.
     * @param LoggerInterface    $logger    Records why a paper was not stamped.
     *
     * @return void
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Stamp a text across and at the foot of every page.
     *
     * @param string $pdf  The PDF bytes.
     * @param string $text The stamp line.
     *
     * @return string|null The stamped PDF, or null when filinq has no stamping service.
     *
     * @throws PaperStampRefusedException With 422 when filinq refuses this paper.
     *
     * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
     */
    public function stamp(string $pdf, string $text): ?string
    {
        $service = FleetAppId::getService($this->container, 'filinq', 'Service\PdfStampService');
        if ($service === null || method_exists($service, 'stamp') === false) {
            $this->logger->info('Decidiq: filinq has no stamping service; a paper that must be watermarked is not served');
            return null;
        }

        try {
            $stamped = $service->stamp($pdf, $text, ['placement' => 'both']);
        } catch (Throwable $e) {
            $this->logger->warning('Decidiq: filinq could not stamp a paper', ['error' => $e->getMessage()]);
            throw new PaperStampRefusedException(message: PaperStampRefusedException::UNSTAMPABLE, status: 422);
        }

        if (is_string($stamped) === false || $stamped === '') {
            throw new PaperStampRefusedException(message: PaperStampRefusedException::UNSTAMPABLE, status: 422);
        }

        return $stamped;

    }//end stamp()

}//end class
