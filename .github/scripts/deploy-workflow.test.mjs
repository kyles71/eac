import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const workflow = readFileSync(
    new URL('../workflows/deploy.yml', import.meta.url),
    'utf8',
);
const qualityWorkflow = readFileSync(
    new URL('../workflows/quality.yml', import.meta.url),
    'utf8',
);
const deploymentRecipe = readFileSync(
    new URL('../../deploy.php', import.meta.url),
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
        qualityWorkflow,
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

test('runs quality checks for pull requests instead of deployments', () => {
    assert.match(qualityWorkflow, /on:\n\s+pull_request:/);
    assert.match(qualityWorkflow, /quality:\n\s+if: \$\{\{ github\.event\.pull_request\.head\.repo\.full_name == github\.repository \}\}/);
    assert.match(qualityWorkflow, /environment: dev/);
    assert.doesNotMatch(workflow, /^\s+quality:$/m);

    const deployJob = workflow.slice(workflow.indexOf('\n  deploy:'));

    assert.doesNotMatch(deployJob, /^\s+- quality$/m);
    assert.match(deployJob, /^\s+- mysql$/m);
    assert.match(deployJob, /^\s+- browser$/m);
});

test('limits private Composer credentials to dependency downloads for trusted branches', () => {
    assert.match(qualityWorkflow, /COMPOSER_AUTH:[\s\S]*secrets\.MY_PRIVATE_GH_TOKEN/);
    assert.match(
        qualityWorkflow,
        /composer install --download-only --no-plugins --no-scripts --no-interaction --prefer-dist --no-progress/,
    );
    assert.doesNotMatch(qualityWorkflow, /composer config --global/);
});

test('keeps legacy cutover checks out of routine deployments', () => {
    assert.doesNotMatch(workflow, /LegacyFormBuilderMigrationTest|mysql-cutover/);
    assert.doesNotMatch(deploymentRecipe, /forms:legacy-|forms:prepare-migration|forms:leave-maintenance/);
    assert.doesNotMatch(deploymentRecipe, /task\('deploy'/);
    assert.match(deploymentRecipe, /after\('artisan:migrate', 'forms:ensure-defaults'\)/);
    assert.match(deploymentRecipe, /after\('deploy:symlink', 'artisan:queue:restart'\)/);
});
