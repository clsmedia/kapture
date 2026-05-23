<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kapture</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>

<div class="layout">

<div class="sidebar">
  <h2>Log Files</h2>
  <a class="file-item<?= !$requestedFile ? ' file-item--active' : '' ?>" href="/admin/">all files <span class="size">(<?= count($files) ?>)</span></a>
  <?php foreach ($files as $date):
    $active = $requestedFile === $date;
  ?><a class="file-item<?= $active ? ' file-item--active' : '' ?>" href="<?= $h('?file=' . urlencode($date)) ?>"><?= $h($date) ?></a>
  <?php endforeach; ?>
</div>

<div class="main">

<div class="header">
  <h1>Kapture</h1>
  <div style="display:flex;align-items:center;gap:8px">
    <button class="live-btn" id="live-btn">live</button>
    <a href="<?= $h($requestedFile ? '?file=' . urlencode($requestedFile) . '&raw' : '?raw') ?>">raw</a>
    <button class="logout-btn" onclick="logout()">log out</button>
  </div>
</div>

<div class="source-info">Viewing: <strong><?= $h($label) ?></strong> &middot; <?= count($entries) ?> entries</div>

<?php if (count($entries) > 0): ?>
<div class="toolbar">
  <input class="filter-input" id="filter" placeholder="Filter by method, URI, IP..." oninput="filterTable(this.value)">
  <span class="count" id="count"><?= count($entries) ?> entries</span>
</div>
<table id="log-table">
<thead><tr>
  <th style="width:150px">Time</th>
  <th style="width:70px">Method</th>
  <th>URI</th>
  <th style="width:130px">IP</th>
  <th style="width:50px"></th>
</tr></thead>
<tbody>
<?php $idx = 0; foreach ($entries as $e): $idx++; ?>
<?php
  $method = $e->method;
  $methodClass = 'method-' . $method;
  $detailId = 'd-' . $idx;
?>
<tr class="row" onclick="toggle('<?= $h($detailId) ?>')">
  <td class="ts"><?= $h($e->capturedAt) ?></td>
  <td><span class="method <?= $h($methodClass) ?>"><?= $h($method) ?></span></td>
  <td class="uri"><?= $h($e->uri) ?></td>
  <td class="ip"><?= $h($e->ip) ?></td>
  <td><button class="expand-btn" onclick="event.stopPropagation();toggle('<?= $h($detailId) ?>')">&#9660;</button></td>
</tr>
<tr class="details-row" id="<?= $h($detailId) ?>" style="display:none">
  <td colspan="5">
    <div class="details" style="display:block">
      <?php if ($e->captureId): ?><h3>Capture ID</h3><pre><?= $h($e->captureId) ?></pre><?php endif; ?>
      <?php if ($e->query && count($e->query) > 0): ?><h3>Query Parameters</h3><pre><?= $h(json_encode($e->query, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?></pre><?php endif; ?>
      <?php if ($e->headers && count($e->headers) > 0): ?><h3>Headers</h3><pre><?= $h(json_encode($e->headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?></pre><?php endif; ?>
      <?php if ($e->body !== ''): ?><h3>Body</h3><pre><?= $h($e->body) ?></pre><?php endif; ?>
    </div>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<script src="/assets/admin.js"></script>
<?php else: ?>
<div class="empty">No log entries yet. Send a request to /webhook/ to create one.</div>
<?php endif; ?>

</div><!-- .main -->
</div><!-- .layout -->

</body>
</html>
