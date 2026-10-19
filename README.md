# JCORE Kirjasto

The JCORE media library plugin. Each feature is a module of its own:

- **Usage** – where every attachment is used: a "Used in" column, a used/not used filter and a "Used in" list in the attachment details, backed by an index of what each post, term, settings page and theme file refers to.
- **Folders** – a folder tree beside the Media screen and inside every media modal. Files are dragged onto folders, uploads go into the open folder, and folders can be imported from FileBird, HappyFiles and Enhanced Media Library.
- **Replace** – a new file in place of an attachment's file, of the same type. The attachment keeps its ID and the file its name, so the new file shows everywhere the old one was used.

This file covers the repository and the development workflow. What the plugin does, and how to use it, is in [readme.txt](readme.txt).

## Requirements

- PHP 8.2+
- WordPress 6.7+
- Node 22+ and [pnpm](https://pnpm.io/)
- [Composer](https://getcomposer.org/) and [WP-CLI](https://wp-cli.org/) (WP-CLI is only needed for the translation targets)

## Getting started

```sh
pnpm install
composer install
pnpm build
```

Then run a throwaway WordPress with the plugin mounted:

```sh
pnpm playground
```

That serves [WordPress Playground](https://wordpress.org/playground/) on <http://localhost:8883> from `.wp/blueprint.json`, logged in as `admin` / `password` and landing on the Media screen. Plugin Check is installed alongside it.

## Scripts

| Command | What it does |
| --- | --- |
| `pnpm build` | Build the admin assets into `build/`. |
| `pnpm start` | Same, in watch mode. |
| `pnpm check` | Everything CI lints: ESLint, Stylelint and PHPCS. |
| `pnpm lint:js` / `lint:css` / `lint:php` | One linter at a time. |
| `pnpm format` | Format `src/` with `wp-scripts format`. |
| `composer lint:fix` | Fix what PHPCBF can fix. |
| `pnpm i18n` | Regenerate the POT, the MO files and the JS translation JSON. |
| `pnpm playground` | Serve the plugin in WordPress Playground. |

A `Makefile` wraps the same scripts (`make ci` is the entry point the shared publish workflow calls); it is a shim, not a second build system.

## Layout

```
jcore-kirjasto.php                     Plugin header, constants, autoloader, bootstrap
uninstall.php                          Drops the table and options on delete
includes/
  class-plugin.php                     Loads the updater and the features
  class-assets.php                     Enqueues a build entry with its translations
  class-database.php                   Usage table name, schema and upgrades
  rest/class-controller.php            Shared namespace and permission check
  usage/
    class-feature.php                  Wires the usage feature to its hooks
    class-scanner.php                  Finds the attachments a post, term, the settings or the theme use
    class-theme-files.php              Lists the active theme's files and notices when they change
    class-indexer.php                  Writes references; re-indexes what changes, on shutdown
    class-rebuild.php                  Full rebuilds in batches: WP-Cron, REST and WP-CLI
    class-report.php                   Reads the index for display, per user
    admin/class-page.php               Media > Usage page and its assets
    admin/class-media-library.php      "Used in" column, field and the used/not used filter
    rest/class-index-controller.php    Index status and rebuild
    cli/class-command.php              wp kirjasto usage rebuild | list
  folders/
    class-feature.php                  Wires the folders feature to its hooks
    class-folders.php                  The folder taxonomy and every change to it
    class-importer.php                 Imports from FileBird, HappyFiles and Enhanced Media Library
    admin/class-media-library.php      Query filters, the folder field, uploads, bulk action, assets
    rest/class-folders-controller.php  Folders, moving files and imports
    cli/class-command.php              wp kirjasto folders list | import
  replace/
    class-feature.php                  Wires the replace feature to its hooks
    class-replacer.php                 Puts a new file in place of an attachment's file
    admin/class-media-library.php      "Replace file" field and row action, assets
    rest/class-replace-controller.php  Takes the upload
    cli/class-command.php              wp kirjasto replace
views/usage/page.php                   Mount point for the Media > Usage app
src/
  usage-page/                          The Media > Usage app (@wordpress/scripts)
  usage-media/                         Grid mode usage filter and the "Used in" list styles
  folders/                             The folder list and its media library integration
  replace/                             The "Replace file" button
languages/                             .po sources; .pot, .mo and .json are generated
```

Classes autoload from the `Jcore\Kirjasto` namespace: `Jcore\Kirjasto\Folders\Admin\Media_Library` lives in `includes/folders/admin/class-media-library.php`.

A feature is a class with a static `register()`, listed in `Plugin::features()`. The `jcore_kirjasto_features` filter turns features off on a site that does not need them. New media library functionality goes into a feature of its own the same way.

## How the index works

`{prefix}jcore_kirjasto_usage` holds one row per reference: attachment, object (`post`, `term`, `option` or `theme`), context (`content`, `featured`, `field`, `meta`, `site_icon`, `custom_logo`, `option`, `file`) and source (the ACF field key, meta key, option name or theme file, e.g. `kielo/patterns/hero.php`).

- **Incrementally.** `Indexer` queues a post on `wp_after_insert_post` and on any meta change, a term on term meta changes, and the settings on changes to the options they are read from. The queue is indexed once on `shutdown`. Deleting a post or term drops its rows; deleting an attachment drops the rows pointing at it.
- **Theme files.** Deploys change theme files without a hook, so the media library, the attachment edit screen and the Usage screen compare a fingerprint of the files' sizes and modification times with the last scan, and re-scan the theme before rendering if it differs. Switching or updating a theme re-scans it too.
- **In full.** `Rebuild` walks posts, then terms with meta, 50 at a time, then the theme and the settings. The cursor is stored in the `jcore_kirjasto_rebuild` option, so WP-Cron, the Usage screen and WP-CLI all advance the same rebuild. When it finishes, rows older than its start belong to objects that are gone and are deleted.
- **Automatically.** A rebuild starts on first install, and whenever `Scanner::VERSION` changes. Bump it when the scanning rules change, so the new rules reach content nobody edits.

The scanner is generous: anything that looks like an attachment ID is collected, and `Indexer` keeps only the IDs that really are attachments, in one query per object.

## How folders work

Folders are terms of `jcore_kirjasto_folder`, a hidden, hierarchical attachment taxonomy. An attachment is in one folder at most; one without a term has no folder. Being terms, folders come along in WXR exports and work in any `tax_query`.

- **Filtering.** The library collection gets a `jcore_kirjasto_folder` prop, a folder ID or 0 for "no folder", which `query-attachments` passes on to PHP. List mode reads the same name from the URL. Grid mode writes it to the URL as well, so a reload or switching modes keeps the folder; core's grid router drops it once a file is opened, as it does with core's own filters.
- **Moving.** Files move by dragging them from the grid, the modal or list rows onto a folder, with the "Folder" field of the attachment details, or with the "Move to folder" bulk action. Dragging the selected file drags the whole selection. Every route that changes folders answers with the whole tree, so every folder list on the page redraws from it.
- **Uploads.** The uploader sends the folder the library shows, and the new attachment is put into it. Core shows new uploads only in queries it can match them against; a folder query is made one of those.
- **Deleting** a folder moves its files and subfolders into its parent.
- **Polylang.** With media translation on, the translations of an attachment are separate attachments. They move together, new translations inherit the folder, and counts follow the language the media library is filtered by.
- **Importing** reads FileBird's tables or the HappyFiles and Enhanced Media Library taxonomies straight from the database, so those plugins need not be active, and leaves them untouched. Imported folders remember where they came from, and files already in a folder keep it, so an import can run again. The folder list offers it on the Media screen until everything has been imported.

The "Folder" field is rendered with the current folder only; the script fills in the tree. It is named `jcore_kirjasto_folder_id`, not after the taxonomy: core saves an attachment field named after a taxonomy itself, reading the value as term names.

## How replacing works

The attachment stays as it is – ID, title, alt text, folder, every reference to it – and only its file changes. The new file must have the attachment's MIME type, and takes the old file's name, so links to the file itself keep working.

1. The upload goes through `wp_handle_upload()` like any other, so size limits, allowed types and upload filters apply. `Replacer` then checks its real type against the attachment's.
2. The new file is moved over the original upload (the file a `-scaled` copy was made from, if any). The old resized copies, edit backups and the `.webp`/`.avif` copies optimisers keep beside each file are deleted.
3. The metadata and resized copies are generated again, which runs image optimisers too. Attachments sharing the file – Polylang's media translations – get the new metadata as well.
4. A new image with other proportions gets copies with other names, `photo-1024x576.jpg` instead of `photo-1024x767.jpg`. Post content linking to an old copy is rewritten to link to the new copy of the same size, or to the full image if the new one is too small for that size. The content is written directly, without a revision. Links in post meta, theme files or other sites are not rewritten.
5. In the media modal and grid mode the attachment model takes the answer, and the views showing it redraw. The list and the edit screen reload.

The file keeps its address, and image responses are commonly cached for a long time (and `Vary: Accept` keeps a refetch from replacing the cached copy), so the browser would keep showing the old file even after an ordinary reload. The admin therefore shows a replaced attachment's images with `?jcore-kirjasto-v={time of replacement}`: the media views add it to the template data only, so the model keeps the real addresses that are inserted into content and copied with "Copy URL", and PHP adds it to `wp_get_attachment_image_src()` and `srcset` on admin pages loaded with GET, outside AJAX. The front end and URLs in content are left as they are.

## REST API

Both screens talk to `jcore-kirjasto/v1`.

| Route | Methods | Who |
| --- | --- | --- |
| `/index` | GET – status, progress and totals | `manage_options` |
| `/index/rebuild` | POST – start a rebuild from the beginning | `manage_options` |
| `/index/step` | POST – index the next batch | `manage_options` |
| `/folders` | GET – the tree with counts; POST – create a folder | `upload_files`; creating as below |
| `/folders/{id}` | PATCH – rename or move; DELETE – delete | `jcore_kirjasto_folders_capability` |
| `/folders/move` | POST – move attachments into a folder, or 0 for none | `upload_files`, and editing each file |
| `/folders/import` | GET – sources with folders; POST – run an import step | `jcore_kirjasto_folders_capability` |
| `/replace/{id}` | POST – replace the file with the uploaded `file` | `upload_files`, and editing the file |

## WP-CLI

```sh
wp kirjasto usage rebuild            # Rebuild the usage index
wp kirjasto usage list 123           # Where attachment 123 is used
wp kirjasto folders list             # The folder tree with file counts
wp kirjasto folders import           # Which plugins have folders to import
wp kirjasto folders import filebird  # Import them
wp kirjasto replace 123 ~/new.jpg    # Replace the file of attachment 123
```

## Filters

- `jcore_kirjasto_features` – the features to load. Remove `usage`, `folders` or `replace` to turn one off.
- `jcore_kirjasto_folders_capability` – who may create, rename, move and delete folders. Default `upload_files`; moving a file needs the right to edit it.
- `jcore_kirjasto_post_types` – the post types that are indexed.
- `jcore_kirjasto_block_attributes` – block attributes holding attachment IDs, by block name.
- `jcore_kirjasto_field_types` – ACF field types whose value is attachment IDs.
- `jcore_kirjasto_meta_keys` – plain meta keys holding attachment IDs, per object type; `jcore_kirjasto_meta_labels` names them in the UI.
- `jcore_kirjasto_option_keys` – array options holding attachment IDs, with a regex for the keys.
- `jcore_kirjasto_options_prefixes` – ACF options page prefixes, besides the ones ACF reports.
- `jcore_kirjasto_theme_files` – the theme files that are scanned, keyed by absolute path.
- `jcore_kirjasto_post_references`, `jcore_kirjasto_term_references`, `jcore_kirjasto_settings_references`, `jcore_kirjasto_theme_references` – the final reference lists.
- `jcore_kirjasto_replace_derived_files` – copies kept beside a file that are deleted when it is replaced. Default `{file}.webp` and `{file}.avif`.
- `jcore_kirjasto_replaced` (action) – after a file has been replaced, with the attachment ID, its old metadata, the rewritten URLs and the attachments sharing the file.

## Releasing

Pushing to `main` runs `.github/workflows/release.yml`:

1. **check** – lint, build, then run Plugin Check against the tree minus `.distignore`. Also runs on pull requests.
2. **release** – [foonver](https://github.com/foonly/foonver) reads the conventional commits since the last tag, bumps the version, syncs it into `jcore-kirjasto.php`, `readme.txt` and `package.json`, writes the `== Changelog ==` section of `readme.txt` and pushes the tag. Its configuration lives in `.foonver.toml`.
3. **publish** – the reusable workflow in [jcore-update](https://github.com/JCO-Digital/jcore-update) builds the zip, attaches it to a GitHub release, registers the version with `update.jcore.fi`, pushes to the dist repository and posts to Slack.

Nothing here is published to wordpress.org. Commit messages must follow [Conventional Commits](https://www.conventionalcommits.org/), or foonver will not know what to bump.

`.distignore` is the single list of what does not ship — it drives both the packaged zip and the dist repository.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
