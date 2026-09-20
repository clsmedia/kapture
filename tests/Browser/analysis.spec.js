const { test, expect } = require('@playwright/test');

test.describe('analysis view', () => {
    test('dashboard topbar links to the analysis view', async ({ page }) => {
        await page.goto('/admin');
        await expect(page.locator('#log-table')).toBeVisible();

        const link = page.locator('.topbar-actions a[href="/admin/analysis"]');
        await expect(link).toBeVisible();
        await link.click();

        await expect(page).toHaveURL(/\/admin\/analysis$/);
        await expect(page.locator('.stats-grid')).toBeVisible();
        await expect(page.locator('.stat-card')).toHaveCount(4);
    });

    test('analysis page runs the analysis on demand', async ({ page }) => {
        await page.goto('/admin/analysis');

        const runButton = page.locator('.run-btn');
        await expect(runButton).toBeVisible();
        await expect(runButton).toHaveText('Run now');

        await runButton.click();
        await expect(runButton).toHaveText('Run now', { timeout: 15_000 });
        await expect(page.locator('.analysis-error')).toBeHidden();
        await expect(page.locator('.analysis-meta')).toContainText('window');
    });
});
