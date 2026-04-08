# plg_fields_tagselect

Joomla Custom Fields plugin that adds a `tagselect` field type for selecting Joomla Tags, with configurable branch scoping, optional native article-tag storage and controlled tag creation.

## Repository Layout

- `plugin/` contains the installable Joomla plugin source.
- `build/build.ps1` creates the installable ZIP from the contents of `plugin/`.
- `build/output/` contains generated ZIP artifacts.
- `build/stage/` is a temporary staging area used during packaging.
- `build.bat` is a Windows shortcut for the PowerShell build script.

## Feature Overview

`tagselect` extends Joomla's core `TagField` and is intended for taxonomy-backed editorial workflows where editors should only see the part of the tag tree that is relevant to the field.

Supported field parameters:

- `multiple`: allow one or many tags.
- `field_type`: store an independent custom-field value or manage native article tags.
- `mode`: `ajax` or `nested`.
- `tag_scope_mode`: show all tags, only selected branches, or all tags except selected branches.
- `scope_root_ids`: one-or-many root tags used by the current scope mode.
- `include_descendants`: in Independent Tag Mode, include the whole selected subtree or only direct children.
- `leaf_only`: allow only terminal tags inside the allowed subtree.
- `allow_tag_creation`: optionally allow editors to create tags from the field.
- `allow_root_level_creation`: optionally allow plain input to create top-level tags when no explicit parent path is given.

Behavior notes:

- If `field_type = independent`, the field stores its own custom-field value, as before.
- If `field_type = native_article_tags`, the field reads from and writes to the article's native `tags`.
- If `tag_scope_mode = all`, the field can use the full tag tree.
- If `tag_scope_mode = include`, editors only see tags from the union of the selected branches.
- If `tag_scope_mode = exclude`, editors see all tags except the selected branches.
- `exclude` scope always removes whole branches.
- If `tag_scope_mode` is `include` or `exclude` but `scope_root_ids` is empty, the field fails safe back to `all`.
- In `Native Article Tag Handler`, scoped branches always mean full subtrees.
- In `Independent Tag Mode`, `include_descendants = No` limits `include` scope to the direct children of the chosen roots.
- If `leaf_only = Yes`, only tags without children inside the permitted subtree are selectable.
- If `allow_tag_creation = Yes`, explicit path input like `Parent/Child` first tries to resolve an existing allowed tag by path, and otherwise creates `Child` under an existing resolvable `Parent`.
- If there is exactly one `include` scope root and the input has no explicit parent path, new tags are created under that configured root.
- If there are zero or many scope roots, or the field uses `exclude`/`all` scope, plain input only creates a top-level tag when `allow_root_level_creation = Yes`.
- If `allow_root_level_creation = Yes` in a restricted field, top-level tags also become selectable in that field.
- In `Native Article Tag Handler`, save updates only the managed native-tag subset and leaves every other native article tag untouched.
- Missing intermediate parents are not auto-created. Unresolvable paths fail safe instead of creating a literal tag title with `/`.
- If a stored value later falls outside the allowed subtree because the config or tree changed, the field keeps the value visible and safe instead of breaking the form.

Implementation note:

- When subtree or leaf restrictions are active, the field disables the core unrestricted remote tag search and uses server-filtered options instead. The editor still gets a searchable selector in `ajax` mode, but only over the allowed tags.

## Usage Examples

### Example 1: Source provider taxonomy

- Field name: `source_provider`
- `field_type`: `independent`
- `tag_scope_mode`: `include`
- `scope_root_ids`: tag `Source`
- `include_descendants`: `Yes`
- `leaf_only`: `Yes`
- `allow_tag_creation`: `No`
- `allow_root_level_creation`: `No`

Result:

- Editors only see provider tags under `Source`.
- Only terminal provider tags can be selected.

### Example 2: Worksheet type branch

- Field name: `worksheet_type`
- `field_type`: `independent`
- `tag_scope_mode`: `include`
- `scope_root_ids`: tag `Worksheet Type`
- `include_descendants`: `No`
- `leaf_only`: `No`
- `allow_tag_creation`: `Yes`
- `allow_root_level_creation`: `No`

Result:

- Editors only see the direct children of `Worksheet Type`.
- New tags typed into the field are created under that root.

### Example 3: Shared taxonomy field across two branches

- Field name: `audience_focus`
- `field_type`: `independent`
- `tag_scope_mode`: `include`
- `scope_root_ids`: tags `Primary Audience`, `Secondary Audience`
- `include_descendants`: `Yes`
- `leaf_only`: `Yes`
- `allow_tag_creation`: `Yes`
- `allow_root_level_creation`: `Yes`

Result:

- Editors see the union of both branches in a single selector.
- Only leaf tags from those branches remain selectable.
- Explicit path input can create tags under a chosen existing parent from either branch.
- Plain input without a path can create a top-level tag because root-level creation is enabled.

### Example 4: Native branch-backed article tags

- Field name: `worksheet_type`
- `field_type`: `native_article_tags`
- `tag_scope_mode`: `include`
- `scope_root_ids`: tag `Worksheet Type`
- `leaf_only`: `No`
- `allow_tag_creation`: `Yes`
- `allow_root_level_creation`: `No`

Result:

- The field loads and saves only the article's native tags inside the `Worksheet Type` branch.
- Any other native article tags stay untouched.
- New plain input is created under `Worksheet Type`, while explicit `Parent/Child` input can target a deeper parent inside that managed branch.

### Example 5: Remaining native tags field

- Field name: `other_tags`
- `field_type`: `native_article_tags`
- `tag_scope_mode`: `exclude`
- `scope_root_ids`: tags `Grade`, `Theme`, `Period`, `Series`
- `leaf_only`: `No`
- `allow_tag_creation`: `Yes`
- `allow_root_level_creation`: `Yes`

Result:

- The field manages every native article tag except those excluded taxonomy branches.
- It works well as a companion field for "all remaining tags".
- Plain input can create top-level tags, while excluded taxonomy branches remain hidden from this selector.

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
Each build run clears previous files from `build/stage/` and `build/output/` before packaging.

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
git tag v1.5.1
git push origin v1.5.1
```

4. GitHub Actions will:
   - validate that the tag matches the manifest version
   - run `build/build.ps1`
   - create or update a GitHub Release
   - upload the generated ZIP from `build/output/`

Important:

- The tag must match the manifest version exactly. Example: tag `v1.5.1` must match manifest version `1.5.1`.
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

## Test Checklist

1. Create a root tag like `Source` and a few nested child tags under it.
2. Create a custom field with type `tagselect`.
3. Set `field_type = independent`, `tag_scope_mode = include`, `scope_root_ids = Source`, `include_descendants = Yes`, `leaf_only = Yes`.
4. Edit an Article and confirm the selector only shows tags from that subtree.
5. Confirm non-leaf tags inside the subtree are excluded when `leaf_only = Yes`.
6. Switch `include_descendants = No` and confirm only the direct children of the scope roots remain selectable.
7. Enable `allow_tag_creation = Yes`, type a new tag and save.
8. Confirm the new tag is created under the configured include root and becomes selectable on reopen.
9. Change the field config so that an already stored tag falls outside the allowed scope and confirm the form still loads safely.
10. Configure two different `scope_root_ids` in `include` mode and confirm the selector shows the combined allowed branches.
11. With two include roots configured, type `Existing Parent/New Child` and confirm only `New Child` is created under `Existing Parent`.
12. With `allow_root_level_creation = No`, type plain input without a parent path in a multi-root field and confirm the field does nothing.
13. With `allow_root_level_creation = Yes`, type plain input without a parent path and confirm a top-level tag is created and remains selectable.
14. Type an unresolvable path like `Missing Parent/New Child` and confirm the field does not create a literal tag containing `/`.
15. Set `field_type = native_article_tags` with `tag_scope_mode = include`, save an article, and confirm the matching native article tags are updated while unrelated native tags remain untouched.
16. Set `field_type = native_article_tags` with `tag_scope_mode = exclude`, save an article, and confirm the field manages the inverse "other tags" subset.
