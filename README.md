# plg_fields_tagselect

Joomla Custom Fields plugin that adds a `tagselect` field type for selecting **existing** Joomla Tags.

## Repository Layout

- `plugin/` contains the installable Joomla plugin source.
- `build/build.ps1` creates the installable ZIP from the contents of `plugin/`.
- `build/output/` contains generated ZIP artifacts.
- `build/stage/` is a temporary staging area used during packaging.
- `build.bat` is a Windows shortcut for the PowerShell build script.

## Build

Run either:

```bat
build.bat
```

or:

```powershell
powershell -ExecutionPolicy Bypass -File .\build\build.ps1
```

The generated package is written to:

```text
build/output/plg_fields_tagselect-vX.Y.Z.zip
```

The version is read from `plugin/tagselect.xml`.

The ZIP is created from the contents of `plugin/` at the archive root, so the result stays directly Joomla-installable without an extra source folder inside the archive.

If Joomla shows `Unable to detect manifest file`, confirm:

1. You are uploading the ZIP from `build/output/` and not a zip of the whole repository.
2. `tagselect.xml` is at the zip root.
3. You rebuilt the package after any source changes.

## GitHub Releases

This repository is set up to publish the installable ZIP automatically through GitHub Actions when a version tag is pushed.

Release flow:

1. Update the version in `plugin/tagselect.xml`.
2. Commit and push your changes.
3. Create and push a tag that matches the manifest version, prefixed with `v`:

```powershell
git tag v1.0.4
git push origin v1.0.4
```

4. GitHub Actions will:
   - validate that the tag matches the manifest version
   - run `build/build.ps1`
   - create or update a GitHub Release
   - upload the generated ZIP from `build/output/`

Important:

- The tag must match the manifest version exactly. Example: tag `v1.0.4` must match manifest version `1.0.4`.
- Generated ZIP artifacts are intentionally kept out of git history.

## Install in Joomla 6

1. Build or download the installable ZIP.
2. In Joomla Administrator, go to `System -> Install -> Extensions`.
3. Upload the ZIP from `build/output/`.
4. Ensure plugin `Fields - Tag Select` is enabled.

## Manual install for development

Copy the contents of `plugin/` into:

```text
plugins/fields/tagselect/
```

Then install or discover the plugin through Joomla as needed.

## Test checklist

1. In Joomla Administrator, create a few Tags first (`Content -> Tags`).
2. Go to `Content -> Fields` and create a new field with type `tagselect`.
3. Keep `Allow multiple tags` enabled (default) and save.
4. Edit an Article (`Content -> Articles`), assign multiple existing tags in the new field, and save.
5. Reopen the article and confirm the selected values persist.
6. In the same field, type a new tag name and press Enter.
7. Confirm no new tag is created (creation is denied). If your setup still allows it in AJAX mode, set the field parameter `Selector mode` to `Nested (no creation)` and retest.
