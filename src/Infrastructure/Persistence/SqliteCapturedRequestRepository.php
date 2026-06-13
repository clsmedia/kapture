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
        private readonly int $retentionDays,
    )
    {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }

        $dbPath = $this->logDir . '/kapture.db';
        $this->db = new \SQLite3($dbPath);
        $this->db->enableExceptions(true);
        $this->db->exec('PRAGMA journal_mode=WAL;');
        $this->db->exec('PRAGMA busy_timeout=5000;');

        $this->ensureSchema();
    }

    #[\Override]
    public function save(CapturedRequest $entry): void
    {
        $stmt = $this->db->prepare(\sprintf(
            'INSERT INTO %s (captured_at, captured_at_date, method, uri, query, headers, body, ip, capture_id, forward_url, forward_status_code) '
            . 'VALUES (:captured_at, :captured_at_date, :method, :uri, :query, :headers, :body, :ip, :capture_id, :forward_url, :forward_status_code)',
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
        $stmt->bindValue(':forward_url', $entry->forwardUrl, \SQLITE3_TEXT);
        $stmt->bindValue(':forward_status_code', $entry->forwardStatusCode, \SQLITE3_INTEGER);

        $stmt->execute();

        $this->prune();
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

    #[\Override]
    public function getEntryCounts(): array
    {
        $result = $this->db->query(\sprintf(
            'SELECT captured_at_date, COUNT(*) AS count FROM %s GROUP BY captured_at_date ORDER BY captured_at_date DESC',
            self::TABLE,
        ));

        if ($result === false) {
            return [];
        }

        $counts = [];
        while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
            $counts[(string) $row['captured_at_date']] = (int) $row['count'];
        }

        return $counts;
    }

    #[\Override]
    public function delete(string $captureId): void
    {
        $stmt = $this->db->prepare(\sprintf(
            'DELETE FROM %s WHERE capture_id = :capture_id',
            self::TABLE,
        ));

        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare SQL delete statement');
        }

        $stmt->bindValue(':capture_id', $captureId, \SQLITE3_TEXT);
        $stmt->execute();
    }

    #[\Override]
    public function getRawContent(\DateTimeImmutable $date): ?string
    {
        return null;
    }

    private const PRUNE_MIN_INTERVAL = 3600;

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
            . 'capture_id TEXT NOT NULL UNIQUE, '
            . 'forward_url TEXT, '
            . 'forward_status_code INTEGER'
            . ')',
            self::TABLE,
        ));

        $this->db->exec(\sprintf(
            'CREATE INDEX IF NOT EXISTS idx_captured_at_date ON %s (captured_at_date)',
            self::TABLE,
        ));

        // migrate existing databases — columns may already exist
        try {
            $this->db->exec(\sprintf('ALTER TABLE %s ADD COLUMN forward_url TEXT', self::TABLE));
        } catch (\Exception) {
            // column already exists
        }
        try {
            $this->db->exec(\sprintf('ALTER TABLE %s ADD COLUMN forward_status_code INTEGER', self::TABLE));
        } catch (\Exception) {
            // column already exists
        }
    }

    private function prune(): void
    {
        $marker = $this->logDir . '/.prune-timestamp';

        if (file_exists($marker) && (time() - filemtime($marker)) < self::PRUNE_MIN_INTERVAL) {
            return;
        }

        $cutoff = (new \DateTimeImmutable("-{$this->retentionDays} days"))->format('Y-m-d');
        $this->db->exec(\sprintf(
            'DELETE FROM %s WHERE captured_at_date < \'%s\'',
            self::TABLE,
            \SQLite3::escapeString($cutoff),
        ));

        touch($marker);
    }

    /**
     * @return CapturedRequest[]
     */
    private function hydrateAll(\SQLite3Result $result): array
    {
        $entries = [];
        while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
            $forwardUrl = isset($row['forward_url']) && $row['forward_url'] !== '' ? (string) $row['forward_url'] : null;
            $forwardStatusCode = isset($row['forward_status_code']) && $row['forward_status_code'] !== '' ? (int) $row['forward_status_code'] : null;

            $entries[] = new CapturedRequest(
                \App\Domain\CapturedAt::fromTimestamp((int) $row['captured_at']),
                \App\Domain\HttpMethod::tryFromMethod((string) $row['method']) ?? \App\Domain\HttpMethod::GET,
                (string) $row['uri'],
                (array) \json_decode((string) $row['query'], true),
                (array) \json_decode((string) $row['headers'], true),
                (string) $row['body'],
                (string) $row['ip'],
                (string) $row['capture_id'],
                $forwardUrl,
                $forwardStatusCode,
            );
        }

        return $entries;
    }
}
