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
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Kapture &middot; admin</title>
            <link rel="stylesheet" href="/assets/style.css">
        </head>
        <body x-data="kaptureAdmin" x-on:keydown.escape.window="onEscapeKey()">
        <?php $this->renderTopbar(); ?>
        <div class="layout">
            <?php $this->renderSidebar($result); ?>
            <div class="sidebar-overlay" x-on:click="closeSidebar()"></div>
            <main class="main">
                <?php $this->renderToolbar(); ?>
                <div class="empty" x-cloak x-show="loaded && entries.length === 0">No log entries yet. Send a request to /kapture/ to create one.</div>
                <table id="log-table" x-cloak x-show="entries.length > 0">
                    <thead>
                    <tr>
                        <th class="sel-col"><input type="checkbox" id="select-all" aria-label="Select all visible" x-effect="syncSelectAllState()" :checked="allVisibleSelected" x-on:click="toggleSelectAll()"></th>
                        <th>Time</th>
                        <th>Method</th>
                        <th>Capture ID</th>
                        <th>URI</th>
                        <th class="ip">IP</th>
                    </tr>
                    </thead>
                    <template x-for="entry in filteredEntries" :key="entry.captureId">
                        <tbody>
                        <tr class="row"
                            :class="selected[entry.captureId] ? 'row--selected' : ''"
                            :data-capture-id="entry.captureId"
                            :data-method="entry.method"
                            :data-uri="entry.uri"
                            :data-group="showGroup(entry) ? groupOf(entry) : null"
                            :data-qgroups="qgroupsAttr(entry)"
                            x-on:click="toggleDetail(entry.captureId)">
                            <td class="sel-cell"><input type="checkbox" class="row-check" :checked="selected[entry.captureId]" x-on:click.stop="toggleSelect(entry.captureId)"></td>
                            <td class="ts"><span class="ts-date" x-text="tsDate(entry)"></span> <br class="ts-br"><span class="ts-time" x-text="tsTime(entry)"></span></td>
                            <td class="method-cell"><span class="method" :class="'method-' + entry.method" x-text="entry.method"></span></td>
                            <td class="uid" x-text="entry.captureId"></td>
                            <td class="uri"><template x-if="entry.forwardUrl"><span class="forward-label" :class="forwardClass(entry)" :title="'Forwarded to ' + entry.forwardUrl + ' (' + entry.forwardStatusCode + ')'">&#9654; FORWARDED</span></template><template x-if="showGroup(entry)"><span>/</span></template><template x-if="showGroup(entry)"><span class="uri-group"
                                         :data-group="groupOf(entry)"
                                         x-on:click.stop="filterByGroup(groupOf(entry))" x-text="groupOf(entry)"></span></template><template x-if="showGroup(entry)"><span class="uri-path" x-text="restOf(entry)"></span></template><template x-if="!showGroup(entry)"><span x-text="pathOf(entry)"></span></template><template x-for="q in queryPairs(entry)" :key="q.pair"><span class="uri-qgroup" :data-qgroup="q.pair" :style="{ color: keyColor(q.key) }" x-on:click.stop="filterByQueryGroup(q.pair)" x-text="q.pair"></span></template><template x-if="!hasQuery(entry) && rawQueryOf(entry) !== ''"><span x-text="'?' + rawQueryOf(entry)"></span></template>
                            </td>
                            <td class="ip"><span x-text="entry.ip"></span>
                                <button class="expand-btn"><template x-if="isOpen(entry)"><span>&#9650;</span></template><template x-if="!isOpen(entry)"><span>&#9660;</span></template></button>
                            </td>
                        </tr>
                        <tr class="details-row" :class="isOpen(entry) ? 'expanded' : ''" x-show="isOpen(entry)">
                            <td colspan="6">
                                <div class="details">
                                    <template x-if="entry.captureId !== ''"><div><h3>Capture ID</h3>
                                        <pre x-text="entry.captureId"></pre></div></template>
                                    <template x-if="hasHeaders(entry)"><div><h3>Headers</h3>
                                        <pre x-text="detailHeaders(entry)"></pre></div></template>
                                    <template x-if="hasQuery(entry)"><div><h3>Query</h3>
                                        <pre x-text="detailQuery(entry)"></pre></div></template>
                                    <template x-if="entry.forwardUrl"><div><h3>Forwarded</h3>
                                        <pre x-text="forwardDetail(entry)"></pre></div></template>
                                    <h3>Body</h3>
                                    <pre x-text="detailBody(entry)"></pre>
                                    <div class="detail-actions">
                                        <button class="replay-btn" x-on:click="openReplay(entry.captureId)">replay</button>
                                        <button class="delete-btn" x-on:click="deleteEntry(entry.captureId)">delete</button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        </tbody>
                    </template>
                </table>
                <?php $this->renderPagination($result); ?>
            </main>
        </div>
        <footer class="footer">Made by the Baltic Sea by <a href="https://clsmedia.pl">CLS Media</a></footer>
        <div id="replay-modal" class="modal" x-cloak x-show="replay.open">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Replay Request</h3>
                    <button class="modal-close" x-on:click="closeReplay()">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="modal-tabs">
                        <button class="modal-tab" data-format="http" :class="replay.format === 'http' ? 'modal-tab--active' : ''" x-on:click="switchReplayFormat('http')">.http</button>
                        <button class="modal-tab" data-format="curl" :class="replay.format === 'curl' ? 'modal-tab--active' : ''" x-on:click="switchReplayFormat('curl')">curl</button>
                    </div>
                    <pre id="replay-content" class="replay-content" x-text="replay.content"></pre>
                </div>
                <div class="modal-footer">
                    <button class="copy-btn" x-on:click="copyReplayContent()" x-text="copyLabel">Copy to clipboard</button>
                </div>
            </div>
        </div>
        <script src="/assets/admin.js" defer></script>
        <script src="/assets/alpine-csp.min.js" defer></script>
        </body>
        </html>
        <?php
    }

    public function renderRows(ListCapturedRequestsResult $result): void
    {
        $entries = $result->page->entries;
        [$groupCounts, $queryGroupCounts] = self::countGroups($entries);
        foreach ($entries as $i => $entry) {
            $this->renderEntryRow($i, $entry, $groupCounts, $queryGroupCounts);
            $this->renderDetailRow($i, $entry);
        }
    }

    private function renderTopbar(): void
    {
        ?>
        <header class="topbar">
            <div class="topbar-brand">
                <button class="sidebar-toggle" x-on:click="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
                <h1>Kapture</h1>
            </div>
            <div class="topbar-actions">
                <button id="live-btn" class="live-btn" x-cloak x-show="liveAvailable" :class="live ? 'live-btn--on' : ''" x-on:click="toggleLive()" x-text="live ? 'live ' + liveCountdown + 's' : 'live'">live</button>
                <a class="raw-link" :href="rawLinkHref">raw</a>
                <button class="logout-btn" x-on:click="logout()">log out</button>
            </div>
        </header>
        <?php
    }

    private function renderSidebar(ListCapturedRequestsResult $result): void
    {
        ?>
        <aside class="sidebar">
            <h2>Archives</h2>
            <a class="file-item<?= $result->selectedArchive === null ? ' file-item--active' : '' ?>" :href="archiveUrl(null)" href="/admin">all
                files (<?= count($result->dailyArchives) ?>)</a>
            <?php foreach ($result->dailyArchives as $date): ?>
                <a class="file-item<?= $date === $result->selectedArchive ? ' file-item--active' : '' ?>"
                   :href="archiveUrl('<?= rawurlencode($date) ?>')"
                   href="/admin?file=<?= rawurlencode($date) ?>"><?= htmlspecialchars($date, ENT_QUOTES) ?> <span
                            class="size"><?= $result->archiveCounts[$date] ?? 0 ?></span></a>
            <?php endforeach; ?>
        </aside>
        <?php
    }

    private function renderToolbar(): void
    {
        ?>
        <div class="toolbar">
            <div class="method-pills">
                <?php foreach (HttpMethod::cases() as $method): ?>
                    <button class="method-pill method-pill--<?= htmlspecialchars($method->value, ENT_QUOTES) ?>"
                            data-method="<?= htmlspecialchars($method->value, ENT_QUOTES) ?>"
                            :class="activeMethod === '<?= htmlspecialchars($method->value, ENT_QUOTES) ?>' ? 'method-pill--active' : ''"
                            x-on:click="toggleMethod('<?= htmlspecialchars($method->value, ENT_QUOTES) ?>')"><?= htmlspecialchars($method->value, ENT_QUOTES) ?></button>
                <?php endforeach; ?>
            </div>
            <input class="filter-input" type="text" placeholder="Filter entries…" x-model="searchText">
            <button id="search-clear" class="group-clear" x-cloak x-show="serverSearch !== ''" x-on:click="clearSearch()">clear search</button>
            <button id="group-clear" class="group-clear" x-cloak x-show="activeGroup !== null" x-on:click="clearGroupFilter()">clear group
                filter
            </button>
            <button id="qgroup-clear" class="qgroup-clear" x-cloak x-show="activeQueryGroup !== null" x-on:click="clearQueryGroupFilter()">clear param
                filter
            </button>
            <button id="method-clear" class="method-clear" x-cloak x-show="activeMethod !== null" x-on:click="clearMethodFilter()">clear method filter</button>
            <span id="count" class="count" x-cloak x-text="filteredEntries.length + ' entries'"></span>
            <div class="bulk-wrap">
                <button id="bulk-btn" class="kebab-btn" type="button" aria-haspopup="menu" :aria-expanded="bulkMenuOpen ? 'true' : 'false'"
                        aria-label="Bulk actions" x-on:click="toggleBulkMenu()">&#8942;<span id="bulk-count" class="bulk-count" x-cloak x-show="selectedIds.length > 0" x-text="selectedIds.length"></span></button>
                <div id="bulk-menu" class="bulk-menu" role="menu" x-cloak x-show="bulkMenuOpen">
                    <button id="bulk-delete" class="bulk-item" type="button" role="menuitem"
                            :disabled="selectedIds.length === 0" x-on:click="deleteSelected()" x-text="'Delete selected (' + selectedIds.length + ')'">Delete selected (0)
                    </button>
                </div>
            </div>
            <div id="bulk-backdrop" class="bulk-backdrop" x-cloak x-show="bulkMenuOpen" x-on:click="closeBulkMenu()"></div>
        </div>
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
     * @return array{array<string, int>, array<string, int>} [groupCounts, queryGroupCounts]
     */
    private static function countGroups(array $entries): array
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
        return [$groupCounts, $queryGroupCounts];
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
}
