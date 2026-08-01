#!/usr/bin/env bash

set -euo pipefail

: "${GH_TOKEN:?GH_TOKEN is required}"
: "${GITHUB_OUTPUT:?GITHUB_OUTPUT is required}"
: "${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
: "${GITHUB_SHA:?GITHUB_SHA is required}"

release_generation="${RELEASE_GENERATION:-1}"
release_timezone="${RELEASE_TIMEZONE:-America/New_York}"

if [[ ! "$release_generation" =~ ^[1-9][0-9]*$ ]]; then
    echo "RELEASE_GENERATION must be a positive integer." >&2
    exit 1
fi

git fetch --force --tags origin

existing_tag="$(
    git tag \
        --points-at "$GITHUB_SHA" \
        --list "v${release_generation}.*" \
        | grep -E "^v${release_generation}\.[0-9]{6}\.[1-9][0-9]*$" \
        | sort -V \
        | tail -n 1 \
        || true
)"

if [[ -n "$existing_tag" ]]; then
    release_tag="$existing_tag"
    echo "Reusing production tag ${release_tag} for ${GITHUB_SHA}."
else
    release_date="$(TZ="$release_timezone" date +%y%m%d)"
    latest_sequence="$(
        git tag --list "v${release_generation}.${release_date}.*" \
            | sed -nE "s/^v${release_generation}\.${release_date}\.([1-9][0-9]*)$/\1/p" \
            | sort -n \
            | tail -n 1
    )"
    next_sequence="$(( ${latest_sequence:-0} + 1 ))"
    release_tag="v${release_generation}.${release_date}.${next_sequence}"

    git config user.name "github-actions[bot]"
    git config user.email "41898282+github-actions[bot]@users.noreply.github.com"
    git tag \
        --annotate "$release_tag" \
        "$GITHUB_SHA" \
        --message "Production release ${release_tag}"
    git push origin "refs/tags/${release_tag}"

    echo "Created production tag ${release_tag} for ${GITHUB_SHA}."
fi

if gh release view "$release_tag" --repo "$GITHUB_REPOSITORY" >/dev/null 2>&1; then
    echo "GitHub Release ${release_tag} already exists; no duplicate was created."
else
    deployed_at="$(TZ="$release_timezone" date '+%Y-%m-%d %H:%M %Z')"
    short_sha="${GITHUB_SHA:0:7}"
    previous_release_tag="$(
        git describe \
            --tags \
            --abbrev=0 \
            --match "v${release_generation}.*" \
            "${GITHUB_SHA}^" \
            2>/dev/null \
            || true
    )"
    update_notes="$(
        PREVIOUS_RELEASE_TAG="$previous_release_tag" \
            node .github/scripts/update-notes.mjs release-preamble
    )"
    release_preamble="$(
        printf 'Originally deployed: %s\nProduction commit: `%s`' \
            "$deployed_at" \
            "$short_sha"
    )"

    if [[ -n "$update_notes" ]]; then
        release_preamble+=$'\n\n'
        release_preamble+="$update_notes"
    fi

    gh release create "$release_tag" \
        --repo "$GITHUB_REPOSITORY" \
        --verify-tag \
        --draft \
        --generate-notes \
        --title "$release_tag" \
        --notes "$release_preamble"

    echo "Created draft GitHub Release ${release_tag}."
fi

printf 'tag=%s\n' "$release_tag" >> "$GITHUB_OUTPUT"
