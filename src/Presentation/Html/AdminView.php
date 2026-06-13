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
            <div class="sidebar-overlay" onclick="document.querySelector('.sidebar').classList.remove('sidebar--open');this.classList.remove('sidebar-overlay--visible')"></div>
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
                <button class="sidebar-toggle" onclick="document.querySelector('.sidebar').classList.toggle('sidebar--open');document.querySelector('.sidebar-overlay').classList.toggle('sidebar-overlay--visible')" aria-label="Toggle sidebar">&#9776;</button>
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
                   href="/admin?file=<?= rawurlencode($date) ?>"><?= htmlspecialchars($date, ENT_QUOTES) ?> <span
                            class="size"><?= $result->archiveCounts[$date] ?? 0 ?></span></a>
            <?php endforeach; ?>
        </aside>
        <?php
    }

    private static function renderToolbar(ListCapturedRequestsResult $result): void
    {
        ?>
        <div class="toolbar">
            <div class="method-pills">
                <button class="method-pill method-pill--GET" data-method="GET" onclick="filterByMethod(this)">GET</button>
                <button class="method-pill method-pill--POST" data-method="POST" onclick="filterByMethod(this)">POST</button>
                <button class="method-pill method-pill--PUT" data-method="PUT" onclick="filterByMethod(this)">PUT</button>
                <button class="method-pill method-pill--PATCH" data-method="PATCH" onclick="filterByMethod(this)">PATCH</button>
                <button class="method-pill method-pill--DELETE" data-method="DELETE" onclick="filterByMethod(this)">DELETE</button>
                <button class="method-pill method-pill--HEAD" data-method="HEAD" onclick="filterByMethod(this)">HEAD</button>
                <button class="method-pill method-pill--OPTIONS" data-method="OPTIONS" onclick="filterByMethod(this)">OPTIONS</button>
            </div>
            <input class="filter-input" type="text" placeholder="Filter entries…"
                   oninput="filterTable(this.value)">
            <button id="group-clear" class="group-clear" style="display:none" onclick="clearGroupFilter()">clear group
                filter
            </button>
            <button id="qgroup-clear" class="qgroup-clear" style="display:none" onclick="clearQueryGroupFilter()">clear param
                filter
            </button>
            <button id="method-clear" class="method-clear" style="display:none" onclick="clearMethodFilter()">clear method filter</button>
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
     * @return array{string, string} [firstPathSegment, remainingPathWithQuery]
     */
    private static function splitUri(string $uri): array
    {
        $parsed = parse_url($uri);
        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';

        $trimmed = ltrim($path, '/');
        if ($trimmed === '') {
            return ['', $uri];
        }

        $parts = explode('/', $trimmed, 2);
        $first = $parts[0];
        $rest = isset($parts[1]) ? '/' . $parts[1] : '';

        return [$first, $rest . $query];
    }

    /**
     * @param CapturedRequest[] $entries
     */
    private static function renderEntryTable(array $entries): void
    {
        $groupCounts = [];
        $queryGroupCounts = [];
        foreach ($entries as $entry) {
            [$group] = self::splitUri($entry->uri);
            if ($group !== '') {
                $groupCounts[$group] = ($groupCounts[$group] ?? 0) + 1;
            }
            foreach ($entry->query as $key => $value) {
                $pair = $key . '=' . $value;
                $queryGroupCounts[$pair] = ($queryGroupCounts[$pair] ?? 0) + 1;
            }
        }

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
                <?php self::renderEntryRow($i, $entry, $groupCounts, $queryGroupCounts); ?>
                <?php self::renderDetailRow($i, $entry); ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function getKeyColor(string $key): string
    {
        $palette = [
            '#86b6ff',
            '#8bd4a0',
            '#e8c88a',
            '#c5a5ff',
            '#f0a8a8',
            '#7dd8d8',
            '#e8b88a',
            '#d4a8d8',
        ];
        $idx = abs(crc32($key)) % count($palette);
        return $palette[$idx];
    }

    /**
     * @param array<string, int> $groupCounts
     * @param array<string, int> $queryGroupCounts
     */
    private static function renderEntryRow(int $i, CapturedRequest $entry, array $groupCounts, array $queryGroupCounts): void
    {
        $parsed = parse_url($entry->uri);
        $path = $parsed['path'] ?? '/';
        $queryString = $parsed['query'] ?? '';

        $trimmed = ltrim($path, '/');
        $group = '';
        $restPath = $path;
        if ($trimmed !== '') {
            $parts = explode('/', $trimmed, 2);
            $group = $parts[0];
            $restPath = isset($parts[1]) ? '/' . $parts[1] : '';
        }
        $showGroup = $group !== '' && ($groupCounts[$group] ?? 0) > 1;
        $groupAttr = $showGroup ? ' data-group="' . htmlspecialchars($group, ENT_QUOTES) . '"' : '';

        $qGroupAttr = '';
        $queryHtml = '';
        if ($entry->query !== []) {
            $pairs = [];
            $spans = [];
            foreach ($entry->query as $key => $value) {
                $pair = $key . '=' . $value;
                $pairs[] = $pair;
                $spans[] = '<span class="uri-qgroup" data-qgroup="' . htmlspecialchars($pair, ENT_QUOTES) . '" style="color:' . self::getKeyColor($key) . '" onclick="event.stopPropagation();filterByQueryGroup(this)">' . htmlspecialchars($pair, ENT_QUOTES) . '</span>';
            }
            $qGroupAttr = ' data-qgroups="|' . htmlspecialchars(implode('|', $pairs), ENT_QUOTES) . '|"';
            $queryHtml = '?' . implode('&', $spans);
        } elseif ($queryString !== '') {
            $queryHtml = '?' . htmlspecialchars($queryString, ENT_QUOTES);
        }
        ?>
        <tr class="row"<?= $groupAttr ?><?= $qGroupAttr ?> data-capture-id="<?= htmlspecialchars($entry->captureId, ENT_QUOTES) ?>" data-method="<?= htmlspecialchars($entry->method->value, ENT_QUOTES) ?>"
            data-uri="<?= htmlspecialchars($entry->uri, ENT_QUOTES) ?>" onclick="toggle('detail-<?= $i ?>')">
            <?php
            $tsRaw = $entry->capturedAt->toHumanReadable();
            $tsParts = explode(' ', $tsRaw, 2);
            ?>
            <td class="ts"><span class="ts-date"><?= htmlspecialchars($tsParts[0], ENT_QUOTES) ?></span> <br class="ts-br"><span class="ts-time"><?= htmlspecialchars($tsParts[1] ?? '', ENT_QUOTES) ?></span></td>
            <td class="method-cell"><span
                        class="method method-<?= htmlspecialchars($entry->method->value, ENT_QUOTES) ?>"><?= htmlspecialchars($entry->method->value, ENT_QUOTES) ?></span>
            </td>
            <td class="uid"><?= htmlspecialchars($entry->captureId, ENT_QUOTES) ?></td>
            <td class="uri"><?php if ($showGroup): ?>/<span class="uri-group"
                                                              data-group="<?= htmlspecialchars($group, ENT_QUOTES) ?>"
                                                              onclick="event.stopPropagation();filterByGroup(this)"><?= htmlspecialchars($group, ENT_QUOTES) ?></span><?php if ($restPath !== ''): ?><span class="uri-path"><?= htmlspecialchars($restPath, ENT_QUOTES) ?></span><?php endif; ?><?php else: ?><?= htmlspecialchars($path, ENT_QUOTES) ?><?php endif; ?><?= $queryHtml ?>
            </td>
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
                    <?php if ($entry->headers !== []): ?><h3>Headers</h3>
                        <pre><?= htmlspecialchars(
                            json_encode($entry->headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                            ENT_QUOTES,
                    ) ?></pre><?php endif; ?>
                    <?php if ($entry->query !== []): ?><h3>Query</h3>
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
