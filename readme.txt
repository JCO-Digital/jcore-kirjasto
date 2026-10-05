=== JCORE Kirjasto ===
Contributors: jcodigital
Tags: media, media library, folders, attachments, unused media
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Media library folders, and where every image and file is used.

== Description ==

JCORE Kirjasto is the J&Co Digital media library plugin. It organises the media library into folders, and shows where every attachment is used.

= Folders =

* **A folder tree** beside the Media screen, in grid and list mode, and inside the media library of every media modal, the block editor's included. Folders nest as deep as needed and are listed alphabetically, with the number of files in each.
* **Drag and drop** – drag files from the grid, the modal or the list onto a folder. Dragging one of the selected files moves the whole selection. Folders are dragged onto each other to nest them.
* **Uploads go into the open folder.**
* **The "Folder" field** in the attachment details, and **"Move to folder"** in the list mode bulk actions, move files without dragging.
* **Import** from FileBird, HappyFiles or Enhanced Media Library, active or not. Their data is left as it is.
* **WP-CLI** – `wp kirjasto folders list` and `wp kirjasto folders import`.

Deleting a folder deletes no files: they move into the folder above, as do its subfolders. Anyone who can upload files can manage folders; a filter narrows that down. A file can only be moved by someone who may edit it.

= Usage =

Kirjasto keeps an index of which attachments each post, page, pattern, template, term and settings page uses, and which ones the active theme's files refer to. The media library reads it to show, for every attachment, where it appears, with a link to each place.

* **"Used in" column** – the media library list shows the first places an attachment is used, with a link to the rest.
* **Attachment details** – the media modal and the attachment edit screen list every place, with the post type, the language (with Polylang) and where on the object: content, featured image or the ACF field.
* **Used / not used filter** – in both list and grid mode.
* **Media → Usage** – how many attachments are used and not used, and a button to rebuild the index.
* **WP-CLI** – `wp kirjasto usage rebuild` and `wp kirjasto usage list <attachment-id>`.

= What is found =

* Core media blocks: Image, Gallery, Cover, Media & Text, Video, Audio and File.
* Images in any HTML (`wp-image-123` classes), `[gallery ids="…"]` shortcodes and `attachment_id=` links.
* Links to files in the uploads directory, including resized copies, `-scaled` originals and `.webp` copies, regardless of the domain in the link.
* Media objects (`{ id, url }`) in the attributes of custom blocks.
* Featured images.
* ACF image, file and gallery fields – in posts, terms, options pages and ACF blocks, including inside repeaters, flexible content, groups and clones. Images in WYSIWYG and other text fields are found too.
* The site icon and the site logo.
* Yoast SEO social images, per post and the site-wide defaults.
* The WooCommerce product gallery.
* Files of the active theme and its parent: blocks in patterns, templates and parts, links to uploaded files in templates, styles and scripts, and IDs written into code such as `wp_get_attachment_image( 123 )` in PHP or `get_image( 123 )` in Twig. A theme changed by a deploy is re-scanned the next time the media library is opened.

Usage in revisions, drafts that were auto-saved and trashed posts is not counted.

= Who sees what =

Everyone who can open the media library sees where attachments are used, but only objects they can edit, or that are published, are named. Others are counted as "items you do not have access to". Site settings and the theme are always named.

= External services =

The plugin checks for updates against `https://update.jcore.fi`, operated by J&Co Digital Oy. It sends the plugin slug and the installed version, and the site URL as the request's origin. No visitor or content data is involved.

= Source code =

The admin screens are built with `@wordpress/scripts`. Their readable source is published at https://github.com/JCO-Digital/jcore-kirjasto.

== Installation ==

1. Upload the `jcore-kirjasto` folder to `/wp-content/plugins/`, or install the zip from the Plugins screen.
2. Activate the plugin. The index is built in the background with WP-Cron; on a large site that takes a few minutes.
3. Open **Media → Usage** to follow the progress, or run `wp kirjasto usage rebuild`.
4. Coming from FileBird, HappyFiles or Enhanced Media Library? The folder list on the Media screen offers to import their folders. Deactivate the old plugin afterwards; two folder lists on one screen get in each other's way.

== Frequently Asked Questions ==

= Is "not used" safe to delete? =

It means none of the places listed above refer to the attachment. A file can still be used somewhere the plugin cannot see: by theme code that works the ID or file name out at run time, in a theme that is not active, in another plugin's code, linked from another site or an email, or stored by a plugin in its own tables. Check before deleting in bulk.

= The index is out of date. =

Content saved through WordPress is indexed as it is saved. Content changed directly in the database – an import, a search-replace, a migration – is not; rebuild the index from **Media → Usage** or with `wp kirjasto usage rebuild`.

= Can the usage index learn about my own blocks or fields? =

Yes, with filters: `jcore_kirjasto_block_attributes` for block attributes holding IDs, `jcore_kirjasto_meta_keys` for meta keys, `jcore_kirjasto_field_types` for ACF field types, `jcore_kirjasto_option_keys` for array options, `jcore_kirjasto_theme_files` for the theme files that are scanned, and `jcore_kirjasto_post_references`, `jcore_kirjasto_term_references`, `jcore_kirjasto_settings_references` and `jcore_kirjasto_theme_references` to add references of any kind.

= Can an attachment be in more than one folder? =

No, like a file on a computer. When an import finds a file in several folders, it goes into the first one.

= Why do the folder counts not match the type filter? =

A folder's count is every file in it, whatever the type or date filter shows.

= Who can manage folders? =

Anyone who can upload files. The `jcore_kirjasto_folders_capability` filter changes the capability needed to create, rename, move and delete folders, e.g. to `edit_others_posts` for editors and up.

= Can I turn a feature off? =

Yes: remove `usage` or `folders` with the `jcore_kirjasto_features` filter.

= What happens to my data when I delete the plugin? =

Deleting the plugin drops the usage index and removes its options. Folders are kept, so a reinstall finds them again. Deactivating the plugin leaves everything in place.

== Changelog ==

= 0.1.0 =

* Feature: media library folders and attachment usage
* Initial commit
