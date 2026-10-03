#!/usr/bin/env bash
# Run only after both native jobs upload their tested image digests.
set -euo pipefail
: "${IMAGE_ROOT:?Required registry/repository}"
: "${RELEASE_TAG:?Required immutable version tag}"
: "${DIGEST_DIRECTORY:?Required downloaded job artifacts}"
[[ "$RELEASE_TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || exit 2

for component in app web; do
    image="$IMAGE_ROOT"
    [ "$component" != web ] || image="$IMAGE_ROOT-nginx"
    refs=()
    digests=()
    for arch in amd64 arm64; do
        reference="$(jq -er --arg arch "$arch" --arg component "$component" \
            'select(.arch == $arch) | .[$component]' "$DIGEST_DIRECTORY/$arch.json")"
        digest="${reference##*@}"
        [[ "$digest" =~ ^sha256:[a-f0-9]{64}$ ]] && [ "$reference" = "$image@$digest" ] || exit 2
        refs+=("$reference")
        digests+=("$digest")
    done
    target="$image:$RELEASE_TAG"
    # Prepare and validate the complete index before publishing its version tag.
    candidate="$DIGEST_DIRECTORY/$component-candidate.json"
    docker buildx imagetools create --dry-run "${refs[@]}" > "$candidate"
    node scripts/verify-image-index.mjs "$candidate" "${digests[@]}"
    published="$DIGEST_DIRECTORY/$component-published.json"
    if docker buildx imagetools inspect "$target" --raw > "$published" 2> "$DIGEST_DIRECTORY/$component-inspect.err"; then
        # Retrying publication is allowed only if the tag already has these digests.
        node scripts/verify-image-index.mjs "$published" "${digests[@]}"
    else
        # Authentication, transport or server errors must not be mistaken for an absent tag.
        grep -Eiq '(manifest unknown|not found)' "$DIGEST_DIRECTORY/$component-inspect.err" || {
            cat "$DIGEST_DIRECTORY/$component-inspect.err" >&2; exit 1;
        }
        docker buildx imagetools create --tag "$target" "${refs[@]}"
        docker buildx imagetools inspect "$target" --raw > "$published"
        node scripts/verify-image-index.mjs "$published" "${digests[@]}"
    fi
    echo "Published $target (linux/amd64, linux/arm64)" >> "$GITHUB_STEP_SUMMARY"
    docker buildx imagetools inspect "$target" >> "$GITHUB_STEP_SUMMARY"
done
