# BeTheme Content Migrator — WordPress Plugin
Convert BeTheme builder content stored in post meta into clean, readable post_content for standard WordPress usage.
Not affiliated with BeTheme.
## What this plugin does
- Adds a Tools page in wp-admin to run a one-click migration.
- Finds posts/pages (and selected custom post types) that have BeTheme builder data saved in post meta under the mfn-page-items key.
- Decodes the stored data and extracts:
    - Rich text/HTML from content blocks.
    - Image URLs and converts them into normalized (wrapped in `<a>`) tags.

- Writes the resulting HTML into the native post_content field.
- Provides a post-run summary (processed, updated, skipped, errors).

## What this plugin does NOT do
- It does not migrate 1:1 BeTheme layouts, styling, or advanced components (e.g., sliders, buttons, grids, animations).
- It does not guarantee perfect visual parity; it focuses on extracting core readable content (text and images).
- It does not alter themes, templates, or page builder settings.
- It does not remove BeTheme data from post meta; it only populates post_content.
- It does not handle shortcodes or custom modules beyond basic content and image extraction.

## Requirements
- WordPress 5.8+ (recommended newer versions)
- PHP 8.0+
- Admin access (manage_options capability) to run the migration

## Installation

1. Download the plugin from [GitHub](https://github.com/kylendowns/betheme-migrator/archive/refs/heads/main.zip).
2. In WordPress Admin, go to Plugins > Add New > Upload Plugin, upload the zip, then Activate.

## Usage
1. Back up your database. _**This operation updates post_content at scale._
2. In WordPress Admin, go to Tools > BeTheme Content Migrator.
3. Select which post types to process.
4. Choose whether to overwrite existing post_content (recommended if you want to replace any existing content with extracted content).
5. Click Run Migration.
6. Review the summary and any error samples shown.

Notes:
- Only posts that have the mfn-page-items meta will be processed.
- If “Overwrite existing post_content” is unchecked, posts with non-empty content will be skipped.

## Safe operation tips
- Always test on a staging site first.
- Back up before running.
- Run in batches if your site is very large (you can limit selected post types and re-run as needed).
- After migration, review key pages to confirm results.

## Frequently asked questions
- Will this break BeTheme?
  - No. It does not remove or modify the original BeTheme meta data; it populates post_content. You can still use BeTheme if desired.
- Will the migrated content look identical?
  - Not necessarily. This plugin focuses on extracting readable text and images, not reproducing layout/styling.
- Can I undo the migration?
  - Not automatically. Restore from a backup to revert post_content changes.
- Does it migrate custom fields or builder-specific widgets?
  - No. It targets main content and images.

## Support and contributions
- This plugin is provided as-is. For issues or ideas, open an issue in your project repository or contact the author if contact info is provided within the plugin metadata.

## License
- GPL-3.0+