# plg_fields_tagselect

Joomla Custom Fields plugin that adds a `tagselect` field type for selecting **existing** Joomla Tags.

## Repository layout

- `src/` contains the installable extension files.
- `build/` is reserved for generated package output.

## Package (do this when ready)

Create `build/plg_fields_tagselect.zip` so that the **contents of `src/` are at the zip root**.

Batch helper:

```bat
build.bat
```

PowerShell example:

```powershell
Compress-Archive -Path src\* -DestinationPath build\plg_fields_tagselect.zip -Force
```

If Joomla shows `Unable to detect manifest file`, confirm:

1. You are uploading `build/plg_fields_tagselect.zip` (not a zip of the whole repository).
2. `tagselect.xml` is at the zip root (the `build.bat` script now validates this automatically).
3. You rebuilt the package after any source changes.

## Install in Joomla 6

1. Create the zip package as shown above.
2. In Joomla Administrator, go to `System -> Install -> Extensions`.
3. Upload `build/plg_fields_tagselect.zip`.
4. Ensure plugin `Fields - Tag Select` is enabled.

## Test checklist

1. In Joomla Administrator, create a few Tags first (`Content -> Tags`).
2. Go to `Content -> Fields` and create a new field with type `tagselect`.
3. Keep `Allow multiple tags` enabled (default) and save.
4. Edit an Article (`Content -> Articles`), assign multiple existing tags in the new field, and save.
5. Reopen the article and confirm the selected values persist.
6. In the same field, type a new tag name and press Enter.
7. Confirm no new tag is created (creation is denied). If your setup still allows it in AJAX mode, set the field parameter `Selector mode` to `Nested (no creation)` and retest.
