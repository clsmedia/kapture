const { test, expect } = require('@playwright/test');

test.describe('root health check', () => {
    test('GET / returns anonymous JSON health with no-store', async ({ request }) => {
        const response = await request.get('/');

        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toContain('application/json');
        expect(response.headers()['cache-control']).toBe('no-store');

        const body = await response.json();
        expect(body.status).toBe('ok');
        expect(body.time).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/);
        expect(JSON.stringify(body).toLowerCase()).not.toContain('kapture');
    });

    test('POST / stays 404', async ({ request }) => {
        const response = await request.post('/', { data: {} });

        expect(response.status()).toBe(404);
        expect(await response.json()).toMatchObject({ error: 'not found' });
    });
});
