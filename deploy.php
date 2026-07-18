<?php

declare(strict_types=1);

namespace Deployer;

require 'recipe/laravel.php';

// Config
set('keep_releases', 5);
set('repository', 'https://github.com/kyles71/eac.git');

add('shared_files', ['.env']);
add('shared_dirs', ['storage']);

set('http_user', 'www-data');
set('writable_mode', 'acl');
set('writable_recursive', true);

// Hosts
host(getenv('DEPLOY_HOST'))
    ->setLabels([
        'env' => 'dev',
    ])
    ->set('branch', 'dev')
    ->set('composer_options', '--verbose --prefer-dist --no-progress --no-interaction --optimize-autoloader')
    ->set('remote_user', getenv('DEPLOY_USER'))
    ->set('sudo_password', getenv('DEPLOY_PASSWORD'))
    ->set('deploy_path', '/var/www/html/eac-test');

// Tasks
desc('Install & build npm packages');
task('npm:build', function () {
    run('cd {{release_path}} && npm ci && npm run build');
});

desc('Snapshot and validate legacy forms before migrations');
task('forms:prepare-migration', function () {
    run('cd {{release_path}} && php artisan forms:legacy-snapshot --no-interaction');
    run('cd {{release_path}} && php artisan forms:legacy-preflight --no-interaction');
});

desc('Ensure defaults and verify the migrated form graph');
task('forms:finalize-migration', function () {
    run('cd {{release_path}} && php artisan forms:ensure-defaults --no-interaction');
    run('cd {{release_path}} && php artisan forms:legacy-verify --no-interaction');
});

// Hooks
before('artisan:migrate', 'forms:prepare-migration');
after('artisan:migrate', 'forms:finalize-migration');
after('forms:finalize-migration', 'npm:build');
after('deploy:failed', 'deploy:unlock');
