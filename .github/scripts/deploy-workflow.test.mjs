import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const workflow = readFileSync(
    new URL('../workflows/deploy.yml', import.meta.url),
    'utf8',
);
const phpunitConfiguration = readFileSync(
    new URL('../../phpunit.xml', import.meta.url),
    'utf8',
);

test('provides application and Stripe configuration for clean test environments', () => {
    assert.match(
        phpunitConfiguration,
        /<env name="APP_KEY" value="base64:[A-Za-z0-9+/]+={0,2}"\/>/,
    );
    assert.match(
        phpunitConfiguration,
        /<env name="STRIPE_SECRET" value="stripe-testing-placeholder"\/>/,
    );
});

test('runs application and browser tests in parallel', () => {
    assert.match(
        workflow,
        /vendor\/bin\/pest --no-progress --parallel --processes=4 --exclude-testsuite=Browser/,
    );
    assert.match(
        workflow,
        /vendor\/bin\/pest --no-progress tests\/Browser --parallel --processes=4/,
    );
});

test('caps browser test execution time', () => {
    assert.match(
        workflow,
        /- name: Run browser tests\n\s+timeout-minutes: 10\n/,
    );
});

test('links public storage before running browser tests', () => {
    assert.match(
        workflow,
        /- name: Link public storage\n\s+run: php artisan storage:link --no-interaction/,
    );
});
