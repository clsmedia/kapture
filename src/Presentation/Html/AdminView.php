<?php

declare(strict_types=1);

namespace App\Presentation\Html;

use App\Application\ListCapturedRequestsResult;
use App\Domain\CapturedRequest;

final readonly class AdminView
{
    public static function render(ListCapturedRequestsResult $result): void
    {
        $entries = $result->entries;
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Kapture &middot; admin</title>
            <link rel="stylesheet" href="/assets/style.css">
        </head>
        <body>
        <?php self::renderTopbar($result); ?>
        <div class="layout">
            <?php self::renderSidebar($result); ?>
            <main class="main">
                <?php self::renderToolbar($result); ?>
                <?php empty($entries) ? self::renderEmpty() : self::renderEntryTable($entries); ?>
            </main>
        </div>
        <footer class="footer">Made by the Baltic Sea by <a href="https://clsmedia.pl">CLS Media</footer>
        <script src="/assets/admin.js"></script>
        </body>
        </html>
        <?php
    }

    private static function renderTopbar(ListCapturedRequestsResult $result): void
    {
        ?>
        <header class="topbar">
            <div class="topbar-brand">
                <h1>Kapture</h1>
            </div>
            <div class="topbar-actions">
                <button id="live-btn" class="live-btn">live</button>
                <a class="raw-link"
                   href="<?= htmlspecialchars($result->selectedArchive !== null ? '?file=' . rawurlencode($result->selectedArchive) . '&raw' : '?raw', ENT_QUOTES) ?>">raw</a>
                <button class="logout-btn" onclick="logout()">log out</button>
            </div>
        </header>
        <?php
    }

    private static function renderSidebar(ListCapturedRequestsResult $result): void
    {
        ?>
        <aside class="sidebar">
            <h2>Archives</h2>
            <a class="file-item<?= $result->selectedArchive === null ? ' file-item--active' : '' ?>" href="/admin">all
                files (<?= count($result->dailyArchives) ?>)</a>
            <?php foreach ($result->dailyArchives as $date): ?>
                <a class="file-item<?= $date === $result->selectedArchive ? ' file-item--active' : '' ?>"
                   href="/admin?file=<?= rawurlencode($date) ?>"><?= htmlspecialchars($date, ENT_QUOTES) ?></a>
            <?php endforeach; ?>
        </aside>
        <?php
    }

    private static function renderToolbar(ListCapturedRequestsResult $result): void
    {
        ?>
        <div class="toolbar">
            <input class="filter-input" type="text" placeholder="Filter entries…"
                   oninput="filterTable(this.value)">
            <span id="count" class="count"><?= count($result->entries) ?> entries</span>
        </div>
        <?php
    }

    private static function renderEmpty(): void
    {
        ?>
        <div class="empty">No log entries yet. Send a request to /kapture/ to create one.</div>
        <?php
    }

    private static function formatBody(string $body): string
    {
        if ($body === '') {
            return '(empty)';
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $body;
        }

        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param CapturedRequest[] $entries
     */
    private static function renderEntryTable(array $entries): void
    {
        ?>
        <table id="log-table">
            <thead>
            <tr>
                <th>Time</th>
                <th>Method</th>
                <th>Capture ID</th>
                <th>URI</th>
                <th class="ip">IP</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $i => $entry): ?>
                <?php self::renderEntryRow($i, $entry); ?>
                <?php self::renderDetailRow($i, $entry); ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function renderEntryRow(int $i, CapturedRequest $entry): void
    {
        ?>
        <tr class="row" onclick="toggle('detail-<?= $i ?>')">
            <td class="ts"><?= htmlspecialchars($entry->capturedAt->toHumanReadable(), ENT_QUOTES) ?></td>
            <td class="method-cell"><span
                        class="method method-<?= htmlspecialchars($entry->method->value, ENT_QUOTES) ?>"><?= htmlspecialchars($entry->method->value, ENT_QUOTES) ?></span>
            </td>
            <td class="uid"><?= htmlspecialchars($entry->captureId, ENT_QUOTES) ?></td>
            <td class="uri"><?= htmlspecialchars((string)$entry->uri, ENT_QUOTES) ?></td>
            <td class="ip"><?= htmlspecialchars((string)$entry->ip, ENT_QUOTES) ?>
                <button class="expand-btn">&#9660;</button>
            </td>
        </tr>
        <?php
    }

    private static function renderDetailRow(int $i, CapturedRequest $entry): void
    {
        ?>
        <tr id="detail-<?= $i ?>" class="details-row" style="display:none">
            <td colspan="5">
                <div class="details" style="display:block">
                    <?php if ($entry->captureId !== ''): ?><h3>Capture ID</h3>
                        <pre><?= htmlspecialchars($entry->captureId, ENT_QUOTES) ?></pre><?php endif; ?>
                    <?php if ($entry->headers && count($entry->headers) > 0): ?><h3>Headers</h3>
                        <pre><?= htmlspecialchars(
                            json_encode($entry->headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                            ENT_QUOTES,
                    ) ?></pre><?php endif; ?>
                    <?php if ($entry->query && count($entry->query) > 0): ?><h3>Query</h3>
                        <pre><?= htmlspecialchars(
                            json_encode($entry->query, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                            ENT_QUOTES,
                    ) ?></pre><?php endif; ?>
                    <h3>Body</h3>
                    <pre><?= htmlspecialchars(self::formatBody($entry->body), ENT_QUOTES) ?: '(empty)' ?></pre>
                </div>
            </td>
        </tr>
        <?php
    }
}
