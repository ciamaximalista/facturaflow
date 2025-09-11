<?php
namespace josemmo\Verifactu\Models\Records;

enum OperationType: string {
    /** Operación sujeta y no exenta - Sin inversión del sujeto pasivo */
    case S1 = 'S1';

    /** Operación sujeta y no exenta - Con inversión del sujeto pasivo */
    case S2 = 'S2';

    /** Operación no sujeta - Artículos 7, 14 y otros */
    case N1 = 'N1';

    /** Operación no sujeta por reglas de localización */
    case N2 = 'N2';
}
