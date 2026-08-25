// @ts-check
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './tests/Browser',
    timeout: 30_000,
    expect: { timeout: 5_000 },
    fullyParallel: false,
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL: 'http://localhost:8010',
        httpCredentials: { username: '', password: 'changeme' },
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
    webServer: {
        command: 'php -S localhost:8010 -t public',
        url: 'http://localhost:8010/admin',
        reuseExistingServer: true,
        timeout: 10_000,
    },
});
