<?php
declare(strict_types=1);

namespace Talyvane\Infrastructure\Audit;

use DateTimeImmutable;
use PDO;
use InvalidArgumentException;

final class AuditQueryService
{
    private const PAGE_SIZE = 50;
    private const MAX_PAGE = 10000;

    public function __construct(private PDO $pdo) {}

    /**
     * Returns metadata only. Before/after snapshots are intentionally excluded
     * from the administrative listing to avoid turning the audit screen into a
     * secondary source of personal or sensitive record data.
     *
     * @param array<string,string> $filters
     * @return list<array<string,mixed>>
     */
    public function search(array $filters, int $page = 1): array
    {
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new InvalidArgumentException('The requested audit page is outside the supported range.');
        }

        $where = [];
        $parameters = [];
        foreach (['event_key', 'target_type', 'target_id', 'actor_user_id'] as $field) {
            $value = trim($filters[$field] ?? '');
            if ($value === '') {
                continue;
            }
            if (strlen($value) > 190 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException('An audit filter is invalid.');
            }
            $where[] = $field . ' = :' . $field;
            $parameters[$field] = $value;
        }

        $from = trim($filters['from'] ?? '');
        if ($from !== '') {
            $where[] = 'occurred_at >= :from_date';
            $parameters['from_date'] = $this->date($from)->format('Y-m-d 00:00:00');
        }

        $to = trim($filters['to'] ?? '');
        if ($to !== '') {
            $where[] = 'occurred_at < :to_date';
            $parameters['to_date'] = $this->date($to)->modify('+1 day')->format('Y-m-d 00:00:00');
        }

        if ($from !== '' && $to !== '' && $from > $to) {
            throw new InvalidArgumentException('The start date must not be after the end date.');
        }

        $sql = 'SELECT id, actor_user_id, event_key, target_type, target_id, reason, request_id, policy_version, occurred_at
                FROM audit_events';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY occurred_at DESC, id DESC LIMIT :limit OFFSET :offset';

        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', self::PAGE_SIZE, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * self::PAGE_SIZE, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    private function date(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed instanceof DateTimeImmutable
            || $parsed->format('Y-m-d') !== $value
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('Enter audit dates in YYYY-MM-DD format.');
        }
        return $parsed;
    }
}
