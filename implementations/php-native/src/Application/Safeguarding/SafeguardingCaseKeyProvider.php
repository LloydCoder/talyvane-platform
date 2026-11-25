<?php
declare(strict_types=1);

namespace Talyvane\Application\Safeguarding;

interface SafeguardingCaseKeyProvider
{
    public function name(): string;
    public function wrap(string $caseId,string $caseKey): string;
    public function unwrap(string $caseId,string $wrappedKey): string;
    public function supportsDestruction(): bool;
    public function destroy(string $caseId,string $wrappedKey): void;
}
