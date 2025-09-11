<?php
namespace josemmo\Verifactu\Models\Records;

use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;
use josemmo\Verifactu\Models\Model;

/**
 * Identificador de factura
 */
class InvoiceIdentifier extends Model {
    /**
     * Class constructor
     *
     * @param string|null            $issuerId      Issuer ID
     * @param string|null            $invoiceNumber Invoice number
     * @param DateTimeImmutable|null $issueDate     Issue date
     */
    public function __construct(
        ?string $issuerId = null,
        ?string $invoiceNumber = null,
        ?DateTimeImmutable $issueDate = null,
    ) {
        if ($issuerId !== null) {
            $this->issuerId = $issuerId;
        }
        if ($invoiceNumber !== null) {
            $this->invoiceNumber = $invoiceNumber;
        }
        if ($issueDate !== null) {
            $this->issueDate = $issueDate;
        }
    }

    /**
     * Número de identificación fiscal (NIF) del obligado a expedir la factura
     *
     * @field IDFactura/IDEmisorFactura
     */
    #[Assert\NotBlank]
    #[Assert\Length(exactly: 9)]
    public string $issuerId;

    /**
     * Nº Serie + Nº Factura que identifica a la factura emitida
     *
     * @field IDFactura/NumSerieFactura
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 60)]
    public string $invoiceNumber;

    /**
     * Fecha de expedición de la factura
     *
     * NOTE: Time part will be ignored.
     *
     * @field IDFactura/FechaExpedicionFactura
     */
    #[Assert\NotBlank]
    public DateTimeImmutable $issueDate;
}
