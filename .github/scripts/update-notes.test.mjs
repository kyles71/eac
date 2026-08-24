import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
    OPERATIONS_END,
    OPERATIONS_START,
    USER_END,
    USER_START,
    buildDeploymentBlock,
    buildReleaseNotes,
    copyNoteBlocksFromDevPullRequest,
    createMasterPullRequest,
    extractOperationalBlocks,
    extractUserBlocks,
    featureHeadFromDevMergeCommit,
    findContaminatingMerge,
    findDevPullRequestForFeatureHead,
    findMergedPullRequest,
    findMergedDevPullRequest,
    handlePullRequestEvent,
    isValidOperationalBlock,
    isValidUserBlock,
    releasePreamble,
    releaseBranchFor,
    replaceDeploymentBlock,
    shouldDeployForPullRequest,
    transferableUpdateLabel,
    validatePullRequestData,
} from './update-notes.mjs';

const userBlock = [
    USER_START,
    '### Clearer account security',
    '',
    'Password requirements and account details are now easier to understand.',
    '',
    '#### Highlights',
    '- Password requirements appear wherever a password can be changed.',
    '',
    '#### Testing focus',
    '- Try each password form and confirm the feedback changes as requirements are met.',
    USER_END,
].join('\n');
const operationsBlock = [
    OPERATIONS_START,
    '- Run the focused account-management smoke tests.',
    OPERATIONS_END,
].join('\n');

test('grants write access needed to update pull request labels', () => {
    const workflow = readFileSync(new URL('../workflows/update-notes.yml', import.meta.url), 'utf8');

    assert.match(workflow, /^\s{2}pull-requests: write$/m);
    assert.match(workflow, /^\s{4}branches: \[dev, master\]$/m);
});

test('gates dev and production deployments through the PR label decision', () => {
    for (const workflowName of ['deploy-dev.yml', 'deploy-production.yml']) {
        const workflow = readFileSync(new URL(`../workflows/${workflowName}`, import.meta.url), 'utf8');

        assert.match(workflow, /node \.github\/scripts\/update-notes\.mjs deployment-decision/);
        assert.match(workflow, /should_deploy: \$\{\{ steps\.decision\.outputs\.should_deploy \}\}/);
        assert.match(workflow, /if: \$\{\{ needs\.decision\.outputs\.should_deploy == 'true' \}\}/);
    }
});

test('accepts the required manual note format and rejects malformed notes', () => {
    assert.equal(isValidUserBlock(userBlock), true);
    assert.equal(isValidUserBlock(`${USER_START}\n### Missing sections\n\nSummary\n${USER_END}`), false);
    assert.equal(extractUserBlocks(`${userBlock}\n${userBlock}`).length, 2);
    assert.equal(extractOperationalBlocks(operationsBlock).length, 1);
    assert.equal(isValidOperationalBlock(operationsBlock), true);
    assert.equal(isValidOperationalBlock(`${OPERATIONS_START}\n<!-- placeholder -->\n${OPERATIONS_END}`), false);
});

test('copies both note blocks from the dev pull request into a new master draft', () => {
    const template = [
        '## User-facing update',
        USER_START,
        '<!-- Write the update note here. -->',
        USER_END,
        '',
        '## Operational notes',
        OPERATIONS_START,
        '- None.',
        OPERATIONS_END,
    ].join('\n');
    const copied = copyNoteBlocksFromDevPullRequest(template, `${userBlock}\n\n${operationsBlock}`, true);

    assert.equal(extractUserBlocks(copied)[0], userBlock);
    assert.equal(extractOperationalBlocks(copied)[0], operationsBlock);
    assert.doesNotMatch(copied, /Write the update note here/);
});

test('backfills placeholders without replacing reviewed master notes', () => {
    const placeholder = [
        USER_START,
        '<!-- Write the update note here. -->',
        USER_END,
        '',
        OPERATIONS_START,
        '- None.',
        OPERATIONS_END,
    ].join('\n');
    const backfilled = copyNoteBlocksFromDevPullRequest(placeholder, `${userBlock}\n\n${operationsBlock}`);
    const reviewedUserBlock = userBlock.replace('Clearer account security', 'Reviewed account security');
    const reviewedOperationsBlock = operationsBlock.replace('focused account-management', 'reviewed account-management');
    const reviewed = `${reviewedUserBlock}\n\n${reviewedOperationsBlock}`;

    assert.equal(extractUserBlocks(backfilled)[0], userBlock);
    assert.equal(extractOperationalBlocks(backfilled)[0], operationsBlock);
    assert.equal(copyNoteBlocksFromDevPullRequest(reviewed, `${userBlock}\n\n${operationsBlock}`), reviewed);
});

test('validates detailed update notes without pathological backtracking', () => {
    const detailedBullet = `- ${'Confirm this detailed behavior during acceptance testing. '.repeat(8)}`;
    const detailedBlock = [
        USER_START,
        '### Detailed administration updates',
        '',
        'Administrators can now review a comprehensive set of application updates.',
        '',
        '#### Highlights',
        ...Array(13).fill(detailedBullet),
        '',
        '#### Testing focus',
        ...Array(13).fill(detailedBullet),
        USER_END,
    ].join('\n');
    const startedAt = Date.now();

    assert.equal(isValidUserBlock(detailedBlock), true);
    assert.ok(Date.now() - startedAt < 1_000);
});

test('builds release notes from approved pull requests only', () => {
    const releaseNotes = buildReleaseNotes([
        { labels: [{ name: 'updates-approved' }], body: `${userBlock}\n\n${operationsBlock}` },
        { labels: [{ name: 'skip-updates' }], body: `${userBlock}\n\n${operationsBlock}` },
    ]);

    assert.equal(releaseNotes.startsWith(USER_START), true);
    assert.match(releaseNotes, /Clearer account security/);
    assert.match(releaseNotes, /## Operational notes/);
    assert.doesNotMatch(releaseNotes, /## What's new/);
    assert.equal((releaseNotes.match(/Clearer account security/g) ?? []).length, 1);
});

test('omits user-facing release content when there are no approved notes', () => {
    assert.equal(buildReleaseNotes([]), '');
});

test('does not add release warning or review boilerplate', () => {
    const releaseScript = readFileSync(new URL('./create-production-release.sh', import.meta.url), 'utf8');

    assert.doesNotMatch(releaseScript, /No approved user-facing update note/);
    assert.doesNotMatch(releaseScript, /Review the user-facing and operational notes/);
});

test('provides the GitHub token names required by release notes and GitHub CLI', () => {
    const workflow = readFileSync(new URL('../workflows/deploy-production.yml', import.meta.url), 'utf8');

    assert.equal((workflow.match(/GITHUB_TOKEN: \$\{\{ github\.token \}\}/g) ?? []).length, 2);
    assert.match(workflow, /GH_TOKEN: \$\{\{ github\.token \}\}/);
});

test('stops release creation when approved note collection fails', async () => {
    const originalFetch = globalThis.fetch;
    const originalEnvironment = {
        GITHUB_REPOSITORY: process.env.GITHUB_REPOSITORY,
        GITHUB_SHA: process.env.GITHUB_SHA,
        GITHUB_TOKEN: process.env.GITHUB_TOKEN,
        PREVIOUS_RELEASE_TAG: process.env.PREVIOUS_RELEASE_TAG,
    };

    process.env.GITHUB_REPOSITORY = 'example/eac';
    process.env.GITHUB_SHA = 'production-sha';
    process.env.GITHUB_TOKEN = 'github-token';
    delete process.env.PREVIOUS_RELEASE_TAG;

    globalThis.fetch = async () => jsonResponse({ message: 'Server error' }, 500);

    try {
        await assert.rejects(releasePreamble(), /GitHub API returned 500/);
    } finally {
        globalThis.fetch = originalFetch;

        for (const [name, value] of Object.entries(originalEnvironment)) {
            if (value === undefined) {
                delete process.env[name];
            } else {
                process.env[name] = value;
            }
        }
    }
});

test('validates approved and explicitly skipped pull requests', () => {
    assert.match(validatePullRequestData({
        labels: [{ name: 'updates-approved' }],
        body: `${userBlock}\n\n${operationsBlock}`,
    }), /valid and approved/);
    assert.match(validatePullRequestData({ labels: [{ name: 'skip-updates' }] }), /explicitly skipped/);
    assert.throws(
        () => validatePullRequestData({ labels: [{ name: 'updates-approved' }, { name: 'skip-updates' }] }),
        /exactly one/,
    );
    assert.throws(
        () => validatePullRequestData({
            labels: [{ name: 'updates-approved' }],
            body: `${userBlock}\n\n${OPERATIONS_START}\n<!-- placeholder -->\n${OPERATIONS_END}`,
        }),
        /non-empty operational-notes block/,
    );
});

test('transfers only explicit and valid dev pull request decisions', () => {
    assert.equal(transferableUpdateLabel({
        labels: [{ name: 'updates-approved' }],
        body: `${userBlock}\n\n${operationsBlock}`,
    }), 'updates-approved');
    assert.equal(transferableUpdateLabel({ labels: [{ name: 'skip-updates' }] }), 'skip-updates');
    assert.equal(transferableUpdateLabel({
        labels: [{ name: 'updates-approved' }],
        body: `${USER_START}\n### Incomplete\n${USER_END}`,
    }), null);
    assert.equal(transferableUpdateLabel({ labels: [] }), null);
    assert.equal(transferableUpdateLabel({
        labels: [{ name: 'updates-approved' }, { name: 'skip-updates' }],
        body: `${userBlock}\n\n${operationsBlock}`,
    }), null);
});

test('skips automatic deployment only for a labeled source pull request', () => {
    const skippedPullRequest = { labels: [{ name: 'skip-deployment' }] };
    const ordinaryPullRequest = { labels: [{ name: 'updates-approved' }] };

    assert.equal(shouldDeployForPullRequest('push', skippedPullRequest), false);
    assert.equal(shouldDeployForPullRequest('push', ordinaryPullRequest), true);
    assert.equal(shouldDeployForPullRequest('push', null), true);
    assert.equal(shouldDeployForPullRequest('workflow_dispatch', skippedPullRequest), true);
});

test('finds only the dev pull request that produced the deployed merge commit', () => {
    const pullRequests = [
        { number: 10, base: { ref: 'master' }, merge_commit_sha: 'deployed', merged_at: '2026-07-31T12:00:00Z' },
        { number: 11, base: { ref: 'dev' }, merge_commit_sha: 'older', merged_at: '2026-07-31T12:00:00Z' },
        { number: 12, base: { ref: 'dev' }, merge_commit_sha: 'deployed', merged_at: '2026-07-31T12:01:00Z' },
    ];

    assert.equal(findMergedDevPullRequest(pullRequests, 'deployed')?.number, 12);
    assert.equal(findMergedDevPullRequest(pullRequests, 'missing'), null);
    assert.equal(findMergedPullRequest([
        { number: 13, base: { ref: 'master' }, merge_commit_sha: 'production', merged_at: '2026-07-31T12:02:00Z' },
    ], 'production', 'master')?.number, 13);
});

test('finds the original dev pull request for a locally merged feature head', () => {
    const pullRequests = [
        { number: 10, base: { ref: 'master' }, head: { sha: 'feature-head' } },
        { number: 11, base: { ref: 'dev' }, head: { sha: 'older-head' } },
        { number: 12, base: { ref: 'dev' }, head: { sha: 'feature-head' } },
    ];

    assert.equal(findDevPullRequestForFeatureHead(pullRequests, 'feature-head')?.number, 12);
    assert.equal(findDevPullRequestForFeatureHead(pullRequests, 'missing'), null);
    assert.equal(featureHeadFromDevMergeCommit({ parents: [{ sha: 'dev' }, { sha: 'feature-head' }] }), 'feature-head');
    assert.equal(featureHeadFromDevMergeCommit({ parents: [{ sha: 'single-parent' }] }), null);
    assert.equal(featureHeadFromDevMergeCommit({ parents: [{ sha: 'one' }, { sha: 'two' }, { sha: 'three' }] }), null);
});

test('uses the dev pull request head as the clean release branch', () => {
    assert.equal(releaseBranchFor({ head: { ref: 'feature/accounts' }, body: '' }), 'feature/accounts');
    assert.throws(() => releaseBranchFor({ head: { ref: 'dev' }, body: '' }), /cannot be used/);
    assert.throws(() => releaseBranchFor({ head: { ref: 'feature bad' }, body: '' }), /invalid/);
});

test('detects merge commits whose merged parent is not already in master', () => {
    const safe = { sha: 'safe-merge', parents: [{ sha: 'feature' }, { sha: 'master-parent' }] };
    const contaminated = { sha: 'dev-merge', parents: [{ sha: 'feature' }, { sha: 'dev-parent' }] };

    assert.equal(findContaminatingMerge([safe], ['master-parent']), null);
    assert.equal(findContaminatingMerge([safe, contaminated], ['master-parent'])?.sha, 'dev-merge');
});

test('updates deployment metadata without replacing the rest of the pull request', () => {
    const original = `Manual summary\n\n${buildDeploymentBlock({
        devPullRequestNumber: 10,
        deployedSha: 'aaaaaaaaaaaa1111',
        deployedAt: '2026-07-31T12:00:00Z',
        releaseBranch: 'feature/accounts',
        runUrl: 'https://github.com/example/repo/actions/runs/1',
    })}\n\nKeep this text.`;
    const updated = replaceDeploymentBlock(original, {
        devPullRequestNumber: 12,
        deployedSha: 'bbbbbbbbbbbb2222',
        deployedAt: '2026-07-31T13:00:00Z',
        releaseBranch: 'feature/accounts',
        runUrl: 'https://github.com/example/repo/actions/runs/2',
    });

    assert.match(updated, /Manual summary/);
    assert.match(updated, /Keep this text/);
    assert.match(updated, /Dev PR: #12/);
    assert.doesNotMatch(updated, /Dev PR: #10/);
});

test('keeps approval after body edits and removes it after new commits', async () => {
    const originalFetch = globalThis.fetch;
    const originalEnvironment = {
        EVENT_ACTION: process.env.EVENT_ACTION,
        GITHUB_REPOSITORY: process.env.GITHUB_REPOSITORY,
        GITHUB_TOKEN: process.env.GITHUB_TOKEN,
        PULL_REQUEST_NUMBER: process.env.PULL_REQUEST_NUMBER,
    };
    const requests = [];
    let labels = [{ name: 'updates-approved' }];

    process.env.EVENT_ACTION = 'edited';
    process.env.GITHUB_REPOSITORY = 'example/eac';
    process.env.GITHUB_TOKEN = 'github-token';
    process.env.PULL_REQUEST_NUMBER = '41';

    globalThis.fetch = async (url, options = {}) => {
        const method = options.method ?? 'GET';
        const path = String(url).replace('https://api.github.com/repos/example/eac', '');
        const body = options.body ? JSON.parse(options.body) : null;
        requests.push({ body, method, path });

        if (path === '/pulls/41') {
            return jsonResponse({
                base: { ref: 'dev' },
                body: `${userBlock}\n\n${operationsBlock}`,
                head: { sha: 'release-sha' },
                html_url: 'https://github.com/example/eac/pull/41',
                labels,
                number: 41,
            });
        }

        if (path === '/issues/41/labels/updates-approved' && method === 'DELETE') {
            labels = [];
            return new Response(null, { status: 204 });
        }

        if (path === '/statuses/release-sha' && method === 'POST') {
            return jsonResponse({ id: 1 }, 201);
        }

        throw new Error(`Unexpected request: ${method} ${path}`);
    };

    try {
        await handlePullRequestEvent();
        assert.equal(requests.filter((request) => request.method === 'DELETE').length, 0);
        assert.equal(
            requests.filter((request) => request.path === '/statuses/release-sha' && request.body?.state === 'success').length,
            1,
        );

        process.env.EVENT_ACTION = 'synchronize';
        await assert.rejects(handlePullRequestEvent(), /exactly one/);
        assert.equal(requests.filter((request) => request.method === 'DELETE').length, 1);
        assert.equal(
            requests.filter((request) => request.path === '/statuses/release-sha' && request.body?.state === 'failure').length,
            1,
        );
    } finally {
        globalThis.fetch = originalFetch;

        for (const [name, value] of Object.entries(originalEnvironment)) {
            if (value === undefined) {
                delete process.env[name];
            } else {
                process.env[name] = value;
            }
        }
    }
});

test('creates one draft master PR after a direct dev conflict merge and reuses it on rerun', async () => {
    const originalFetch = globalThis.fetch;
    const originalEnvironment = {
        DEPLOYED_AT: process.env.DEPLOYED_AT,
        DEPLOYED_SHA: process.env.DEPLOYED_SHA,
        DEPLOYMENT_RUN_URL: process.env.DEPLOYMENT_RUN_URL,
        GITHUB_REPOSITORY: process.env.GITHUB_REPOSITORY,
        GITHUB_TOKEN: process.env.GITHUB_TOKEN,
    };
    const requests = [];
    let createdPullRequest = null;
    let devLabels = [{ name: 'updates-approved' }];

    process.env.DEPLOYED_AT = '2026-07-31T15:00:00Z';
    process.env.DEPLOYED_SHA = 'deployed-sha';
    process.env.DEPLOYMENT_RUN_URL = 'https://github.com/example/eac/actions/runs/99';
    process.env.GITHUB_REPOSITORY = 'example/eac';
    process.env.GITHUB_TOKEN = 'github-token';

    globalThis.fetch = async (url, options = {}) => {
        const method = options.method ?? 'GET';
        const path = String(url).replace('https://api.github.com/repos/example/eac', '');
        const body = options.body ? JSON.parse(options.body) : null;
        requests.push({ body, method, path });

        if (path === '/commits/deployed-sha/pulls') {
            return jsonResponse([]);
        }

        if (path === '/commits/deployed-sha') {
            return jsonResponse({ parents: [{ sha: 'dev-parent' }, { sha: 'release-sha' }] });
        }

        if (path === '/commits/release-sha/pulls') {
            return jsonResponse([{
                base: { ref: 'dev' },
                body: `${userBlock}\n\n${operationsBlock}`,
                head: { ref: 'feature/accounts', repo: { full_name: 'example/eac' }, sha: 'release-sha' },
                labels: devLabels,
                merge_commit_sha: null,
                merged_at: null,
                number: 30,
                title: 'Improve accounts',
            }]);
        }

        if (path === '/branches/feature%2Faccounts') {
            return jsonResponse({ commit: { sha: 'release-sha' } });
        }

        if (path.startsWith('/compare/release-sha...deployed-sha')) {
            return jsonResponse({ commits: [], status: 'ahead', total_commits: 0 });
        }

        if (path === '/branches/master') {
            return jsonResponse({ commit: { sha: 'master-sha' } });
        }

        if (path.startsWith('/compare/master...release-sha')) {
            return jsonResponse({
                commits: [{ parents: [{ sha: 'master-sha' }], sha: 'release-sha' }],
                status: 'ahead',
                total_commits: 1,
            });
        }

        if (path.startsWith('/pulls?state=open&base=master&head=')) {
            return jsonResponse(createdPullRequest ? [createdPullRequest] : []);
        }

        if (path === '/pulls' && method === 'POST') {
            createdPullRequest = {
                ...body,
                body: body.body,
                head: { ref: 'feature/accounts', sha: 'release-sha' },
                html_url: 'https://github.com/example/eac/pull/41',
                labels: [],
                number: 41,
            };

            return jsonResponse(createdPullRequest, 201);
        }

        if (path === '/issues/41/labels' && method === 'POST') {
            createdPullRequest.labels = body.labels.map((name) => ({ name }));
            return jsonResponse(createdPullRequest.labels);
        }

        if (path === '/issues/41/labels/updates-approved' && method === 'DELETE') {
            createdPullRequest.labels = createdPullRequest.labels.filter((label) => label.name !== 'updates-approved');
            return new Response(null, { status: 204 });
        }

        if (path === '/pulls/41' && method === 'PATCH') {
            createdPullRequest = { ...createdPullRequest, ...body };
            return jsonResponse(createdPullRequest);
        }

        if (path === '/pulls/41' && method === 'GET') {
            return jsonResponse(createdPullRequest);
        }

        if (path === '/statuses/release-sha' && method === 'POST') {
            return jsonResponse({ id: 1 }, 201);
        }

        throw new Error(`Unexpected request: ${method} ${path}`);
    };

    try {
        await createMasterPullRequest();
        await createMasterPullRequest();

        devLabels = [{ name: 'skip-deployment' }];
        await createMasterPullRequest();

        assert.equal(requests.filter((request) => request.path === '/pulls' && request.method === 'POST').length, 1);
        assert.equal(requests.filter((request) => request.path === '/pulls/41' && request.method === 'PATCH').length, 1);
        assert.equal(requests.filter((request) => request.path === '/issues/41/labels' && request.method === 'POST').length, 1);
        assert.equal(requests.filter((request) => request.path === '/issues/41/labels/updates-approved' && request.method === 'DELETE').length, 1);
        assert.equal(requests.filter((request) => request.path === '/branches/feature%2Faccounts').length, 2);
        assert.match(createdPullRequest.body, /Dev PR: #30/);
        assert.match(createdPullRequest.body, /Clearer account security/);
        assert.equal(
            requests.filter((request) => request.path === '/statuses/release-sha' && request.body?.state === 'success').length,
            1,
        );
        assert.equal(
            requests.filter((request) => request.path === '/statuses/release-sha' && request.body?.state === 'failure').length,
            1,
        );
    } finally {
        globalThis.fetch = originalFetch;

        for (const [name, value] of Object.entries(originalEnvironment)) {
            if (value === undefined) {
                delete process.env[name];
            } else {
                process.env[name] = value;
            }
        }
    }
});

function jsonResponse(payload, status = 200) {
    return new Response(JSON.stringify(payload), {
        headers: { 'Content-Type': 'application/json' },
        status,
    });
}
