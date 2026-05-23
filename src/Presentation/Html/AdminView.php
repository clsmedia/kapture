<?php

declare(strict_types=1);

namespace App\Presentation\Html;

use App\Application\ListCapturedRequestsResult;

final readonly class AdminView
{
    public static function render(ListCapturedRequestsResult $result): void
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
<body>
<div class="layout">
<aside class="sidebar">
<h2>Archives</h2>
<a class="file-item<?= $result->selectedArchive === null ? ' file-item--active' : '' ?>" href="/admin">all files (<?= count($result->dailyArchives) ?>)</a>
<?php foreach ($result->dailyArchives as $date): ?>
<a class="file-item<?= $date === $result->selectedArchive ? ' file-item--active' : '' ?>" href="/admin?file=<?= rawurlencode($date) ?>"><?= htmlspecialchars($date, ENT_QUOTES) ?></a>
<?php endforeach; ?>
</aside>
<main class="main">
<div class="header">
<h1>Kapture</h1>
<div>
<button id="live-btn" class="live-btn">live</button>
<button class="logout-btn" onclick="logout()">log out</button>
</div>
</div>
<div class="source-info"><?= htmlspecialchars($result->label, ENT_QUOTES) ?></div>
<div class="toolbar">
<input class="filter-input" type="text" placeholder="Filter entries…" oninput="filterTable(this.value)">
<span id="count" class="count"><?= count($result->entries) ?> entries</span>
</div>
<?php if (empty($result->entries)): ?>
<div class="empty">No entries found.</div>
<?php else: ?>
<table id="log-table">
<thead>
<tr>
<th>Method</th>
<th>URI</th>
<th>Time</th>
<th>IP</th>
</tr>
</thead>
<tbody>
<?php foreach ($result->entries as $i => $entry): ?>
<tr class="row" onclick="toggle('detail-<?= $i ?>')">
<td><span class="method method-<?= htmlspecialchars(strtoupper((string)$entry->method), ENT_QUOTES) ?>"><?= htmlspecialchars(strtoupper((string)$entry->method), ENT_QUOTES) ?></span></td>
<td class="uri"><?= htmlspecialchars((string)$entry->uri, ENT_QUOTES) ?></td>
<td class="ts"><?= htmlspecialchars((string)$entry->capturedAt, ENT_QUOTES) ?></td>
<td class="ip"><?= htmlspecialchars((string)$entry->ip, ENT_QUOTES) ?> <button class="expand-btn">&#9660;</button></td>
</tr>
<tr id="detail-<?= $i ?>" class="details-row" style="display:none">
<td colspan="4">
<div class="details" style="display:block">
<h3>Headers</h3>
<pre><?= htmlspecialchars(
    json_encode($entry->headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ENT_QUOTES,
) ?></pre>
<h3>Query</h3>
<pre><?= htmlspecialchars(
    json_encode($entry->query, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ENT_QUOTES,
) ?></pre>
<h3>Body</h3>
<pre><?= htmlspecialchars((string)$entry->body, ENT_QUOTES) ?: '(empty)' ?></pre>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</main>
</div>
<script src="/assets/admin.js"></script>
</body>
</html>
<?php
    }
}
