import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const workflow = readFileSync(
    new URL('../workflows/create-dev-pr.yml', import.meta.url),
    'utf8',
);

test('creates dev pull requests for fix and feature branch pushes', () => {
    assert.match(workflow, /^\s{6}- 'fix\/\*\*'$/m);
    assert.match(workflow, /^\s{6}- 'feature\/\*\*'$/m);
    assert.match(workflow, /^\s{2}pull-requests: write$/m);
    assert.match(workflow, /^\s{12}--base dev \\/m);
    assert.match(workflow, /^\s{12}--head "\$BRANCH_NAME" \\/m);
});

test('avoids duplicate pull requests and uses the repository template', () => {
    assert.match(workflow, /existing_pr_url="\$\(gh pr list/);
    assert.match(workflow, /if \[\[ -n "\$existing_pr_url" \]\]; then/);
    assert.match(workflow, /--body-file \.github\/pull_request_template\.md$/m);
});
