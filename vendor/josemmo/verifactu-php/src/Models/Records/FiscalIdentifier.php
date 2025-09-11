<?php
namespace josemmo\Verifactu\Models\Records;

use Symfony\Component\Validator\Constraints as Assert;
use josemmo\Verifactu\Models\Model;

/**
 * Identificador fiscal
 *
 * @field Caberecera/ObligadoEmision
 * @field Caberecera/Representante
 */
class FiscalIdentifier extends Model {
    /**
     * Class constructor
     *
     * @param string|null $name Name
     * @param string|null $nif  NIF
     */
    public function __construct(
        ?string $name = null,
        ?string $nif = null,
    ) {
        if ($name !== null) {
            $this->name = $name;
        }
        if ($nif !== null) {
            $this->nif = $nif;
        }
    }

    /**
     * Nombre-razón social
     *
     * @field NombreRazon
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public string $name;

    /**
     * Número de identificación fiscal (NIF)
     *
     * @field NIF
     */
    #[Assert\NotBlank]
    #[Assert\Length(exactly: 9)]
    public string $nif;
}
