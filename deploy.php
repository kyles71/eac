<?php
namespace Deployer;

require 'recipe/laravel.php';

// Config

set('repository', 'https://github.com/kyles71/eac.git');

add('shared_files', ['.env']);
add('shared_dirs', ['storage']);
add('writable_dirs', []);

// Hosts
host(getenv('DEPLOY_HOST'))
    ->setLabels([
        'env' => 'dev',
    ])
    ->set('branch', 'dev')
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
