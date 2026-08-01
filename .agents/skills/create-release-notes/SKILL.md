---
name: create-release-notes
description: Generate non-technical release notes by comparing the current branch with master. Use when asked to prepare, draft, or create release notes.
---

Compare the changes of this branch to master. Provide me a clear, short one or two sentance release summary of the changes intended for non technical users. then provide me with 3 lists of information:
1) a list of the changes so users know what has changed from this release. this is intended for non technical users.
2) specific things that should be tested, especially if they aren't obvious from the new features list; be sure to include any critical edge cases. this is intended for non technical users.
3) a list of any new environment variables, configurations, or external services that need to be set up or monitored. migrations are ran automatically during deployment. you can list existing environment variables that impact the branch as just a low priority reference 

Ensure your response follows this format:
### Release Notes

The 1-2 sentance summary

#### Highlights
- List 1

#### Testing focus
- List 2

#### Operational notes
- List 3
