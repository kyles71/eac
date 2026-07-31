import assert from 'node:assert/strict';
import test from 'node:test';

import {
    MAX_DIFF_BYTES,
    buildBoundedDiff,
    buildReleaseNotes,
    extractOperationalBlocks,
    extractUserBlocks,
    filesVisibleToModel,
    isValidUserBlock,
    renderNoteBlocks,
    replaceNoteBlocks,
    validateModelNote,
} from './update-notes.mjs';

const note = {
    title: 'Clearer account security',
    summary: 'Password requirements and account details are now easier to understand.',
    highlights: ['Password requirements appear wherever a password can be changed.'],
    testing_focus: ['Try each password form and confirm the feedback changes as requirements are met.'],
    operational_notes: ['Run the focused account-management smoke tests.'],
};

test('validates and renders the structured model response', () => {
    assert.deepEqual(validateModelNote(note), note);

    const { userBlock, operationsBlock } = renderNoteBlocks(note);

    assert.equal(isValidUserBlock(userBlock), true);
    assert.match(userBlock, /#### Highlights/);
    assert.match(operationsBlock, /Run the focused account-management smoke tests/);
});

test('rejects incomplete model output', () => {
    assert.throws(() => validateModelNote({ ...note, testing_focus: [] }), /at least one item/);
});

test('replaces only generated blocks and preserves manual pull request content', () => {
    const original = `Manual summary\n\n<!-- eac-update-note:start -->\nplaceholder\n<!-- eac-update-note:end -->\n\nKeep this text.\n\n<!-- eac-operational-notes:start -->\nplaceholder\n<!-- eac-operational-notes:end -->`;
    const updated = replaceNoteBlocks(original, note);

    assert.match(updated, /Manual summary/);
    assert.match(updated, /Keep this text/);
    assert.equal(extractUserBlocks(updated).length, 1);
    assert.equal(extractOperationalBlocks(updated).length, 1);
});

test('bounds diff input and excludes sensitive and generated files', () => {
    const files = [
        { filename: '.env', patch: '+SECRET=value' },
        { filename: 'package-lock.json', patch: '+lock data' },
        { filename: 'app/Services/Feature.php', patch: `+${'a'.repeat(MAX_DIFF_BYTES * 2)}`, additions: 1, deletions: 0, status: 'modified' },
        { filename: 'app/Services/Second.php', patch: '+second file', additions: 1, deletions: 0, status: 'modified' },
    ];
    const diff = buildBoundedDiff(files);

    assert.ok(Buffer.byteLength(diff) <= MAX_DIFF_BYTES);
    assert.doesNotMatch(diff, /SECRET|lock data/);
    assert.match(diff, /app\/Services\/Feature.php/);
    assert.deepEqual(
        filesVisibleToModel(files).map((file) => file.filename),
        ['app/Services/Feature.php', 'app/Services/Second.php'],
    );
});

test('builds release notes from approved pull requests only', () => {
    const { userBlock, operationsBlock } = renderNoteBlocks(note);
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
