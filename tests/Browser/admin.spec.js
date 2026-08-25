const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const { join } = require('node:path');
const { readFileSync } = require('node:fs');

const SEEDER = join(__dirname, 'seed.php');
const STORAGE_DRIVER = (readFileSync(join(__dirname, '..', '..', '.env'), 'utf8').match(/STORAGE_DRIVER=(\w+)/) ?? [])[1] ?? 'filesystem';

function seed(count, group) {
    const out = execFileSync('php', [SEEDER, String(count), group ?? 'e2e'], { encoding: 'utf8' });
    return JSON.parse(out.trim());
}

function cleanup(prefix) {
    if (!prefix) return;
    execFileSync('php', [SEEDER, 'cleanup', prefix], { encoding: 'utf8' });
}

async function openAdmin(page) {
    await page.goto('/admin');
    await expect(page.locator('#log-table')).toBeVisible();
}

test.beforeEach(async () => {
    // Purge orphans from previously crashed runs so count assertions
    // stay deterministic on this shared storage.
    cleanup('e2e-');
});

test.describe('auth', () => {
    test('admin rejects requests without basic auth credentials', async () => {
        const response = await fetch('http://localhost:8010/admin');
        expect(response.status).toBe(401);
        expect(await response.text()).toContain('Unauthorized');
    });

    test('logout returns 401 challenge with logged-out message', async ({ page }) => {
        const response = await page.goto('/admin/logout');
        expect(response.status()).toBe(401);
        await expect(page.locator('body')).toContainText('Logged out.');
    });
});

test.describe('table rendering', () => {
    let seeded;

    test.beforeEach(async ({ page }) => {
        seeded = seed(3, 'alpha');
        await openAdmin(page);
    });

    test.afterEach(() => cleanup(seeded.prefix));

    test('renders seeded captures as rows with capture ids', async ({ page }) => {
        for (const id of seeded.ids) {
            await expect(page.locator(`tr.row[data-capture-id="${id}"]`)).toBeVisible();
        }
        await expect(page.locator('#count')).toContainText(/entries$/);
    });

    test('clicking a row toggles its detail row with body preview', async ({ page }) => {
        const firstId = seeded.ids[0];
        const row = page.locator(`tr.row[data-capture-id="${firstId}"]`);
        const detail = page.locator(`tr.row[data-capture-id="${firstId}"] + tr.details-row`);

        await expect(detail).toBeHidden();
        await row.click();
        await expect(detail).toBeVisible();
        await expect(detail).toContainText('"hello": "world"');
        await row.click();
        await expect(detail).toBeHidden();
    });
});

test.describe('filters', () => {
    let alpha;
    let beta;

    test.beforeEach(async ({ page }) => {
        alpha = seed(3, 'alpha');
        beta = seed(2, 'beta');
        await openAdmin(page);
    });

    test.afterEach(() => {
        cleanup(alpha.prefix);
        cleanup(beta.prefix);
    });

    test('text filter narrows rows to the searched term and back', async ({ page }) => {
        await page.locator('.filter-input').fill(beta.ids[0]);
        await expect(page.locator('#count')).toHaveText('1 entries');
        await expect(page.locator(`tr.row[data-capture-id="${beta.ids[0]}"]`)).toBeVisible();
        await expect(page.locator(`tr.row[data-capture-id="${alpha.ids[0]}"]`)).toBeHidden();

        await page.locator('.filter-input').fill('');
        await expect(page.locator(`tr.row[data-capture-id="${alpha.ids[0]}"]`)).toBeVisible();
    });

    test('server-side search filters the archive, deep-links, and clears', async ({ page }) => {
        await page.locator('.filter-input').fill(beta.prefix);
        await expect(page.locator('#count')).toHaveText('2 entries');
        await expect(page.locator(`tr.row[data-capture-id="${beta.ids[0]}"]`)).toBeVisible();
        await expect(page.locator(`tr.row[data-capture-id="${alpha.ids[0]}"]`)).toBeHidden();
        await expect(page.locator('#search-clear')).toBeVisible();
        await expect(page).toHaveURL(new RegExp('q=' + beta.prefix));

        await page.goto('/admin?q=' + beta.prefix);
        await expect(page.locator('#count')).toHaveText('2 entries');
        await expect(page.locator(`tr.row[data-capture-id="${beta.ids[1]}"]`)).toBeVisible();
        await expect(page.locator(`tr.row[data-capture-id="${alpha.ids[0]}"]`)).toBeHidden();

        await page.locator('#search-clear').click();
        await expect(page.locator(`tr.row[data-capture-id="${alpha.ids[0]}"]`)).toBeVisible();
        await expect(page).toHaveURL(/\/admin$/);
    });

    test('method pill filters by HTTP method and is clearable', async ({ page }) => {
        await page.locator('.method-pill--POST').click();
        const visibleRows = page.locator('#log-table tbody tr.row:visible');
        const count = await visibleRows.count();
        expect(count).toBeGreaterThanOrEqual(5);
        for (let i = 0; i < count; i++) {
            await expect(visibleRows.nth(i)).toHaveAttribute('data-method', 'POST');
        }
        await expect(page.locator('#method-clear')).toBeVisible();

        await page.locator('#method-clear').click();
        await expect(page.locator(`tr.row[data-capture-id="${beta.ids[0]}"]`)).toBeVisible();
    });

    test('uri group click filters to that group and is clearable', async ({ page }) => {
        await page.locator('.uri-group[data-group="alpha"]').first().click();
        await expect(page.locator('#count')).toHaveText('3 entries');
        await expect(page.locator(`tr.row[data-capture-id="${beta.ids[0]}"]`)).toBeHidden();
        await expect(page.locator('#group-clear')).toBeVisible();

        await page.locator('#group-clear').click();
        await expect(page.locator(`tr.row[data-capture-id="${beta.ids[0]}"]`)).toBeVisible();
    });

    test('query param group click filters to matching pairs and is clearable', async ({ page }) => {
        const qgroup = await page
            .locator(`tr.row[data-capture-id="${alpha.ids[0]}"] .uri-qgroup`)
            .first()
            .getAttribute('data-qgroup');
        expect(qgroup).toBeTruthy();

        await page.locator(`tr.row[data-capture-id="${alpha.ids[0]}"] .uri-qgroup`).first().click();
        await expect(page.locator('#count')).toHaveText('3 entries');
        await expect(page.locator(`tr.row[data-capture-id="${beta.ids[0]}"]`)).toBeHidden();
        await expect(page.locator('#qgroup-clear')).toBeVisible();

        await page.locator('#qgroup-clear').click();
        await expect(page.locator(`tr.row[data-capture-id="${beta.ids[0]}"]`)).toBeVisible();
    });
});

test.describe('selection and bulk delete', () => {
    let seeded;

    test.beforeEach(async ({ page }) => {
        seeded = seed(3, 'alpha');
        await openAdmin(page);
    });

    test.afterEach(() => cleanup(seeded.prefix));

    test('checking a row enables bulk delete with a live count', async ({ page }) => {
        const bulkBtn = page.locator('#bulk-delete');
        await expect(bulkBtn).toBeDisabled();

        await page.locator(`tr.row[data-capture-id="${seeded.ids[0]}"] .row-check`).check();
        await expect(bulkBtn).toBeEnabled();
        await expect(bulkBtn).toContainText('(1)');

        await page.locator('#select-all').check();
        const visibleCount = await page.locator('#log-table tbody tr.row:visible').count();
        await expect(bulkBtn).toContainText(`(${visibleCount})`);
    });

    test('bulk delete removes selected rows after confirmation', async ({ page }) => {
        page.on('dialog', (dialog) => dialog.accept());

        for (const id of seeded.ids.slice(0, 2)) {
            await page.locator(`tr.row[data-capture-id="${id}"] .row-check`).check();
        }
        await page.locator('#bulk-btn').click();
        await page.locator('#bulk-delete').click();

        // The delete+redirect roundtrip can complete before waitForURL
        // observes the intermediate URL — assert the final table state.
        await expect(page.locator(`tr.row[data-capture-id="${seeded.ids[0]}"]`)).toHaveCount(0);
        await expect(page.locator(`tr.row[data-capture-id="${seeded.ids[2]}"]`)).toBeVisible();
    });
});

test.describe('single entry delete', () => {
    let seeded;

    test.beforeEach(async ({ page }) => {
        seeded = seed(2, 'alpha');
        await openAdmin(page);
    });

    test.afterEach(() => cleanup(seeded.prefix));

    test('delete button removes the entry after confirmation', async ({ page }) => {
        page.on('dialog', (dialog) => dialog.accept());
        const victim = seeded.ids[0];

        await page.locator(`tr.row[data-capture-id="${victim}"]`).click();
        await page
            .locator(`tr.row[data-capture-id="${victim}"] + tr.details-row .delete-btn`)
            .click();

        // Same instant-redirect race as bulk delete — assert final state.
        await expect(page.locator(`tr.row[data-capture-id="${victim}"]`)).toHaveCount(0);
        await expect(page.locator(`tr.row[data-capture-id="${seeded.ids[1]}"]`)).toBeVisible();
    });
});

test.describe('replay modal', () => {
    let seeded;

    test.beforeEach(async ({ page }) => {
        seeded = seed(1, 'alpha');
        await openAdmin(page);
    });

    test.afterEach(() => cleanup(seeded.prefix));

    test('opens with .http content and switches to curl format', async ({ page }) => {
        await page.locator(`tr.row[data-capture-id="${seeded.ids[0]}"]`).click();
        await page
            .locator(`tr.row[data-capture-id="${seeded.ids[0]}"] + tr.details-row .replay-btn`)
            .click();

        const modal = page.locator('#replay-modal');
        await expect(modal).toBeVisible();
        await expect(modal.locator('#replay-content')).toContainText('/alpha/hook-0', { timeout: 10_000 });

        await modal.locator('.modal-tab[data-format="curl"]').click();
        await expect(modal.locator('#replay-content')).toContainText('curl', { timeout: 10_000 });

        await modal.locator('.modal-close').click();
        await expect(modal).toBeHidden();
    });

    test('copy button writes replay content to the clipboard', async ({ browser }) => {
        const context = await browser.newContext({
            httpCredentials: { username: '', password: 'changeme' },
            permissions: ['clipboard-read', 'clipboard-write'],
        });
        const page = await context.newPage();
        const own = seed(1, 'alpha');

        try {
            await page.goto('/admin');
            await page.locator(`tr.row[data-capture-id="${own.ids[0]}"]`).click();
            await page
                .locator(`tr.row[data-capture-id="${own.ids[0]}"] + tr.details-row .replay-btn`)
                .click();
            await expect(page.locator('#replay-content')).toContainText('/alpha/hook-0', { timeout: 10_000 });

            await page.locator('.copy-btn').click();
            await expect(page.locator('.copy-btn')).toContainText('Copied!');

            const clipboard = await page.evaluate(() => navigator.clipboard.readText());
            expect(clipboard).toContain('/alpha/hook-0');
        } finally {
            await context.close();
            cleanup(own.prefix);
        }
    });
});

test.describe('live polling', () => {
    let seeded;

    test.beforeEach(async ({ page }) => {
        seeded = seed(2, 'alpha');
        await openAdmin(page);
    });

    test.afterEach(() => cleanup(seeded.prefix));

    test('new capture appears without reload while live mode is on', async ({ page }) => {
        await page.locator('#live-btn').click();
        await expect(page.locator('#live-btn')).toContainText(/live \ds/);

        const fresh = seed(1, 'fresh');
        try {
            await expect(
                page.locator(`tr.row[data-capture-id="${fresh.ids[0]}"]`),
            ).toBeVisible({ timeout: 12_000 });
        } finally {
            cleanup(fresh.prefix);
        }

        await page.locator('#live-btn').click();
        await expect(page.locator('#live-btn')).toHaveText('live');
    });
});

test.describe('archives sidebar and raw view', () => {
    let seeded;

    test.beforeEach(async ({ page }) => {
        seeded = seed(2, 'alpha');
        await openAdmin(page);
    });

    test.afterEach(() => cleanup(seeded.prefix));

    test('sidebar lists today archive with link preserving deep-link format', async ({ page }) => {
        const today = new Date().toISOString().slice(0, 10);
        const link = page.locator(`.file-item[href="/admin?file=${today}"]`);
        await expect(link).toBeVisible();

        await link.click();
        await page.waitForURL(new RegExp(`file=${today}`));
        await expect(page.locator(`tr.row[data-capture-id="${seeded.ids[0]}"]`)).toBeVisible();
    });

    test('raw view returns JSONL containing seeded captures', async ({ request }) => {
        const response = await request.get('/admin?raw');
        if (STORAGE_DRIVER === 'sqlite') {
            // Raw JSONL replay is a filesystem-driver feature; sqlite documents 404.
            expect(response.status()).toBe(404);
            return;
        }
        expect(response.status()).toBe(200);
        const body = await response.text();
        for (const id of seeded.ids) {
            expect(body).toContain(id);
        }
    });
});

test.describe('pagination', () => {
    let seeded;

    test.beforeAll(async () => {
        seeded = seed(101, 'page');
    });

    test.afterAll(() => cleanup(seeded.prefix));

    test('renders pagination when entries exceed per-page limit', async ({ page }) => {
        await openAdmin(page);
        await expect(page.locator('.pagination')).toBeVisible();
        await expect(page.locator('.page-info')).toContainText(/\d{3,} entries/);

        await page.locator('.page-link', { hasText: /^2$/ }).first().click();
        await page.waitForURL(/page=2/);
        await expect(page.locator('#log-table tbody tr.row').first()).toBeVisible();
    });

    test('deep link to page 2 renders rows directly', async ({ page }) => {
        await page.goto('/admin?page=2');
        await expect(page.locator('#log-table tbody tr.row').first()).toBeVisible();
    });
});
