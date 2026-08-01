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
    createMasterPullRequest,
    extractOperationalBlocks,
    extractUserBlocks,
    findContaminatingMerge,
    findMergedDevPullRequest,
    handlePullRequestEvent,
    isValidOperationalBlock,
    isValidUserBlock,
    releaseBranchFor,
    replaceDeploymentBlock,
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
});

test('accepts the required manual note format and rejects malformed notes', () => {
    assert.equal(isValidUserBlock(userBlock), true);
    assert.equal(isValidUserBlock(`${USER_START}\n### Missing sections\n\nSummary\n${USER_END}`), false);
    assert.equal(extractUserBlocks(`${userBlock}\n${userBlock}`).length, 2);
    assert.equal(extractOperationalBlocks(operationsBlock).length, 1);
    assert.equal(isValidOperationalBlock(operationsBlock), true);
    assert.equal(isValidOperationalBlock(`${OPERATIONS_START}\n<!-- placeholder -->\n${OPERATIONS_END}`), false);
});

test('builds release notes from approved pull requests only', () => {
    const releaseNotes = buildReleaseNotes([
        { labels: [{ name: 'updates-approved' }], body: `${userBlock}\n\n${operationsBlock}` },
        { labels: [{ name: 'skip-updates' }], body: `${userBlock}\n\n${operationsBlock}` },
    ]);

    assert.match(releaseNotes, /## What's new/);
    assert.match(releaseNotes, /Clearer account security/);
    assert.equal((releaseNotes.match(/Clearer account security/g) ?? []).length, 1);
});

test('warns when a release has no approved notes', () => {
    assert.match(buildReleaseNotes([]), /WARNING/);
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

test('finds only the dev pull request that produced the deployed merge commit', () => {
    const pullRequests = [
        { number: 10, base: { ref: 'master' }, merge_commit_sha: 'deployed', merged_at: '2026-07-31T12:00:00Z' },
        { number: 11, base: { ref: 'dev' }, merge_commit_sha: 'older', merged_at: '2026-07-31T12:00:00Z' },
        { number: 12, base: { ref: 'dev' }, merge_commit_sha: 'deployed', merged_at: '2026-07-31T12:01:00Z' },
    ];

    assert.equal(findMergedDevPullRequest(pullRequests, 'deployed')?.number, 12);
    assert.equal(findMergedDevPullRequest(pullRequests, 'missing'), null);
});

test('uses a clean release branch marker for temporary dev integration pull requests', () => {
    assert.equal(releaseBranchFor({ head: { ref: 'feature/accounts' }, body: '' }), 'feature/accounts');
    assert.equal(
        releaseBranchFor({
            head: { ref: 'integration/accounts-on-dev' },
            body: '<!-- eac-release-branch: feature/accounts -->',
        }),
        'feature/accounts',
    );
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

test('removes approval and publishes a failing status after new commits', async () => {
    const originalFetch = globalThis.fetch;
    const originalEnvironment = {
        EVENT_ACTION: process.env.EVENT_ACTION,
        GITHUB_REPOSITORY: process.env.GITHUB_REPOSITORY,
        GITHUB_TOKEN: process.env.GITHUB_TOKEN,
        PULL_REQUEST_NUMBER: process.env.PULL_REQUEST_NUMBER,
    };
    const requests = [];
    let labels = [{ name: 'updates-approved' }];

    process.env.EVENT_ACTION = 'synchronize';
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
                base: { ref: 'master' },
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
        await assert.rejects(handlePullRequestEvent(), /exactly one/);
        assert.equal(requests.filter((request) => request.method === 'DELETE').length, 1);
        assert.equal(
            requests.find((request) => request.path === '/statuses/release-sha')?.body?.state,
            'failure',
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

test('creates one draft master PR after dev deployment and reuses it on rerun', async () => {
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
            return jsonResponse([{
                base: { ref: 'dev' },
                body: '',
                head: { ref: 'feature/accounts', repo: { full_name: 'example/eac' } },
                merge_commit_sha: 'deployed-sha',
                merged_at: '2026-07-31T14:59:00Z',
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
                number: 41,
            };

            return jsonResponse(createdPullRequest, 201);
        }

        if (path === '/issues/41/labels/updates-approved' && method === 'DELETE') {
            return jsonResponse({ message: 'Not Found' }, 404);
        }

        if (path === '/pulls/41' && method === 'PATCH') {
            createdPullRequest = { ...createdPullRequest, ...body };
            return jsonResponse(createdPullRequest);
        }

        if (path === '/statuses/release-sha' && method === 'POST') {
            return jsonResponse({ id: 1 }, 201);
        }

        throw new Error(`Unexpected request: ${method} ${path}`);
    };

    try {
        await createMasterPullRequest();

        createdPullRequest.body = createdPullRequest.body
            .replace(new RegExp(`${USER_START}[\\s\\S]*?${USER_END}`), userBlock)
            .replace(new RegExp(`${OPERATIONS_START}[\\s\\S]*?${OPERATIONS_END}`), operationsBlock);

        await createMasterPullRequest();

        assert.equal(requests.filter((request) => request.path === '/pulls' && request.method === 'POST').length, 1);
        assert.match(createdPullRequest.body, /Dev PR: #30/);
        assert.match(createdPullRequest.body, /Clearer account security/);
        assert.equal(
            requests.filter((request) => request.path === '/statuses/release-sha' && request.body?.state === 'failure').length,
            2,
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
