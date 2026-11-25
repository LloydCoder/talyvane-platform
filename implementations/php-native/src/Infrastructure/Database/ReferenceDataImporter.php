<?php
declare(strict_types=1);

namespace Talyvane\Infrastructure\Database;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/** Imports pinned, reviewed ISO reference snapshots without changing operator enablement decisions. */
final class ReferenceDataImporter
{
    public function __construct(private PDO $pdo) {}

    /** @return array{countries:int,currencies:int,version:string,imported:bool} */
    public function importIfNeeded(string $countriesPath,string $currenciesPath): array
    {
        $countryDocument = self::readDocument($countriesPath,'countries');
        $currencyDocument = self::readDocument($currenciesPath,'currencies');
        $countryVersion = 'iso3166-' . self::version($countryDocument);
        $currencyVersion = 'iso4217-' . self::version($currencyDocument);
        $countries = self::validateCountries($countryDocument);
        $currencies = self::validateCurrencies($currencyDocument);

        $countryCheck = $this->pdo->prepare('SELECT COUNT(*) FROM countries WHERE dataset_version = :version');
        $countryCheck->execute(['version'=>$countryVersion]);
        $currencyCheck = $this->pdo->prepare('SELECT COUNT(*) FROM currencies WHERE dataset_version = :version');
        $currencyCheck->execute(['version'=>$currencyVersion]);
        if ((int) $countryCheck->fetchColumn() === count($countries) &&
            (int) $currencyCheck->fetchColumn() === count($currencies)) {
            return ['countries'=>count($countries),'currencies'=>count($currencies),'version'=>self::version($countryDocument),'imported'=>false];
        }

        $this->pdo->beginTransaction();
        try {
            $countryInsert = $this->pdo->prepare(
                'INSERT INTO countries
                 (alpha2,alpha3,numeric_code,name,enabled,dataset_version,updated_at)
                 VALUES (:alpha2,:alpha3,:numeric,:name,1,:version,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE alpha3=VALUES(alpha3),numeric_code=VALUES(numeric_code),
                 name=VALUES(name),dataset_version=VALUES(dataset_version),updated_at=UTC_TIMESTAMP()'
            );
            foreach ($countries as $country) {
                $countryInsert->execute([
                    'alpha2'=>$country['alpha2'],'alpha3'=>$country['alpha3'],'numeric'=>$country['numeric_code'],
                    'name'=>$country['name'],'version'=>$countryVersion,
                ]);
            }

            $currencyInsert = $this->pdo->prepare(
                'INSERT INTO currencies
                 (code,numeric_code,name,symbol,minor_units,enabled,dataset_version,updated_at)
                 VALUES (:code,:numeric,:name,:symbol,:minor,1,:version,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE numeric_code=VALUES(numeric_code),name=VALUES(name),
                 symbol=VALUES(symbol),minor_units=VALUES(minor_units),
                 dataset_version=VALUES(dataset_version),updated_at=UTC_TIMESTAMP()'
            );
            foreach ($currencies as $currency) {
                $currencyInsert->execute([
                    'code'=>$currency['code'],'numeric'=>$currency['numeric_code'],'name'=>$currency['name'],
                    'symbol'=>$currency['symbol'],'minor'=>$currency['minor_units'],'version'=>$currencyVersion,
                ]);
            }

            $currencyCodes = array_column($currencies,'code');
            $countryCodes = array_column($countries,'alpha2');
            $this->disableRemoved('currencies','code','iso4217-',$currencyCodes);
            $this->disableRemoved('countries','alpha2','iso3166-',$countryCodes);
            $this->pdo->commit();
            return ['countries'=>count($countries),'currencies'=>count($currencies),'version'=>self::version($countryDocument),'imported'=>true];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function disableRemoved(string $table,string $key,string $versionPrefix,array $activeCodes): void
    {
        if (!in_array($table,['countries','currencies'],true) || !in_array($key,['alpha2','code'],true)) {
            throw new InvalidArgumentException('Reference-data table is not permitted.');
        }
        $placeholders = implode(',',array_fill(0,count($activeCodes),'?'));
        $sql = 'UPDATE ' . $table . ' SET enabled=0,updated_at=UTC_TIMESTAMP()
                WHERE dataset_version LIKE ? AND ' . $key . ' NOT IN (' . $placeholders . ')';
        $statement = $this->pdo->prepare($sql);
        $statement->execute(array_merge([$versionPrefix . '%'],$activeCodes));
    }

    /** @return array<string,mixed> */
    private static function readDocument(string $path,string $key): array
    {
        if (!is_file($path) || !is_readable($path) || is_link($path)) {
            throw new RuntimeException('Required bundled reference-data file is unavailable.');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '' || strlen($raw) > 2_000_000) {
            throw new RuntimeException('Bundled reference-data file is empty or exceeds the size limit.');
        }
        try {
            $document = json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Bundled reference-data file is not valid JSON.');
        }
        if (!is_array($document) || !is_array($document['metadata'] ?? null) || !is_array($document[$key] ?? null)) {
            throw new RuntimeException('Bundled reference-data document has an invalid structure.');
        }
        return $document;
    }

    private static function version(array $document): string
    {
        $version = $document['metadata']['version'] ?? null;
        if (!is_string($version) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}\.[0-9]+$/D',$version)) {
            throw new RuntimeException('Reference-data version is invalid.');
        }
        return $version;
    }

    /** @return list<array{alpha2:string,alpha3:string,numeric_code:string,name:string}> */
    private static function validateCountries(array $document): array
    {
        if (($document['metadata']['market_approval_required'] ?? false) !== true) {
            throw new RuntimeException('Country reference metadata must preserve the independent market-approval gate.');
        }
        $rows = $document['countries'];
        $expected = $document['metadata']['records'] ?? null;
        if (!is_int($expected) || $expected < 200 || $expected > 300 || count($rows) !== $expected) {
            throw new RuntimeException('Country reference count does not match its signed-in-repository metadata.');
        }
        $seen = [];
        $validated = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Country reference entry is invalid.');
            }
            $alpha2 = $row['alpha2'] ?? null;
            $alpha3 = $row['alpha3'] ?? null;
            $numeric = $row['numeric_code'] ?? null;
            $name = $row['name'] ?? null;
            if (!is_string($alpha2) || !preg_match('/^[A-Z]{2}$/D',$alpha2) ||
                !is_string($alpha3) || !preg_match('/^[A-Z]{3}$/D',$alpha3) ||
                !is_string($numeric) || !preg_match('/^[0-9]{3}$/D',$numeric) ||
                !is_string($name) || trim($name) === '' || strlen($name) > 190 ||
                isset($seen[$alpha2]) || isset($seen[$alpha3]) || isset($seen[$numeric])) {
                throw new RuntimeException('Country reference entry failed ISO format or uniqueness validation.');
            }
            $seen[$alpha2] = true;
            $seen[$alpha3] = true;
            $seen[$numeric] = true;
            $validated[] = ['alpha2'=>$alpha2,'alpha3'=>$alpha3,'numeric_code'=>$numeric,'name'=>trim($name)];
        }
        return $validated;
    }

    /** @return list<array{code:string,numeric_code:string,name:string,symbol:string,minor_units:int}> */
    private static function validateCurrencies(array $document): array
    {
        if (($document['metadata']['fund_codes_excluded'] ?? false) !== true || ($document['metadata']['withdrawn_codes_excluded'] ?? false) !== true) {
            throw new RuntimeException('Currency reference metadata must exclude fund and withdrawn codes.');
        }
        $rows = $document['currencies'];
        $expected = $document['metadata']['records'] ?? null;
        if (!is_int($expected) || $expected < 100 || $expected > 250 || count($rows) !== $expected) {
            throw new RuntimeException('Currency reference count does not match its metadata.');
        }
        $seen = [];
        $validated = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Currency reference entry is invalid.');
            }
            $code = $row['code'] ?? null;
            $numeric = $row['numeric_code'] ?? null;
            $name = $row['name'] ?? null;
            $symbol = $row['symbol'] ?? null;
            $minor = $row['minor_units'] ?? null;
            if (!is_string($code) || !preg_match('/^[A-Z]{3}$/D',$code) ||
                !is_string($numeric) || !preg_match('/^[0-9]{3}$/D',$numeric) ||
                !is_string($name) || trim($name) === '' || strlen($name) > 190 ||
                !is_string($symbol) || strlen($symbol) > 24 ||
                !is_int($minor) || $minor < 0 || $minor > 4 ||
                isset($seen[$code]) || isset($seen[$numeric])) {
                throw new RuntimeException('Currency reference entry failed ISO format or uniqueness validation.');
            }
            $seen[$code] = true;
            $seen[$numeric] = true;
            $validated[] = [
                'code'=>$code,'numeric_code'=>$numeric,'name'=>trim($name),
                'symbol'=>$symbol !== '' ? $symbol : $code,'minor_units'=>$minor,
            ];
        }
        return $validated;
    }
}
