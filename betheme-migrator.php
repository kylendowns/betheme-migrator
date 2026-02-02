<?php
/**
 * Plugin Name: BeTheme Content Migrator
 * Description: Adds a Tools page with a button to migrate BeTheme builder content from postmeta (mfn-page-items) into post_content for selected post types. Normalizes content by extracting HTML blocks and images into clean HTML.
 * Version:     0.0.1
 * Author:      Kylen Downs
 * Author URI:  https://sierraplugins.com
 * License:     GPL-2.0+
 * Requires PHP: 8.0
 * Requires at least: 5.8
 * Text Domain: betheme-content-migrator
 */

if (!defined('ABSPATH')) {
    exit;
}

final class BeTheme_Content_Migrator {
    private const NONCE_ACTION = 'betheme_migrate_action';
    private const NONCE_NAME   = 'betheme_migrate_nonce';
    private const OPTION_SLUG  = 'betheme-content-migrator';
    private const META_KEY     = 'mfn-page-items';

    /** @var string[] */
    private array $post_types = ['page', 'post', 'template', 'portfolio', 'product'];

    public function __construct() {
        add_action('admin_menu', [$this, 'register_tools_page']);
        add_action('admin_post_betheme_run_migration', [$this, 'handle_migration_submit']);
    }

    public function register_tools_page(): void {
        $title = __('BeTheme Content Migrator', 'betheme-content-migrator');

        // Adds under Tools menu
        add_management_page(
            $title,
            $title,
            'manage_options',
            self::OPTION_SLUG,
            [$this, 'render_tools_page']
        );
    }

    public function render_tools_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'betheme-content-migrator'));
        }

        $last_result = get_transient('betheme_migrator_last_result'); // Short-lived display of previous run
        if ($last_result) {
            delete_transient('betheme_migrator_last_result');
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('BeTheme Content Migrator', 'betheme-content-migrator'); ?></h1>
            <p><?php echo esc_html__('This migrates BeTheme builder data stored in postmeta (mfn-page-items, base64) into the post_content field for selected post types. It will extract any rich text and normalize image URLs into <img> tags.', 'betheme-content-migrator'); ?></p>

            <?php if (is_array($last_result)) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php echo esc_html__('Migration completed.', 'betheme-content-migrator'); ?></strong></p>
                    <ul>
                        <li><?php echo esc_html(sprintf(__('Processed posts: %d', 'betheme-content-migrator'), (int) ($last_result['processed'] ?? 0))); ?></li>
                        <li><?php echo esc_html(sprintf(__('Updated posts: %d', 'betheme-content-migrator'), (int) ($last_result['updated'] ?? 0))); ?></li>
                        <li><?php echo esc_html(sprintf(__('Normalized from serialized data: %d', 'betheme-content-migrator'), (int) ($last_result['normalized'] ?? 0))); ?></li>
                        <li><?php echo esc_html(sprintf(__('Skipped (no data): %d', 'betheme-content-migrator'), (int) ($last_result['skipped_no_meta'] ?? 0))); ?></li>
                        <li><?php echo esc_html(sprintf(__('Skipped (invalid base64): %d', 'betheme-content-migrator'), (int) ($last_result['invalid_base64'] ?? 0))); ?></li>
                        <li><?php echo esc_html(sprintf(__('Skipped (unrecognized structure): %d', 'betheme-content-migrator'), (int) ($last_result['skipped_unrecognized'] ?? 0))); ?></li>
                        <li><?php echo esc_html(sprintf(__('Errors: %d', 'betheme-content-migrator'), (int) ($last_result['errors'] ?? 0))); ?></li>
                    </ul>
                    <?php
                    if (!empty($last_result['error_samples']) && is_array($last_result['error_samples'])) {
                        echo '<details><summary>' . esc_html__('Show error samples', 'betheme-content-migrator') . '</summary><ul>';
                        foreach ($last_result['error_samples'] as $sample) {
                            echo '<li>' . esc_html($sample) . '</li>';
                        }
                        echo '</ul></details>';
                    }
                    ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                <input type="hidden" name="action" value="betheme_run_migration" />

                <h2><?php echo esc_html__('Post types to process', 'betheme-content-migrator'); ?></h2>
                <p><?php echo esc_html__('Only posts with the mfn-page-items meta key will be updated.', 'betheme-content-migrator'); ?></p>

                <fieldset>
                    <?php foreach ($this->post_types as $pt) : ?>
                        <label style="display:block; margin:4px 0;">
                            <input type="checkbox" name="betheme_post_types[]" value="<?php echo esc_attr($pt); ?>" checked />
                            <?php echo esc_html($pt); ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

                <p>
                    <label>
                        <input type="checkbox" name="betheme_overwrite_nonempty" value="1" checked />
                        <?php echo esc_html__('Overwrite existing post_content (recommended)', 'betheme-content-migrator'); ?>
                    </label>
                </p>

                <p>
                    <button type="submit" class="button button-primary">
                        <?php echo esc_html__('Run Migration', 'betheme-content-migrator'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    public function handle_migration_submit(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'betheme-content-migrator'));
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        // Options from form
        $requested_post_types = isset($_POST['betheme_post_types']) && is_array($_POST['betheme_post_types'])
            ? array_values(array_intersect(array_map('sanitize_text_field', $_POST['betheme_post_types']), $this->post_types))
            : $this->post_types;

        $overwrite_nonempty = !empty($_POST['betheme_overwrite_nonempty']);

        // Prepare environment for long-running task
        if (!ini_get('safe_mode')) {
            @set_time_limit(0);
        }
        @ini_set('memory_limit', WP_MAX_MEMORY_LIMIT);

        $result = [
            'processed'            => 0,
            'updated'              => 0,
            'normalized'           => 0,
            'skipped_no_meta'      => 0,
            'invalid_base64'       => 0,
            'skipped_unrecognized' => 0,
            'errors'               => 0,
            'error_samples'        => [],
        ];

        $paged       = 1;
        $per_page    = 200;
        $total_found = 0;

        do {
            $q = new WP_Query([
                'post_type'      => $requested_post_types,
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => $per_page,
                'paged'          => $paged,
                'meta_query'     => [
                    [
                        'key'     => self::META_KEY,
                        'compare' => 'EXISTS',
                    ],
                ],
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'no_found_rows'  => false,
            ]);

            $post_ids   = $q->posts;
            $total_found = $q->found_posts;

            foreach ($post_ids as $post_id) {
                $result['processed']++;

                // Fetch the BeTheme builder data
                $encoded = get_post_meta($post_id, self::META_KEY, true);
                if ($encoded === '' || $encoded === null) {
                    $result['skipped_no_meta']++;
                    continue;
                }

                // Strict base64 decode
                $decoded = base64_decode((string) $encoded, true);
                if ($decoded === false) {
                    $result['invalid_base64']++;
                    continue;
                }

                // Optionally skip if post_content already has something and overwrite disabled
                $current = get_post_field('post_content', $post_id, 'raw');
                if (!$overwrite_nonempty && !empty($current)) {
                    continue;
                }

                // Normalize BeTheme data into readable HTML
                $normalized_html = $this->normalize_betheme_content($decoded);

                if ($normalized_html === null) {
                    // If we cannot understand the structure, skip to avoid polluting content.
                    $result['skipped_unrecognized']++;
                    continue;
                }

                $update = [
                    'ID'           => (int) $post_id,
                    'post_content' => $normalized_html,
                ];

                $updated_id = wp_update_post($update, true);
                if (is_wp_error($updated_id)) {
                    $result['errors']++;
                    if (count($result['error_samples']) < 5) {
                        $result['error_samples'][] = sprintf(
                            'Post ID %d: %s',
                            (int) $post_id,
                            $updated_id->get_error_message()
                        );
                    }
                } else {
                    $result['updated']++;
                    $result['normalized']++;
                }
            }

            $paged++;
            wp_reset_postdata();
        } while (!empty($post_ids) && ($paged - 1) * $per_page < $total_found);

        // Store summary to display on the tools page after redirect
        set_transient('betheme_migrator_last_result', $result, 60);

        // Redirect back to the page
        $redirect = add_query_arg(['page' => self::OPTION_SLUG], admin_url('tools.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Normalize BeTheme serialized builder data (decoded from base64) into readable HTML.
     * - Preserves rich text from "column" items (attr['content']) if present.
     * - Extracts image URLs from "image" items (attr['src']) and wraps them in normalized <img> blocks.
     * - Ignores the rest of the builder metadata.
     *
     * Returns null if the structure isn't recognized/parsable.
     */
    private function normalize_betheme_content(string $decoded): ?string {
        // BeTheme data commonly is PHP serialized. Use maybe_unserialize to avoid warnings.
        // If it's not serialized, we don't attempt to parse it.
        if (!is_serialized($decoded)) {
            return null;
        }

        // Unserialize with classes disabled for safety.
        $data = @unserialize($decoded, ['allowed_classes' => false]);
        if ($data === false || !is_array($data)) {
            // Try WP helper as fallback (tolerant), still must be array to proceed.
            $data = maybe_unserialize($decoded);
            if (!is_array($data)) {
                return null;
            }
        }

        $parts = [];
        $this->extract_parts_recursive($data, $parts);

        if (empty($parts)) {
            return '';
        }

        // Join parts with double newlines for readability, sanitize to post content.
        $html = implode("\n\n", $parts);

        // Allow common post HTML including images, links, headings, paragraphs, lists, figures, etc.
        // Using wp_kses_post keeps HTML safe. Our <figure> is not in default, so we add to allowed list.
        $allowed = wp_kses_allowed_html('post');
        // Add figure/figcaption if not present
        if (!isset($allowed['figure'])) {
            $allowed['figure'] = ['class' => true];
        }
        if (!isset($allowed['figcaption'])) {
            $allowed['figcaption'] = ['class' => true];
        }
        // Allow loading and decoding attributes on img
        if (isset($allowed['img'])) {
            $allowed['img']['loading']  = true;
            $allowed['img']['decoding'] = true;
            $allowed['img']['sizes']    = true;
            $allowed['img']['srcset']   = true;
        }

        return wp_kses($html, $allowed);
    }

    /**
     * Recursively walk BeTheme builder array and collect:
     * - HTML from attr['content'] if it's a string
     * - <img> blocks from image items using attr['src']
     *
     * @param mixed $node
     * @param array<int,string> $parts
     * @return void
     */
    private function extract_parts_recursive($node, array &$parts): void {
        if (is_array($node)) {
            // If node itself looks like an item, try to extract directly.
            if ($this->is_betheme_item_array($node)) {
                $this->maybe_collect_from_item($node, $parts);
            }

            // Recurse into all children.
            foreach ($node as $child) {
                $this->extract_parts_recursive($child, $parts);
            }
        }
    }

    /**
     * Determine if array has signatures of a BeTheme "item".
     */
    private function is_betheme_item_array(array $node): bool {
        // Typical item keys: 'type' (e.g., image, column, button), 'attr' => array
        if (isset($node['type']) && is_string($node['type']) && isset($node['attr']) && is_array($node['attr'])) {
            return true;
        }
        // Some containers may not have 'type' but contain 'attr' only; still handled by recursion.
        return false;
    }

    /**
     * Collect content from a BeTheme item into $parts.
     *
     * - column: append attr['content'] if present
     * - image: append normalized <img> with src
     */
    private function maybe_collect_from_item(array $item, array &$parts): void {
        $type = isset($item['type']) && is_string($item['type']) ? strtolower($item['type']) : '';

        // 1) Column/content blocks
        if (isset($item['attr']['content']) && is_string($item['attr']['content']) && $item['attr']['content'] !== '') {
            $content = $item['attr']['content'];

            // In rare cases, content might be encoded entities. We keep as-is; WP will render correctly.
            $parts[] = $this->wrap_as_block($content);
        }

        // 2) Images
        if ($type === 'image' && isset($item['attr']['src']) && is_string($item['attr']['src']) && $item['attr']['src'] !== '') {
            $src = esc_url_raw($item['attr']['src']);

            // Try to infer alt from title if present; else empty.
            $alt = '';
            if (isset($item['title']) && is_string($item['title']) && $item['title'] !== '') {
                $alt = sanitize_text_field($item['title']);
            }

            $img_html = sprintf(
                '<figure class="migrated-image"><img src="%s" alt="%s" loading="lazy" decoding="async" /></figure>',
                esc_url($src),
                esc_attr($alt)
            );

            $parts[] = $img_html;
        }

        // 3) Optional: other items like buttons could be ignored (as requested),
        // but if you'd like to preserve links in the future, handle 'button' here.
    }

    /**
     * Wrap raw HTML content in a block-level container only if it doesn't already start with a block tag.
     */
    private function wrap_as_block(string $html): string {
        $trim = ltrim($html);
        // If it begins with a common block tag, return as-is.
        if (preg_match('#^<(p|h[1-6]|ul|ol|div|section|article|blockquote|figure|table|img)\b#i', $trim)) {
            return $html;
        }
        // Otherwise, wrap in a paragraph.
        return '<p>' . $html . '</p>';
    }
}

new BeTheme_Content_Migrator();
