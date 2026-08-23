<?php

declare(strict_types=1);

namespace App\Presentation\Html;

use App\Application\ListCapturedRequestsResult;
use App\Domain\CapturedRequest;
use App\Domain\HttpMethod;

final class AdminView
{
    public function render(ListCapturedRequestsResult $result, string $csrfToken): void
    {
        $entries = $result->page->entries;
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
            <title>Kapture &middot; admin</title>
            <link rel="stylesheet" href="/assets/style.css">
        </head>
        <body>
        <?php $this->renderTopbar($result); ?>
        <div class="layout">
            <?php $this->renderSidebar($result); ?>
            <div class="sidebar-overlay" onclick="document.querySelector('.sidebar').classList.remove('sidebar--open');this.classList.remove('sidebar-overlay--visible')"></div>
            <main class="main">
                <?php $this->renderToolbar($result); ?>
                <?php empty($entries) ? $this->renderEmpty() : $this->renderEntryTable($entries, $result); ?>
            </main>
        </div>
        <footer class="footer">Made by the Baltic Sea by <a href="https://clsmedia.pl">CLS Media</a></footer>
        <div id="replay-modal" class="modal" style="display:none">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Replay Request</h3>
                    <button class="modal-close" onclick="closeReplayModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="modal-tabs">
                        <button class="modal-tab modal-tab--active" data-format="http" onclick="switchReplayTab(this)">.http</button>
                        <button class="modal-tab" data-format="curl" onclick="switchReplayTab(this)">curl</button>
                    </div>
                    <pre id="replay-content" class="replay-content"></pre>
                </div>
                <div class="modal-footer">
                    <button class="copy-btn" onclick="copyReplayContent()">Copy to clipboard</button>
                </div>
            </div>
        </div>
        <script src="/assets/admin.js"></script>
        </body>
        </html>
        <?php
    }

    private function renderTopbar(ListCapturedRequestsResult $result): void
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

    private function renderSidebar(ListCapturedRequestsResult $result): void
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

    private function renderToolbar(ListCapturedRequestsResult $result): void
    {
        ?>
        <div class="toolbar">
            <div class="method-pills">
                <?php foreach (HttpMethod::cases() as $method): ?>
                    <button class="method-pill method-pill--<?= htmlspecialchars($method->value, ENT_QUOTES) ?>"
                            data-method="<?= htmlspecialchars($method->value, ENT_QUOTES) ?>"
                            onclick="filterByMethod(this)"><?= htmlspecialchars($method->value, ENT_QUOTES) ?></button>
                <?php endforeach; ?>
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
            <span id="count" class="count"><?= count($result->page->entries) ?> entries</span>
            <div class="bulk-wrap">
                <button id="bulk-btn" class="kebab-btn" type="button" aria-haspopup="menu" aria-expanded="false"
                        aria-label="Bulk actions" onclick="toggleBulkMenu()">&#8942;<span id="bulk-count" class="bulk-count" hidden></span></button>
                <div id="bulk-menu" class="bulk-menu" role="menu" hidden>
                    <button id="bulk-delete" class="bulk-item" type="button" role="menuitem"
                            onclick="deleteSelected()" disabled>Delete selected (0)
                    </button>
                </div>
            </div>
            <div id="bulk-backdrop" class="bulk-backdrop" hidden onclick="closeBulkMenu()"></div>
        </div>
        <?php
    }

    private function renderEmpty(): void
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
            return htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
        }

        return json_encode($decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_THROW_ON_ERROR,
        );
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
    private function renderEntryTable(array $entries, ListCapturedRequestsResult $result): void
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
                <th class="sel-col"><input type="checkbox" id="select-all" aria-label="Select all visible" onclick="toggleSelectAll(this)"></th>
                <th>Time</th>
                <th>Method</th>
                <th>Capture ID</th>
                <th>URI</th>
                <th class="ip">IP</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $i => $entry): ?>
                <?php $this->renderEntryRow($i, $entry, $groupCounts, $queryGroupCounts); ?>
                <?php $this->renderDetailRow($i, $entry); ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php $this->renderPagination($result); ?>
        <?php
    }

    private function renderPagination(ListCapturedRequestsResult $result): void
    {
        $total = $result->page->totalEntries;
        $perPage = $result->page->perPage;
        $current = $result->page->currentPage;

        if ($total <= $perPage) {
            return;
        }

        $lastPage = (int) ceil($total / $perPage);

        $buildUrl = function (int $page) use ($result): string {
            $params = ['page' => $page];
            if ($result->selectedArchive !== null) {
                $params['file'] = $result->selectedArchive;
            }
            return '/admin?' . http_build_query($params);
        };
        ?>
        <nav class="pagination" role="navigation" aria-label="Pagination">
            <span class="page-info"><?= htmlspecialchars((string) $total, ENT_QUOTES) ?> entries</span>
            <div class="page-links">
                <?php if ($current > 1): ?>
                    <a class="page-link" href="<?= htmlspecialchars($buildUrl(1), ENT_QUOTES) ?>" aria-label="First page">&laquo;</a>
                    <a class="page-link" href="<?= htmlspecialchars($buildUrl($current - 1), ENT_QUOTES) ?>" aria-label="Previous page">&lsaquo;</a>
                <?php endif; ?>

                <?php
                $start = max(1, $current - 2);
                $end = min($lastPage, $current + 2);
                for ($p = $start; $p <= $end; $p++): ?>
                    <a class="page-link<?= $p === $current ? ' page-link--active' : '' ?>" href="<?= htmlspecialchars($buildUrl($p), ENT_QUOTES) ?>"><?= $p ?></a>
                <?php endfor; ?>

                <?php if ($current < $lastPage): ?>
                    <a class="page-link" href="<?= htmlspecialchars($buildUrl($current + 1), ENT_QUOTES) ?>" aria-label="Next page">&rsaquo;</a>
                    <a class="page-link" href="<?= htmlspecialchars($buildUrl($lastPage), ENT_QUOTES) ?>" aria-label="Last page">&raquo;</a>
                <?php endif; ?>
            </div>
        </nav>
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
    private function renderEntryRow(int $i, CapturedRequest $entry, array $groupCounts, array $queryGroupCounts): void
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
            <td class="sel-cell"><input type="checkbox" class="row-check" data-capture-id="<?= htmlspecialchars($entry->captureId, ENT_QUOTES) ?>" onclick="event.stopPropagation();toggleSelect(this)"></td>
            <?php
            $tsRaw = $entry->capturedAt->toHumanReadable();
            $tsParts = explode(' ', $tsRaw, 2);
            ?>
            <td class="ts"><span class="ts-date"><?= htmlspecialchars($tsParts[0], ENT_QUOTES) ?></span> <br class="ts-br"><span class="ts-time"><?= htmlspecialchars($tsParts[1] ?? '', ENT_QUOTES) ?></span></td>
            <td class="method-cell"><span
                        class="method method-<?= htmlspecialchars($entry->method->value, ENT_QUOTES) ?>"><?= htmlspecialchars($entry->method->value, ENT_QUOTES) ?></span>
            </td>
            <td class="uid"><?= htmlspecialchars($entry->captureId, ENT_QUOTES) ?></td>
            <td class="uri"><?php if ($entry->forwardUrl !== null):
                $fwdClass = 'forward-label';
                $sc = $entry->forwardStatusCode;
                if ($sc !== null && $sc >= 400 && $sc < 500) $fwdClass .= ' forward-label--warn';
                elseif ($sc !== null && $sc >= 500) $fwdClass .= ' forward-label--error';
                ?><span class="<?= $fwdClass ?>" title="Forwarded to <?= htmlspecialchars($entry->forwardUrl, ENT_QUOTES) ?> (<?= htmlspecialchars((string) $entry->forwardStatusCode, ENT_QUOTES) ?>)">▶ FORWARDED</span><?php endif; ?><?php if ($showGroup): ?>/<span class="uri-group"
                                                               data-group="<?= htmlspecialchars($group, ENT_QUOTES) ?>"
                                                               onclick="event.stopPropagation();filterByGroup(this)"><?= htmlspecialchars($group, ENT_QUOTES) ?></span><?php if ($restPath !== ''): ?><span class="uri-path"><?= htmlspecialchars($restPath, ENT_QUOTES) ?></span><?php endif; ?><?php else: ?><?= htmlspecialchars($path, ENT_QUOTES) ?><?php endif; ?><?= $queryHtml ?>
            </td>
            <td class="ip"><?= htmlspecialchars((string)$entry->ip, ENT_QUOTES) ?>
                <button class="expand-btn">&#9660;</button>
            </td>
        </tr>
        <?php
    }

    private function renderDetailRow(int $i, CapturedRequest $entry): void
    {
        ?>
        <tr id="detail-<?= $i ?>" class="details-row" style="display:none">
            <td colspan="6">
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
                    <?php if ($entry->forwardUrl !== null): ?><h3>Forwarded</h3>
                        <pre>Target: <?= htmlspecialchars($entry->forwardUrl, ENT_QUOTES) ?>
Status: <?= htmlspecialchars((string) $entry->forwardStatusCode, ENT_QUOTES) ?></pre><?php endif; ?>
                    <h3>Body</h3>
                    <pre><?= self::formatBody($entry->body) ?></pre>
                    <div class="detail-actions">
                        <button class="replay-btn" onclick="showReplayModal('<?= htmlspecialchars($entry->captureId, ENT_QUOTES) ?>')">replay</button>
                        <button class="delete-btn" onclick="deleteEntry('<?= htmlspecialchars($entry->captureId, ENT_QUOTES) ?>')">delete</button>
                    </div>
                </div>
            </td>
        </tr>
        <?php
    }
}
