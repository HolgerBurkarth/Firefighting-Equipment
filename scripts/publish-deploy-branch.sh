#!/usr/bin/env bash
# Legt Code plus Frontend-Build im Branch "deploy" ab (GitHub Actions, Job "deploy").
#
# Aufruf im Repository (Arbeitsordner = Projektordner):
#   bash scripts/publish-deploy-branch.sh <main-commit> <dist-ordner> [<remote>]
#
# Der neue Commit enthält den Baum von <main-commit> und zusätzlich <dist-ordner> als frontend/dist
# (auf main in .gitignore). Eltern: zuerst der bisherige Stand von deploy (falls vorhanden), dann
# <main-commit>. So ist jeder deploy-Stand ein Nachfahre des vorigen und des zugehörigen
# main-Stands; der Server kann immer per "git merge --ff-only origin/deploy" nachziehen, auch beim
# Umstieg von main. Gepusht wird ohne --force: hat jemand deploy inzwischen geändert, bricht der
# Push ab. Ist der Baum gleich dem bisherigen deploy-Stand, entsteht kein neuer Commit.
#
# Weder Arbeitsordner noch Index werden verändert (eigener temporärer Index).

set -euo pipefail

BRANCH="deploy"
DIST_PATH="frontend/dist"
PLACEHOLDER="/__FF_BASE__/"

fail() {
    echo "FEHLER: $*" >&2
    exit 1
}

[ "$#" -ge 2 ] && [ "$#" -le 3 ] || {
    echo "Aufruf: bash scripts/publish-deploy-branch.sh <main-commit> <dist-ordner> [<remote>]" >&2
    exit 2
}

MAIN_COMMIT="$(git rev-parse --verify --quiet "$1^{commit}")" || fail "Commit '$1' nicht gefunden."
DIST_DIR="${2%/}"
REMOTE="${3:-origin}"

[ -f "$DIST_DIR/index.html" ] || fail "$DIST_DIR/index.html fehlt – Frontend nicht gebaut?"
[ "$(grep -o -F "$PLACEHOLDER" "$DIST_DIR/index.html" | wc -l)" -eq 1 ] \
    || fail "$DIST_DIR/index.html enthält den Platzhalter $PLACEHOLDER nicht genau einmal."
[ -f "$DIST_DIR/sw.js" ] || fail "$DIST_DIR/sw.js fehlt."
if git ls-tree -d --name-only "$MAIN_COMMIT" -- "$DIST_PATH" | grep -q .; then
    fail "$DIST_PATH ist in $MAIN_COMMIT versioniert; der Build gehört nur in den Branch $BRANCH."
fi

# Bisheriger Stand von deploy (fehlt er, ist dies der erste deploy-Commit).
PARENT=""
if git ls-remote --exit-code --heads "$REMOTE" "$BRANCH" >/dev/null; then
    git fetch --quiet --no-tags "$REMOTE" "+refs/heads/$BRANCH:refs/remotes/$REMOTE/$BRANCH"
    PARENT="$(git rev-parse "refs/remotes/$REMOTE/$BRANCH")"
fi

INDEX_FILE="$(mktemp)"
trap 'rm -f "$INDEX_FILE"' EXIT
export GIT_INDEX_FILE="$INDEX_FILE"

git read-tree "$MAIN_COMMIT"
while IFS= read -r -d '' file; do
    blob="$(git hash-object -w -- "$file")"
    git update-index --add --cacheinfo "100644,$blob,$DIST_PATH/${file#"$DIST_DIR"/}"
done < <(find "$DIST_DIR" -type f -print0 | LC_ALL=C sort -z)
TREE="$(git write-tree)"

if [ -n "$PARENT" ] && [ "$(git rev-parse "$PARENT^{tree}")" = "$TREE" ]; then
    echo "Branch $BRANCH ist bereits auf diesem Stand ($PARENT)."
    exit 0
fi

PARENT_ARGS=()
if [ -n "$PARENT" ]; then
    PARENT_ARGS+=(-p "$PARENT")
fi
PARENT_ARGS+=(-p "$MAIN_COMMIT")

SUBJECT="$(git log -1 --format=%s "$MAIN_COMMIT")"
COMMIT="$(git commit-tree "$TREE" "${PARENT_ARGS[@]}" \
    -m "Deploy $(git rev-parse --short "$MAIN_COMMIT"): $SUBJECT" \
    -m "Code von main $MAIN_COMMIT plus Frontend-Build in $DIST_PATH.")"

git push --quiet "$REMOTE" "$COMMIT:refs/heads/$BRANCH"
echo "Branch $BRANCH: $COMMIT"
