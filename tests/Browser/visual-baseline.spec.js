const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const { join } = require('node:path');

const SEEDER = join(__dirname, 'seed.php');

let seeded;

test.beforeEach(async ({ page }) => {
    seeded = JSON.parse(
        execFileSync('php', [SEEDER, '5', 'alpha'], { encoding: 'utf8' }).trim(),
    );
    await page.goto('/admin');
    await expect(page.locator('#log-table')).toBeVisible();
});

test.afterEach(() => {
    execFileSync('php', [SEEDER, 'cleanup', seeded.prefix], { encoding: 'utf8' });
});

test('admin dashboard visual baseline — desktop', async ({ page }) => {
    await expect(page.locator('#log-table')).toBeVisible();
    await page.screenshot({ path: join(__dirname, 'baseline', 'admin-desktop.png'), fullPage: true });
});

test('admin dashboard visual baseline — mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.locator('#log-table')).toBeVisible();
    await page.screenshot({ path: join(__dirname, 'baseline', 'admin-mobile.png'), fullPage: true });
});
