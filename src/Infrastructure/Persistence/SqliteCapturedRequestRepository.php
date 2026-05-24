<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\CapturedRequest;
use App\Domain\CapturedRequestRepository;

final class SqliteCapturedRequestRepository implements CapturedRequestRepository
{
    private const TABLE = 'captured_requests';

    private \SQLite3 $db;

    public function __construct(
        private readonly string $logDir,
    )
    {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }

        $dbPath = $this->logDir . '/kapture.db';
        $this->db = new \SQLite3($dbPath);
        $this->db->enableExceptions(true);

        $this->ensureSchema();
    }

    #[\Override]
    public function save(CapturedRequest $entry): void
    {
        $stmt = $this->db->prepare(\sprintf(
            'INSERT INTO %s (captured_at, captured_at_date, method, uri, query, headers, body, ip, capture_id) '
            . 'VALUES (:captured_at, :captured_at_date, :method, :uri, :query, :headers, :body, :ip, :capture_id)',
            self::TABLE,
        ));

        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare SQL insert statement');
        }

        $capturedAt = $entry->capturedAt->toTimestamp();
        $capturedAtDate = $entry->capturedAt->toDateTimeImmutable()->format('Y-m-d');

        $stmt->bindValue(':captured_at', $capturedAt, \SQLITE3_INTEGER);
        $stmt->bindValue(':captured_at_date', $capturedAtDate, \SQLITE3_TEXT);
        $stmt->bindValue(':method', $entry->method->value, \SQLITE3_TEXT);
        $stmt->bindValue(':uri', $entry->uri, \SQLITE3_TEXT);
        $stmt->bindValue(':query', \json_encode($entry->query, \JSON_THROW_ON_ERROR), \SQLITE3_TEXT);
        $stmt->bindValue(':headers', \json_encode($entry->headers, \JSON_THROW_ON_ERROR), \SQLITE3_TEXT);
        $stmt->bindValue(':body', $entry->body, \SQLITE3_TEXT);
        $stmt->bindValue(':ip', $entry->ip, \SQLITE3_TEXT);
        $stmt->bindValue(':capture_id', $entry->captureId, \SQLITE3_TEXT);

        $stmt->execute();
    }

    #[\Override]
    public function findAll(): array
    {
        $result = $this->db->query(\sprintf(
            'SELECT * FROM %s ORDER BY captured_at DESC',
            self::TABLE,
        ));

        if ($result === false) {
            return [];
        }

        return $this->hydrateAll($result);
    }

    #[\Override]
    public function findByDate(\DateTimeImmutable $date): array
    {
        $stmt = $this->db->prepare(\sprintf(
            'SELECT * FROM %s WHERE captured_at_date = :date ORDER BY captured_at DESC',
            self::TABLE,
        ));

        if ($stmt === false) {
            return [];
        }

        $stmt->bindValue(':date', $date->format('Y-m-d'), \SQLITE3_TEXT);
        $result = $stmt->execute();

        if ($result === false) {
            return [];
        }

        return $this->hydrateAll($result);
    }

    #[\Override]
    public function getAvailableDates(): array
    {
        $result = $this->db->query(\sprintf(
            'SELECT DISTINCT captured_at_date FROM %s ORDER BY captured_at_date DESC',
            self::TABLE,
        ));

        if ($result === false) {
            return [];
        }

        $dates = [];
        while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d|', $row['captured_at_date']);
            if ($dt !== false) {
                $dates[] = $dt;
            }
        }

        return $dates;
    }

    private function ensureSchema(): void
    {
        $this->db->exec(\sprintf(
            'CREATE TABLE IF NOT EXISTS %s ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'captured_at INTEGER NOT NULL, '
            . 'captured_at_date TEXT NOT NULL, '
            . 'method TEXT NOT NULL, '
            . 'uri TEXT NOT NULL, '
            . 'query TEXT NOT NULL, '
            . 'headers TEXT NOT NULL, '
            . 'body TEXT NOT NULL, '
            . 'ip TEXT NOT NULL, '
            . 'capture_id TEXT NOT NULL UNIQUE'
            . ')',
            self::TABLE,
        ));

        $this->db->exec(\sprintf(
            'CREATE INDEX IF NOT EXISTS idx_captured_at_date ON %s (captured_at_date)',
            self::TABLE,
        ));
    }

    /**
     * @return CapturedRequest[]
     */
    private function hydrateAll(\SQLite3Result $result): array
    {
        $entries = [];
        while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
            $entries[] = new CapturedRequest(
                \App\Domain\CapturedAt::fromTimestamp((int) $row['captured_at']),
                \App\Domain\HttpMethod::tryFromMethod((string) $row['method']) ?? \App\Domain\HttpMethod::GET,
                (string) $row['uri'],
                (array) \json_decode((string) $row['query'], true),
                (array) \json_decode((string) $row['headers'], true),
                (string) $row['body'],
                (string) $row['ip'],
                (string) $row['capture_id'],
            );
        }

        return $entries;
    }
}
