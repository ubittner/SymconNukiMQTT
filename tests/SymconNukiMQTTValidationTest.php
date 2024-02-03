<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class SymconNukiMQTTValidationTest extends TestCaseSymconValidation
{
    public function testValidateLibrary(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateModule_SmartLock(): void
    {
        $this->validateModule(__DIR__ . '/../SmartLock');
    }
}