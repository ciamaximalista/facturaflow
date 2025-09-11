<?php
namespace josemmo\Verifactu\Models;

use Symfony\Component\Validator\Validation;
use josemmo\Verifactu\Exceptions\InvalidModelException;

abstract class Model {
    /**
     * Validate this instance
     *
     * @throws InvalidModelException if failed to pass validation
     */
    final public function validate(): void {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $errors = $validator->validate($this);
        if (count($errors) > 0) {
            throw new InvalidModelException($errors);
        }
    }
}
