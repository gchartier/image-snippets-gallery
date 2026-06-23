# Releasing

How to cut a new release of **ImageSnippets Gallery** and publish an installable
zip to GitHub Releases. Replace `X.Y.Z` with the new version throughout.

## 1. Bump the version

The version string lives in **four** places — keep them in sync:

| File | What to change |
| --- | --- |
| `image-snippets-gallery.php` | the `* Version:` header **and** `define( 'ISG_VERSION', '…' )` |
| `package.json` | `"version"` |
| `src/block.json` | `"version"` |
| `readme.txt` | `Stable tag:` — and add a `== Changelog ==` entry (plus an `== Upgrade Notice ==` line) |

## 2. Rebuild

`build/` is gitignored but **ships in the zip**, and it carries its own copy of
`block.json`, so you must rebuild after a version bump:

```bash
npm install   # first time / after dependency changes
npm run build
grep '"version"' build/block.json   # should now read X.Y.Z
```

## 3. Commit, tag, push

```bash
git add image-snippets-gallery.php package.json src/block.json readme.txt
git commit -m "vX.Y.Z: <summary>"
git push origin main

git tag vX.Y.Z
git push origin vX.Y.Z
```

> SSH on some machines hangs waiting for a passphrase. If `git push` stalls,
> prefix it with `GIT_SSH_COMMAND='ssh -o BatchMode=yes' ` so it fails fast.

## 4. Build the plugin zip

A WordPress plugin zip must contain a **single top-level folder named after the
plugin slug** (`image-snippets-gallery/`) holding only the runtime files —
**no** `node_modules/`, `src/`, `package*.json`, or dotfiles.

This machine has no `zip` binary, so build it with Python's `zipfile`:

```bash
VERSION=X.Y.Z
STAGE="$(mktemp -d)/image-snippets-gallery"
mkdir -p "$STAGE"
cp image-snippets-gallery.php readme.txt LICENSE README.md "$STAGE/"
cp -r includes build "$STAGE/"
find "$STAGE" -name '.DS_Store' -delete

python3 - "$STAGE" "$VERSION" <<'PY'
import os, shutil, sys
stage, version = sys.argv[1], sys.argv[2]
root = os.path.dirname(stage)
out  = os.path.join(os.getcwd(), f"image-snippets-gallery-{version}")
print("created:", shutil.make_archive(out, "zip", root_dir=root, base_dir="image-snippets-gallery"))
PY
```

(If you do have `zip`: `cd "$(dirname "$STAGE")" && zip -rq image-snippets-gallery-$VERSION.zip image-snippets-gallery`.)

Sanity-check the structure — everything should sit under `image-snippets-gallery/`:

```bash
python3 -c "import zipfile,sys; [print(n) for n in sorted(zipfile.ZipFile(sys.argv[1]).namelist())]" image-snippets-gallery-$VERSION.zip
```

## 5. Publish the release

Create the release and attach the zip in one step:

```bash
gh release create vX.Y.Z image-snippets-gallery-X.Y.Z.zip \
  --title "vX.Y.Z" \
  --notes "<release notes — usually the readme.txt changelog entry>"
```

Users then install via **Plugins → Add New → Upload Plugin** in wp-admin.

## Fixing a release after the fact

If you already created the release/tag and just need to attach or replace the zip:

```bash
gh release upload vX.Y.Z image-snippets-gallery-X.Y.Z.zip --clobber
```

If the tag points at the wrong commit (e.g. you cut it before a version bump),
move it onto the right commit — safe only if nobody has downloaded the assets yet:

```bash
git tag -f vX.Y.Z <commit>
git push -f origin vX.Y.Z   # the GitHub release follows the tag
```
