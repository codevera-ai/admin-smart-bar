<?php
namespace AdminSmartBar;

/**
 * Extract content from various page builders for indexing
 * Supports the top 10 WordPress page builders
 */
class Page_Builder_Content_Extractor {

    /**
     * Page builder configurations
     * Maps builder name to meta key and extraction method
     */
    private $builders = [
        'elementor' => [
            'meta_key' => '_elementor_data',
            'check_key' => '_elementor_edit_mode',
            'format' => 'json'
        ],
        'divi' => [
            'meta_key' => '_et_pb_use_builder',
            'check_key' => '_et_pb_use_builder',
            'format' => 'shortcode'
        ],
        'beaver_builder' => [
            'meta_key' => '_fl_builder_data',
            'check_key' => '_fl_builder_enabled',
            'format' => 'serialized'
        ],
        'seedprod' => [
            'meta_key' => '_seedprod_page',
            'check_key' => '_seedprod_page',
            'format' => 'json'
        ],
        'oxygen' => [
            'meta_key' => 'ct_builder_shortcodes',
            'check_key' => 'ct_builder_shortcodes',
            'format' => 'shortcode'
        ],
        'bricks' => [
            'meta_key' => '_bricks_page_content_2',
            'check_key' => '_bricks_page_content_2',
            'format' => 'json'
        ],
        'breakdance' => [
            'meta_key' => '_breakdance_data',
            'check_key' => '_breakdance_data',
            'format' => 'json'
        ],
        'wpbakery' => [
            'meta_key' => '_wpb_vc_js_status',
            'check_key' => '_wpb_vc_js_status',
            'format' => 'shortcode'
        ],
        'thrive_architect' => [
            'meta_key' => 'tcb_editor_enabled',
            'check_key' => 'tcb_editor_enabled',
            'format' => 'custom'
        ],
        'visual_composer' => [
            'meta_key' => 'vcv-pageContent',
            'check_key' => 'vcv-pageContent',
            'format' => 'json'
        ]
    ];

    /**
     * Extract content from all active page builders on a post
     *
     * @param int $post_id The post ID to extract content from
     * @return string Combined content from all detected page builders
     */
    public function extract_content($post_id) {
        $combined_content = '';

        foreach ($this->builders as $builder_name => $config) {
            $content = $this->extract_from_builder($post_id, $builder_name, $config);
            if (!empty($content)) {
                $combined_content .= ' ' . $content;
            }
        }

        return trim($combined_content);
    }

    /**
     * Extract content from a specific page builder
     *
     * @param int $post_id Post ID
     * @param string $builder_name Builder name
     * @param array $config Builder configuration
     * @return string Extracted content
     */
    private function extract_from_builder($post_id, $builder_name, $config) {
        // Check if this builder is active on this post
        if (!$this->is_builder_active($post_id, $config['check_key'])) {
            return '';
        }

        $meta_value = get_post_meta($post_id, $config['meta_key'], true);

        if (empty($meta_value)) {
            return '';
        }

        // Extract content based on format
        switch ($config['format']) {
            case 'json':
                return $this->extract_from_json($meta_value);

            case 'serialized':
                return $this->extract_from_serialized($meta_value);

            case 'shortcode':
                return $this->extract_from_shortcode($meta_value, $post_id);

            case 'custom':
                return $this->extract_custom($post_id, $builder_name);

            default:
                return '';
        }
    }

    /**
     * Check if a page builder is active on a post
     *
     * @param int $post_id Post ID
     * @param string $check_key Meta key to check
     * @return bool True if builder is active
     */
    private function is_builder_active($post_id, $check_key) {
        $meta_value = get_post_meta($post_id, $check_key, true);

        // Meta exists and is not empty/false
        return !empty($meta_value) && $meta_value !== 'off';
    }

    /**
     * Extract text content from JSON data
     *
     * @param string|array $data JSON string or array
     * @return string Extracted text content
     */
    private function extract_from_json($data) {
        // If it's a string, decode it
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        if (!is_array($data)) {
            return '';
        }

        return $this->extract_text_from_array($data);
    }

    /**
     * Extract text content from serialized PHP data
     *
     * @param string $data Serialized PHP data
     * @return string Extracted text content
     */
    private function extract_from_serialized($data) {
        // Unserialize the data
        $unserialized = @unserialize($data);

        if ($unserialized === false) {
            return '';
        }

        if (!is_array($unserialized)) {
            return '';
        }

        return $this->extract_text_from_array($unserialized);
    }

    /**
     * Extract text content from shortcodes
     *
     * @param string $content Content with shortcodes
     * @param int $post_id Post ID
     * @return string Extracted text content
     */
    private function extract_from_shortcode($content, $post_id) {
        // For Divi and WPBakery, content might be in post_content
        // Get post content if meta value is just a flag
        if ($content === 'on' || $content === 'true' || $content === '1') {
            $post = get_post($post_id);
            if ($post) {
                $content = $post->post_content;
            }
        }

        // Strip all shortcodes but keep the content between them
        $content = strip_shortcodes($content);

        // Remove any remaining shortcode markers
        $content = preg_replace('/\[.*?\]/', '', $content);

        // Clean up HTML
        $content = wp_strip_all_tags($content);

        return $content;
    }

    /**
     * Extract custom content for builders with unique storage
     *
     * @param int $post_id Post ID
     * @param string $builder_name Builder name
     * @return string Extracted content
     */
    private function extract_custom($post_id, $builder_name) {
        switch ($builder_name) {
            case 'thrive_architect':
                // Thrive stores content in post_content when enabled
                $post = get_post($post_id);
                if ($post) {
                    return wp_strip_all_tags($post->post_content);
                }
                break;
        }

        return '';
    }

    /**
     * Recursively extract text content from nested arrays
     *
     * @param array $data Array to extract from
     * @return string Extracted text content
     */
    private function extract_text_from_array($data) {
        $text_content = [];

        // Common text field keys used by page builders
        $text_keys = [
            'content', 'text', 'title', 'heading', 'description',
            'caption', 'label', 'placeholder', 'value', 'html',
            'editor', 'textarea', 'settings', 'widgetType'
        ];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Recursively extract from nested arrays
                $nested_content = $this->extract_text_from_array($value);
                if (!empty($nested_content)) {
                    $text_content[] = $nested_content;
                }
            } elseif (is_string($value) && in_array($key, $text_keys)) {
                // This is a text field we want to index
                $clean_text = $this->clean_text($value);
                if (!empty($clean_text)) {
                    $text_content[] = $clean_text;
                }
            } elseif (is_string($value) && strlen($value) > 10) {
                // For unknown keys, include if it looks like content (longer than 10 chars)
                $clean_text = $this->clean_text($value);
                if (!empty($clean_text) && $this->looks_like_content($clean_text)) {
                    $text_content[] = $clean_text;
                }
            }
        }

        return implode(' ', $text_content);
    }

    /**
     * Clean text content
     *
     * @param string $text Text to clean
     * @return string Cleaned text
     */
    private function clean_text($text) {
        // Strip HTML tags
        $text = wp_strip_all_tags($text);

        // Strip shortcodes (removes tags but keeps content between them)
        $text = strip_shortcodes($text);

        // Remove any remaining square brackets
        $text = preg_replace('/\[.*?\]/', '', $text);

        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        // Trim
        $text = trim($text);

        return $text;
    }

    /**
     * Check if text looks like actual content (not code, URLs, etc.)
     *
     * @param string $text Text to check
     * @return bool True if it looks like content
     */
    private function looks_like_content($text) {
        // Skip if it's mostly non-alphanumeric (likely code or data)
        $alphanumeric_ratio = preg_match_all('/[a-zA-Z0-9\s]/', $text) / strlen($text);
        if ($alphanumeric_ratio < 0.6) {
            return false;
        }

        // Skip if it looks like a URL or file path
        if (preg_match('/^(https?:\/\/|\/\w+\/|[a-z]:\\\\)/i', $text)) {
            return false;
        }

        // Skip if it looks like CSS or JSON
        if (preg_match('/^[\{\[\]].+[\}\]]$/s', $text) ||
            preg_match('/[{;}].*[:;]/', $text)) {
            return false;
        }

        // Skip common meta/config keys
        $skip_patterns = ['_id', '_type', 'css', 'class', 'id=', 'style='];
        foreach ($skip_patterns as $pattern) {
            if (stripos($text, $pattern) !== false && strlen($text) < 50) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get list of active page builders on a post
     *
     * @param int $post_id Post ID
     * @return array Array of active builder names
     */
    public function get_active_builders($post_id) {
        $active_builders = [];

        foreach ($this->builders as $builder_name => $config) {
            if ($this->is_builder_active($post_id, $config['check_key'])) {
                $active_builders[] = $builder_name;
            }
        }

        return $active_builders;
    }
}
