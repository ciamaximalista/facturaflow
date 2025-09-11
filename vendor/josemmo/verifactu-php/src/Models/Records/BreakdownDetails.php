<?php
namespace josemmo\Verifactu\Models\Records;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use josemmo\Verifactu\Models\Model;

/**
 * Detalle de desglose
 *
 * @field DetalleDesglose
 */
class BreakdownDetails extends Model {
    /**
     * Impuesto de aplicación
     *
     * @field Impuesto
     */
    #[Assert\NotBlank]
    public TaxType $taxType;

    /**
     * Clave que identifica el tipo de régimen del impuesto o una operación con trascendencia tributaria
     *
     * @field ClaveRegimen
     */
    #[Assert\NotBlank]
    public RegimeType $regimeType;

    /**
     * Clave de la operación sujeta y no exenta o de la operación no sujeta
     *
     * @field CalificacionOperacion
     */
    #[Assert\NotBlank]
    public OperationType $operationType;

    /**
     * Porcentaje aplicado sobre la base imponible para calcular la cuota
     *
     * @field TipoImpositivo
     */
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d{1,3}\.\d{2}$/')]
    public string $taxRate;

    /**
     * Magnitud dineraria sobre la que se aplica el tipo impositivo / Importe no sujeto
     *
     * @field BaseImponibleOimporteNoSujeto
     */
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^-?\d{1,12}\.\d{2}$/')]
    public string $baseAmount;

    /**
     * Cuota resultante de aplicar a la base imponible el tipo impositivo
     *
     * @field CuotaRepercutida
     */
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^-?\d{1,12}\.\d{2}$/')]
    public string $taxAmount;

    #[Assert\Callback]
    final public function validateTaxAmount(ExecutionContextInterface $context): void {
        if (!isset($this->taxRate) || !isset($this->baseAmount) || !isset($this->taxAmount)) {
            return;
        }

        $validTaxAmount = false;
        $bestTaxAmount = (float) $this->baseAmount * ((float) $this->taxRate / 100);
        foreach ([0, -0.01, 0.01, -0.02, 0.02] as $tolerance) {
            $expectedTaxAmount = number_format($bestTaxAmount + $tolerance, 2, '.', '');
            if ($this->taxAmount === $expectedTaxAmount) {
                $validTaxAmount = true;
                break;
            }
        }
        if (!$validTaxAmount) {
            $bestTaxAmount = number_format($bestTaxAmount, 2, '.', '');
            $context->buildViolation("Expected tax amount of $bestTaxAmount, got {$this->taxAmount}")
                ->atPath('taxAmount')
                ->addViolation();
        }
    }
}
