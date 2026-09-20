<?php

declare(strict_types=1);

namespace App\Presentation\Html;

final class AnalysisView
{
    public function render(): void
    {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Kapture &middot; analysis</title>
            <link rel="stylesheet" href="/assets/style.css">
        </head>
        <body x-data="kaptureAnalysis">
        <header class="topbar">
            <div class="topbar-brand">
                <h1>Kapture</h1>
                <span class="source-info">recurring analysis</span>
            </div>
            <div class="topbar-actions">
                <a class="raw-link" href="/admin">captures</a>
                <button class="logout-btn" x-on:click="logout()">log out</button>
            </div>
        </header>
        <main class="analysis-main">
            <div class="analysis-header">
                <div>
                    <h2>Patterns and statistics</h2>
                    <p class="analysis-meta" x-text="metaLabel()" x-cloak></p>
                </div>
                <button class="run-btn" x-cloak x-show="loaded" :disabled="running" x-on:click="runNow()" x-text="runLabel()"></button>
            </div>

            <div class="analysis-error" x-cloak x-show="error" x-text="error"></div>

            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-value" x-text="scannedEntries" x-cloak></span>
                    <span class="stat-label">captures</span>
                </div>
                <div class="stat-card">
                    <span class="stat-value" x-text="uniqueFingerprints" x-cloak></span>
                    <span class="stat-label">endpoints</span>
                </div>
                <div class="stat-card">
                    <span class="stat-value stat-value--periodic" x-text="periodicCount" x-cloak></span>
                    <span class="stat-label">periodic</span>
                </div>
                <div class="stat-card">
                    <span class="stat-value stat-value--daily" x-text="dailyCount" x-cloak></span>
                    <span class="stat-label">daily</span>
                </div>
            </div>

            <section class="analysis-section">
                <h3>Recurring patterns</h3>
                <div class="empty" x-cloak x-show="loaded && patterns.length === 0">No recurring patterns detected in the current window.</div>
                <table class="analysis-table" x-cloak x-show="patterns.length > 0">
                    <thead>
                    <tr>
                        <th>Endpoint</th>
                        <th>Type</th>
                        <th>Period</th>
                        <th class="num">Count</th>
                        <th>Suggested cron</th>
                        <th>Last seen</th>
                    </tr>
                    </thead>
                    <template x-for="pattern in patterns" :key="pattern.fingerprint">
                        <tbody>
                        <tr>
                            <td class="endpoint">
                                <span class="method" :class="'method-' + fingerprintMethod(pattern.fingerprint)" x-text="fingerprintMethod(pattern.fingerprint)"></span>
                                <span class="analysis-uri" x-text="fingerprintUri(pattern.fingerprint)"></span>
                                <span class="analysis-ip" x-text="fingerprintIp(pattern.fingerprint)"></span>
                                <span class="badge badge--new" x-show="isNew(pattern)">new</span>
                            </td>
                            <td><span class="badge" :class="'badge--' + pattern.type" x-text="pattern.type"></span></td>
                            <td x-text="formatPeriod(pattern)"></td>
                            <td class="num" x-text="pattern.occurrences"></td>
                            <td><code class="cron" x-text="cronLabel(pattern)"></code></td>
                            <td class="analysis-muted" x-text="relativeTime(pattern.lastSeen)"></td>
                        </tr>
                        </tbody>
                    </template>
                </table>
            </section>

            <section class="analysis-section">
                <h3>Top offenders</h3>
                <div class="empty" x-cloak x-show="loaded && offenders.length === 0">No frequent repetitions detected in the current window.</div>
                <div class="offenders" x-cloak x-show="offenders.length > 0">
                    <template x-for="offender in offenders" :key="offender.fingerprint">
                        <div class="offender-row">
                            <div class="offender-label">
                                <span class="method" :class="'method-' + fingerprintMethod(offender.fingerprint)" x-text="fingerprintMethod(offender.fingerprint)"></span>
                                <span class="analysis-uri" x-text="fingerprintUri(offender.fingerprint)"></span>
                                <span class="analysis-ip" x-text="fingerprintIp(offender.fingerprint)"></span>
                            </div>
                            <div class="offender-bar-track">
                                <div class="offender-bar" :style="{ width: barWidth(offender) }"></div>
                            </div>
                            <div class="offender-count">
                                <strong x-text="offender.occurrences"></strong>
                                <span class="analysis-muted" x-text="offenderShare(offender)"></span>
                            </div>
                        </div>
                    </template>
                </div>
            </section>
        </main>
        <footer class="footer">Made by the Baltic Sea by <a href="https://clsmedia.pl">CLS Media</a></footer>
        <script src="/assets/admin.js" defer></script>
        <script src="/assets/alpine-csp.min.js" defer></script>
        </body>
        </html>
        <?php
    }
}
