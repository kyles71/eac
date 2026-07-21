<?php

declare(strict_types=1);

namespace Deployer;

require 'recipe/laravel.php';

// Config
set('keep_releases', 5);
set('repository', 'https://github.com/kyles71/eac.git');

set('shared_files', ['.env']);
set('shared_dirs', ['storage']);

set('http_user', 'www-data');
set('writable_mode', 'acl');
set('writable_use_sudo', false);
set('writable_recursive', true);

set('composer_options', '--verbose --prefer-dist --no-progress --no-interaction --no-dev --optimize-autoloader');

// Hosts
host('dev')
    ->setHostname(getenv('DEPLOY_HOST'))
    ->setLabels([
        'env' => 'dev',
    ])
    ->set('branch', 'dev')
    ->set('remote_user', getenv('DEPLOY_USER'))
    ->set('deploy_path', '/var/www/html/eac-test');

host('production')
    ->setHostname(getenv('DEPLOY_HOST'))
    ->setLabels([
        'env' => 'production',
    ])
    ->set('branch', 'master')
    ->set('remote_user', getenv('DEPLOY_USER'))
    ->set('deploy_path', '/var/www/html/eac');

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
// after('deploy:vendors', 'npm:build');
after('deploy:symlink', 'artisan:queue:restart');
after('deploy:failed', 'deploy:unlock');
