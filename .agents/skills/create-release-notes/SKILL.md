---
name: create-release-notes
description: Generate concise, non-technical release notes by comparing the current branch with master. Use when asked to prepare, draft, or create release notes for an EAC pull request or as standalone notes, including requests for plain output without PR markers.
---

# Create release notes

Compare the current branch with `master` and describe only the changes introduced by the branch. Inspect the relevant diff and implementation before writing the notes; do not rely only on commit messages or PR titles.

Write for non-technical users and testers:

- Create a short, specific title.
- Summarize the user-visible outcome in one or two sentences.
- List meaningful user-facing changes under **Highlights**.
- List concrete testing steps and critical edge cases under **Testing focus**.
- Put deployment, configuration, external-service, monitoring, dependency, and other technical considerations under **Operational notes**, not Highlights.
- Mention new environment variables or required setup prominently. Existing variables that merely affect the feature may be included as low-priority reference.
- Do not list routine automatic migration execution as an action. Mention a migration only when its data, compatibility, rollback, or operational impact matters.
- Use `- None.` when there are no operational notes.
- Do not invent behavior or setup requirements that the diff does not support.

## Output modes

Use **PR-ready output** by default. Use **plain output** only when the user explicitly asks for plain, standalone, legacy, markerless, or non-PR-formatted notes.

### PR-ready output (default)

Return exactly one `markdown` fenced code block with the following structure and no explanatory text outside it. This lets the user copy the block contents directly into the PR body. Replace all placeholder text with the generated notes.

```markdown
## User-facing update

<!-- eac-update-note:start -->
### Short user-facing title

One or two sentence non-technical summary.

#### Highlights
- User-facing change

#### Testing focus
- Concrete testing step
<!-- eac-update-note:end -->

## Operational notes

<!-- eac-operational-notes:start -->
- Operational consideration, or None.
<!-- eac-operational-notes:end -->
```

Keep the marker comments exact. Include exactly one user-facing block and one operational block. Inside the user-facing block, include exactly one `###` title, one `#### Highlights` heading, and one `#### Testing focus` heading in that order. Put every highlight and testing item on its own single-line `- ` bullet so the repository validator accepts the note.

### Plain output

Omit the marker comments, PR section headings, and outer code fence. Use this structure:

```markdown
### Release Notes

One or two sentence non-technical summary.

#### Highlights
- User-facing change

#### Testing focus
- Concrete testing step

#### Operational notes
- Operational consideration, or None.
```
