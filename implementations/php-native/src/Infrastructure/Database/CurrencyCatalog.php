<?php
declare(strict_types=1);

namespace Talyvane\Infrastructure\Database;

use PDO;
use Talyvane\Domain\Money\Currency;

final class CurrencyCatalog
{
    public function __construct(private PDO $pdo) {}

    public function find(string $code): ?Currency
    {
        if (!preg_match('/^[A-Z]{3}$/D', $code)) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT code, name, minor_units, enabled, numeric_code, symbol, dataset_version FROM currencies WHERE code = :code LIMIT 1');
        $statement->execute(['code' => $code]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        return new Currency(
            (string) $row['code'],
            (string) $row['name'],
            (int) $row['minor_units'],
            (bool) $row['enabled'],
            $row['numeric_code'] === null ? null : (string) $row['numeric_code'],
            $row['symbol'] === null ? null : (string) $row['symbol'],
            (string) $row['dataset_version']
        );
    }

    public function requireEnabled(string $code): Currency
    {
        $currency = $this->find($code);
        if ($currency === null || !$currency->enabled) {
            throw new \DomainException('Currency is unknown or disabled for this deployment.');
        }
        return $currency;
    }
}
