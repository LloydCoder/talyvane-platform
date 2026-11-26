<?php
declare(strict_types=1);

namespace Talyvane\Security;

final readonly class Principal
{
    /** @param list<string> $roles */
    public function __construct(public string $id, public array $roles, public bool $mfaVerified = false)
    {
        if ($id === '' || $roles === []) {
            throw new \InvalidArgumentException('Principal requires an identity and at least one role.');
        }
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasOnlyRole(string $role): bool
    {
        return count($this->roles) === 1 && $this->hasRole($role);
    }
}
