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

// Hooks
after('artisan:migrate', 'npm:build');
after('deploy:failed', 'deploy:unlock');
