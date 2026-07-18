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

// Hooks
after('deploy:vendors', 'npm:build');
after('deploy:symlink', 'artisan:queue:restart');
after('deploy:failed', 'deploy:unlock');
