import { appendFile, readFile } from 'node:fs/promises';
import process from 'node:process';
import { pathToFileURL } from 'node:url';

export const USER_START = '<!-- eac-update-note:start -->';
export const USER_END = '<!-- eac-update-note:end -->';
export const OPERATIONS_START = '<!-- eac-operational-notes:start -->';
export const OPERATIONS_END = '<!-- eac-operational-notes:end -->';
export const DEPLOYMENT_START = '<!-- eac-dev-deployment:start -->';
export const DEPLOYMENT_END = '<!-- eac-dev-deployment:end -->';

const API_VERSION = '2022-11-28';
const UPDATE_STATUS_CONTEXT = 'updates-note';

export function extractUserBlocks(markdown) {
    return extractDelimitedBlocks(markdown ?? '', USER_START, USER_END);
}

export function extractOperationalBlocks(markdown) {
    return extractDelimitedBlocks(markdown ?? '', OPERATIONS_START, OPERATIONS_END);
}

export function isValidUserBlock(block) {
    const trimmedBlock = block.trim();

    if (!trimmedBlock.startsWith(USER_START) || !trimmedBlock.endsWith(USER_END)) {
        return false;
    }

    const lines = trimmedBlock
        .slice(USER_START.length, -USER_END.length)
        .trim()
        .split(/\r?\n/);
    const titleIndex = lines.findIndex((line) => line.trim() !== '');
    const highlightsIndexes = headingIndexes(lines, '#### Highlights');
    const testingFocusIndexes = headingIndexes(lines, '#### Testing focus');

    if (titleIndex === -1
        || !/^###\s+\S/.test(lines[titleIndex].trim())
        || highlightsIndexes.length !== 1
        || testingFocusIndexes.length !== 1) {
        return false;
    }

    const highlightsIndex = highlightsIndexes[0];
    const testingFocusIndex = testingFocusIndexes[0];

    if (titleIndex >= highlightsIndex || highlightsIndex >= testingFocusIndex) {
        return false;
    }

    const summaryLines = nonEmptyLines(lines.slice(titleIndex + 1, highlightsIndex));
    const highlightLines = nonEmptyLines(lines.slice(highlightsIndex + 1, testingFocusIndex));
    const testingFocusLines = nonEmptyLines(lines.slice(testingFocusIndex + 1));

    return summaryLines.length > 0
        && isBulletList(highlightLines)
        && isBulletList(testingFocusLines);
}

function headingIndexes(lines, heading) {
    return lines.flatMap((line, index) => line.trim() === heading ? [index] : []);
}

function nonEmptyLines(lines) {
    return lines.map((line) => line.trim()).filter(Boolean);
}

function isBulletList(lines) {
    return lines.length > 0 && lines.every((line) => /^-\s+\S/.test(line));
}

export function isValidOperationalBlock(block) {
    return block
        .replace(OPERATIONS_START, '')
        .replace(OPERATIONS_END, '')
        .replace(/<!--[\s\S]*?-->/g, '')
        .trim() !== '';
}

export function buildReleaseNotes(pullRequests) {
    const approved = pullRequests.filter((pullRequest) => {
        const labels = labelNames(pullRequest);
        return labels.includes('updates-approved') && !labels.includes('skip-updates');
    });
    const userBlocks = approved.flatMap((pullRequest) => extractUserBlocks(pullRequest.body ?? ''))
        .filter(isValidUserBlock);
    const operationsBlocks = approved.flatMap((pullRequest) => extractOperationalBlocks(pullRequest.body ?? ''))
        .filter(isValidOperationalBlock);

    if (userBlocks.length === 0) {
        return [
            '> [!WARNING]',
            '> No approved user-facing update note was found for this deployment. Add one before publishing this draft.',
        ].join('\n');
    }

    const sections = ['## What\'s new', '', ...userBlocks];

    if (operationsBlocks.length > 0) {
        sections.push('', '## Operational notes', '', ...operationsBlocks);
    }

    return sections.join('\n\n');
}

export function validatePullRequestData(pullRequest) {
    const labels = labelNames(pullRequest);
    const approved = labels.includes('updates-approved');
    const skipped = labels.includes('skip-updates');

    if (approved === skipped) {
        throw new Error('Apply exactly one of updates-approved or skip-updates.');
    }

    if (skipped) {
        return 'User-facing update notes are explicitly skipped.';
    }

    const blocks = extractUserBlocks(pullRequest.body ?? '');

    if (blocks.length !== 1 || !isValidUserBlock(blocks[0])) {
        throw new Error('The approved pull request must contain exactly one valid user-facing update-note block.');
    }

    const operationalBlocks = extractOperationalBlocks(pullRequest.body ?? '');

    if (operationalBlocks.length !== 1 || !isValidOperationalBlock(operationalBlocks[0])) {
        throw new Error('The approved pull request must contain exactly one non-empty operational-notes block.');
    }

    return 'The update note is valid and approved.';
}

export function findMergedDevPullRequest(pullRequests, deployedSha) {
    return pullRequests.find((pullRequest) => pullRequest.base?.ref === 'dev'
        && Boolean(pullRequest.merged_at)
        && pullRequest.merge_commit_sha === deployedSha) ?? null;
}

export function findDevPullRequestForFeatureHead(pullRequests, featureHeadSha) {
    return pullRequests.find((pullRequest) => pullRequest.base?.ref === 'dev'
        && pullRequest.head?.sha === featureHeadSha) ?? null;
}

export function featureHeadFromDevMergeCommit(commit) {
    if (!Array.isArray(commit.parents) || commit.parents.length !== 2) {
        return null;
    }

    return commit.parents[1]?.sha ?? null;
}

export function releaseBranchFor(pullRequest) {
    const branch = pullRequest.head?.ref || '';

    if (!isSafeReleaseBranchName(branch)) {
        throw new Error(`The release branch "${branch || '(missing)'}" is invalid.`);
    }

    if (['dev', 'master'].includes(branch)) {
        throw new Error(`The shared ${branch} branch cannot be used as an independently releasable branch.`);
    }

    return branch;
}

export function findContaminatingMerge(commits, masterAncestorShas) {
    const safeParents = new Set(masterAncestorShas);

    return commits.find((commit) => (commit.parents ?? []).slice(1)
        .some((parent) => !safeParents.has(parent.sha))) ?? null;
}

export function buildDeploymentBlock({ devPullRequestNumber, deployedSha, deployedAt, releaseBranch, runUrl }) {
    const run = runUrl ? `[successful dev deployment](${runUrl})` : 'successful dev deployment';

    return [
        DEPLOYMENT_START,
        '## Dev deployment',
        '',
        `- Dev PR: #${devPullRequestNumber}`,
        `- Release branch: \`${releaseBranch}\``,
        `- Deployed feature commit: \`${deployedSha.slice(0, 12)}\``,
        `- Verified by: ${run} on ${deployedAt}`,
        DEPLOYMENT_END,
    ].join('\n');
}

export function replaceDeploymentBlock(markdown, values) {
    return replaceDelimitedBlock(markdown ?? '', DEPLOYMENT_START, DEPLOYMENT_END, buildDeploymentBlock(values)).trimEnd() + '\n';
}

export async function createMasterPullRequest() {
    const deployedSha = requiredEnvironment('DEPLOYED_SHA');
    const repository = requiredEnvironment('GITHUB_REPOSITORY');
    const devPullRequest = await devPullRequestForDeployment(deployedSha);

    if (!devPullRequest) {
        await summary('No draft master PR was created because the deployed dev commit could not be tied to an original feature PR into dev.');
        return;
    }

    if (devPullRequest.head?.repo?.full_name !== repository) {
        throw new Error('Automatic master PR creation supports branches in this repository only.');
    }

    const releaseBranch = releaseBranchFor(devPullRequest);
    const branch = await githubRequest(`/branches/${encodeURIComponent(releaseBranch)}`);
    const releaseSha = branch.commit?.sha;

    if (!releaseSha) {
        throw new Error(`Unable to resolve the current head of ${releaseBranch}. Keep release branches until production is published.`);
    }

    await assertReleaseBranchIsSafe({ deployedSha, releaseBranch, releaseSha });

    const owner = repository.split('/')[0];
    const existing = await githubPaginate(`/pulls?state=open&base=master&head=${encodeURIComponent(`${owner}:${releaseBranch}`)}`);
    let masterPullRequest = existing[0] ?? null;
    const deploymentValues = {
        devPullRequestNumber: devPullRequest.number,
        deployedSha: releaseSha,
        deployedAt: process.env.DEPLOYED_AT || new Date().toISOString(),
        releaseBranch,
        runUrl: process.env.DEPLOYMENT_RUN_URL ?? '',
    };
    if (!masterPullRequest) {
        const template = await readPullRequestTemplate();
        masterPullRequest = await githubRequest('/pulls', {
            method: 'POST',
            body: JSON.stringify({
                base: 'master',
                body: replaceDeploymentBlock(template, deploymentValues),
                draft: true,
                head: releaseBranch,
                title: devPullRequest.title,
            }),
        });
        await summary(`Created draft master PR #${masterPullRequest.number} from \`${releaseBranch}\` after dev deployment succeeded.`);
    } else {
        masterPullRequest = await githubRequest(`/pulls/${masterPullRequest.number}`, {
            method: 'PATCH',
            body: JSON.stringify({ body: replaceDeploymentBlock(masterPullRequest.body ?? '', deploymentValues) }),
        });
        await summary(`Updated draft master PR #${masterPullRequest.number} with the latest successful dev deployment.`);
    }

    await removeLabel(masterPullRequest.number, 'updates-approved');
    await publishValidationStatus(masterPullRequest, false);
}

async function devPullRequestForDeployment(deployedSha) {
    const associated = await githubRequest(`/commits/${deployedSha}/pulls`);
    const mergedPullRequest = findMergedDevPullRequest(associated, deployedSha);

    if (mergedPullRequest) {
        return mergedPullRequest;
    }

    const deployedCommit = await githubRequest(`/commits/${deployedSha}`);
    const featureHeadSha = featureHeadFromDevMergeCommit(deployedCommit);

    if (!featureHeadSha) {
        return null;
    }

    const featurePullRequests = await githubRequest(`/commits/${featureHeadSha}/pulls`);

    return findDevPullRequestForFeatureHead(featurePullRequests, featureHeadSha);
}

export async function handlePullRequestEvent() {
    const action = requiredEnvironment('EVENT_ACTION');
    const pullRequestNumber = requiredEnvironment('PULL_REQUEST_NUMBER');
    let pullRequest = await githubRequest(`/pulls/${pullRequestNumber}`);

    if (pullRequest.base?.ref !== 'master') {
        return;
    }

    if (action === 'synchronize') {
        await removeLabel(pullRequestNumber, 'updates-approved');
        pullRequest = await githubRequest(`/pulls/${pullRequestNumber}`);
    }

    await publishValidationStatus(pullRequest, true);
}

async function assertReleaseBranchIsSafe({ deployedSha, releaseBranch, releaseSha }) {
    if (!await isAncestor(releaseSha, deployedSha)) {
        throw new Error(`The latest ${releaseBranch} commit is not contained in the successful dev deployment.`);
    }

    const master = await githubRequest('/branches/master');
    const comparison = await githubComparison('master', releaseSha);

    if (!['ahead', 'diverged'].includes(comparison.status) || comparison.commits.length === 0) {
        throw new Error(`${releaseBranch} has no independently releasable commits ahead of master.`);
    }

    if (comparison.total_commits > comparison.commits.length) {
        throw new Error(`${releaseBranch} has too many commits to verify safely. Open the master PR manually after reviewing its history.`);
    }

    const masterAncestors = new Set([master.commit.sha]);

    for (const commit of comparison.commits) {
        for (const parent of (commit.parents ?? []).slice(1)) {
            if (await isAncestor(parent.sha, master.commit.sha)) {
                masterAncestors.add(parent.sha);
            }
        }
    }

    const contamination = findContaminatingMerge(comparison.commits, masterAncestors);

    if (contamination) {
        throw new Error(`${releaseBranch} contains merge commit ${contamination.sha.slice(0, 12)} from a branch that is not in master. Do not merge dev into a release branch.`);
    }
}

async function publishValidationStatus(pullRequest, throwOnInvalid) {
    let state = 'success';
    let description;
    let validationError = null;

    try {
        description = validatePullRequestData(pullRequest);
    } catch (error) {
        state = 'failure';
        description = error.message;
        validationError = error;
    }

    await githubRequest(`/statuses/${pullRequest.head.sha}`, {
        method: 'POST',
        body: JSON.stringify({
            context: UPDATE_STATUS_CONTEXT,
            description: description.slice(0, 140),
            state,
            target_url: pullRequest.html_url,
        }),
    });
    process.stdout.write(`${description}\n`);

    if (validationError && throwOnInvalid) {
        throw validationError;
    }
}

async function releasePreamble() {
    try {
        const pullRequests = await pullRequestsSincePreviousTag();
        process.stdout.write(buildReleaseNotes(pullRequests));
    } catch (error) {
        process.stderr.write(`Unable to collect approved update notes: ${error.message}\n`);
        process.stdout.write(buildReleaseNotes([]));
    }
}

async function pullRequestsSincePreviousTag() {
    const previousTag = process.env.PREVIOUS_RELEASE_TAG ?? '';
    const currentSha = requiredEnvironment('GITHUB_SHA');
    const commitShas = new Set([currentSha]);

    if (previousTag) {
        const comparison = await githubComparison(previousTag, currentSha);
        comparison.commits.forEach((commit) => commit?.sha && commitShas.add(commit.sha));
    }

    const pullRequests = new Map();

    for (const sha of commitShas) {
        const associated = await githubRequest(`/commits/${sha}/pulls`);

        for (const pullRequest of associated) {
            if (pullRequest.base?.ref === 'master' && pullRequest.merged_at) {
                pullRequests.set(pullRequest.number, pullRequest);
            }
        }
    }

    return [...pullRequests.values()].sort((left, right) => new Date(left.merged_at) - new Date(right.merged_at));
}

async function githubComparison(base, head) {
    const commits = [];
    let first = null;

    for (let page = 1; page <= 10; page += 1) {
        const payload = await githubRequest(`/compare/${encodeURIComponent(base)}...${encodeURIComponent(head)}?per_page=100&page=${page}`);
        first ??= payload;
        commits.push(...(payload.commits ?? []));

        if ((payload.commits ?? []).length < 100) {
            break;
        }
    }

    return { ...first, commits };
}

async function isAncestor(ancestor, descendant) {
    if (ancestor === descendant) {
        return true;
    }

    const comparison = await githubComparison(ancestor, descendant);

    return comparison.status === 'ahead';
}

async function githubPaginate(path) {
    const items = [];

    for (let page = 1; page <= 10; page += 1) {
        const separator = path.includes('?') ? '&' : '?';
        const payload = await githubRequest(`${path}${separator}per_page=100&page=${page}`);
        items.push(...payload);

        if (payload.length < 100) {
            break;
        }
    }

    return items;
}

async function githubRequest(path, options = {}) {
    const repository = requiredEnvironment('GITHUB_REPOSITORY');
    const response = await fetch(`https://api.github.com/repos/${repository}${path}`, {
        ...options,
        headers: {
            Accept: 'application/vnd.github+json',
            Authorization: `Bearer ${requiredEnvironment('GITHUB_TOKEN')}`,
            'Content-Type': 'application/json',
            'X-GitHub-Api-Version': API_VERSION,
            ...(options.headers ?? {}),
        },
    });

    if (!response.ok) {
        throw new Error(`GitHub API returned ${response.status}: ${await response.text()}`);
    }

    return response.status === 204 ? null : response.json();
}

async function removeLabel(pullRequestNumber, label) {
    try {
        await githubRequest(`/issues/${pullRequestNumber}/labels/${encodeURIComponent(label)}`, { method: 'DELETE' });
    } catch (error) {
        if (!error.message.includes('returned 404')) {
            throw error;
        }
    }
}

async function readPullRequestTemplate() {
    try {
        return await readFile('.github/pull_request_template.md', 'utf8');
    } catch {
        return [
            '## Summary',
            '',
            'Review the linked dev PR and describe the production outcome.',
            '',
            USER_START,
            USER_END,
            '',
            OPERATIONS_START,
            '- None.',
            OPERATIONS_END,
        ].join('\n');
    }
}

async function summary(message) {
    process.stdout.write(`${message}\n`);

    if (process.env.GITHUB_STEP_SUMMARY) {
        await appendFile(process.env.GITHUB_STEP_SUMMARY, `${message}\n`);
    }
}

function replaceDelimitedBlock(markdown, start, end, replacement) {
    const expression = new RegExp(`${escapeRegExp(start)}[\\s\\S]*?${escapeRegExp(end)}`);

    if (expression.test(markdown)) {
        return markdown.replace(expression, replacement);
    }

    return `${markdown.trimEnd()}\n\n${replacement}`.trimStart();
}

function extractDelimitedBlocks(markdown, start, end) {
    const expression = new RegExp(`${escapeRegExp(start)}[\\s\\S]*?${escapeRegExp(end)}`, 'g');
    return markdown.match(expression) ?? [];
}

function labelNames(pullRequest) {
    return (pullRequest.labels ?? [])
        .map((label) => typeof label === 'string' ? label : label?.name)
        .filter(Boolean);
}

function isSafeReleaseBranchName(branch) {
    return typeof branch === 'string'
        && branch.length > 0
        && branch.length <= 200
        && !branch.startsWith('/')
        && !branch.endsWith('/')
        && !branch.endsWith('.')
        && !branch.includes('..')
        && !branch.includes('@{')
        && !/[\s~^:?*[\]\\]/.test(branch);
}

function singleLineMessage(message) {
    return String(message)
        .replace(/[\r\n]+/g, ' ')
        .slice(0, 300);
}

function requiredEnvironment(name) {
    const value = process.env[name];

    if (!value) {
        throw new Error(`${name} is required.`);
    }

    return value;
}

function escapeRegExp(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

async function main() {
    const command = process.argv[2];

    if (command === 'create-master-pr') {
        await createMasterPullRequest();
    } else if (command === 'handle-pr-event') {
        await handlePullRequestEvent();
    } else if (command === 'release-preamble') {
        await releasePreamble();
    } else {
        throw new Error(`Unknown update-notes command: ${command ?? '(missing)'}`);
    }
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
    main().catch(async (error) => {
        process.stderr.write(`${error.stack ?? error.message}\n`);
        await summary(`Update-note automation failed: ${singleLineMessage(error.message)}`);
        process.exitCode = 1;
    });
}
