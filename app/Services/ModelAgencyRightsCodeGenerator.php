<?php

namespace App\Services;

class ModelAgencyRightsCodeGenerator
{
    public function generate(): string
    {
        return (string) random_int(10000000, 99999999);
    }
}
