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

desc('Prepare the one-time legacy form cutover when its migration is pending');
task('forms:prepare-migration', function () {
    $required = test('cd {{release_path}} && php artisan forms:legacy-cutover-required --quiet --no-interaction');
    set('legacy_cutover_required', $required);
    set('legacy_maintenance_entered', false);

    if (! $required) {
        info('Legacy form cutover already completed; maintenance and snapshot steps are skipped.');

        return;
    }

    run('cd {{release_path}} && php artisan forms:legacy-preflight --no-interaction');
    run('cd {{release_path}} && php artisan down --render="errors::503"');
    set('legacy_maintenance_entered', true);
    run('cd {{release_path}} && php artisan schedule:pause --no-interaction');
    run('cd {{release_path}} && php artisan schedule:interrupt --no-interaction');
    run('cd {{release_path}} && php artisan queue:pause default --no-interaction');
    run('cd {{release_path}} && php artisan queue:restart');
    run('cd {{release_path}} && php artisan forms:legacy-preflight --no-interaction');
    run('cd {{release_path}} && php artisan forms:legacy-snapshot --no-interaction');
});

desc('Ensure default dynamic forms after migrations');
task('forms:finalize-migration', function () {
    run('cd {{release_path}} && php artisan forms:ensure-defaults --no-interaction');
});

desc('Leave maintenance mode after a successful cutover deployment');
task('forms:leave-maintenance', function () {
    if (get('legacy_maintenance_entered', false)) {
        run('cd {{release_path}} && php artisan schedule:resume --no-interaction');
        run('cd {{release_path}} && php artisan queue:resume default --no-interaction');
        run('cd {{release_path}} && php artisan up');
    }
});

task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'npm:build',
    'artisan:storage:link',
    'forms:prepare-migration',
    'artisan:migrate',
    'forms:finalize-migration',
    'deploy:symlink',
    'artisan:config:cache',
    'artisan:route:cache',
    'artisan:view:cache',
    'artisan:event:cache',
    'artisan:queue:restart',
    'forms:leave-maintenance',
    'deploy:unlock',
    'deploy:cleanup',
    'deploy:success',
]);

after('deploy:failed', 'deploy:unlock');
after('deploy:failed', function () {
    if (! get('legacy_maintenance_entered', false)) {
        return;
    }

    warning('The cutover deployment failed. Maintenance mode, the scheduler pause, and the default queue pause remain enabled. The old release symlink was retained if publication had not begun.');
    run('cd {{release_path}} && php artisan forms:legacy-restore-instructions --no-interaction', no_throw: true);
});
