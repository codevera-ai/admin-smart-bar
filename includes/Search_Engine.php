<?php
namespace AdminSmartBar;

use YetiSearch\YetiSearch;

class Search_Engine {

    private $yeti;
    private $indexer;
    private $db_path;
    private $index_name = 'admin_smart_bar';

    public function __construct() {
        $this->db_path = ADMIN_SMART_BAR_PLUGIN_DIR . 'data/search.db';
        $this->init_search();
    }

    /**
     * Initialise YetiSearch engine with optimized configuration
     */
    private function init_search() {
        try {
            $config = [
                'storage' => [
                    'path' => $this->db_path,
                    'journal_mode' => 'WAL',        // Write-Ahead Logging for better concurrency
                    'synchronous' => 'NORMAL',      // Balance between safety and performance
                    'cache_size' => -64000,         // 64MB cache for better performance
                    'temp_store' => 'MEMORY'        // Use memory for temp tables
                ],
                'indexer' => [
                    'fields' => [
                        'title' => ['boost' => 10.0, 'store' => true],      // Highest priority for titles
                        'content' => ['boost' => 1.0, 'store' => true],     // Standard content
                        'excerpt' => ['boost' => 2.0, 'store' => true],     // Medium priority
                        'tags' => ['boost' => 5.0, 'store' => true],        // High priority for tags
                        'category' => ['boost' => 4.0, 'store' => true]     // High priority for categories
                    ],
                    'batch_size' => 250,            // Optimized batch size
                    'chunk_size' => 1000,           // Characters per chunk
                    'chunk_overlap' => 100,         // Overlap for context
                    'fts' => [
                        'multi_column' => true,     // Enable multi-column FTS for better field weighting
                        'prefix' => [2, 3]          // Enable prefix indexing for type-ahead
                    ]
                ],
                'search' => [
                    // Multi-column FTS and field weighting
                    'multi_column_fts' => true,     // Use separate FTS columns for native BM25 weighting
                    'field_weights' => [
                        'title' => 10.0,
                        'tags' => 5.0,
                        'category' => 4.0,
                        'excerpt' => 2.0,
                        'content' => 1.0
                    ],

                    // Exact match boosting
                    'exact_match_boost' => 2.0,     // Boost exact phrase matches
                    'exact_terms_boost' => 1.5,     // Boost when all exact terms are present

                    // Enhanced fuzzy search with modern typo correction
                    'enable_fuzzy' => true,
                    'fuzzy_correction_mode' => true,    // Modern typo correction like Google
                    'fuzzy_algorithm' => 'trigram',     // Best balance of speed and accuracy
                    'correction_threshold' => 0.6,      // Sensitivity for corrections
                    'trigram_threshold' => 0.35,        // Trigram similarity threshold
                    'fuzzy_score_penalty' => 0.25,      // Lower penalty for corrected matches
                    'levenshtein_threshold' => 2,       // Allow up to 2 character edits
                    'min_term_frequency' => 2,          // Min occurrences for fuzzy matching
                    'max_fuzzy_variations' => 8,        // Max variations per term

                    // Query result caching for 10-100x faster repeated searches
                    'cache' => [
                        'enabled' => true,          // Enable query caching
                        'ttl' => 300,              // Cache for 5 minutes
                        'max_size' => 1000         // Store up to 1000 queries
                    ],

                    // Type-ahead support
                    'prefix_last_token' => true,        // Enable last-token prefix matching
                    'fuzzy_last_token_only' => false,   // Fuzzy match all tokens for better results

                    // Search behavior
                    'min_score' => 0.0,
                    'highlight_tag' => '<mark>',
                    'highlight_tag_close' => '</mark>',
                    'snippet_length' => 150,
                    'max_results' => 1000,
                    'enable_suggestions' => true,

                    // Result fields to return
                    'result_fields' => ['title', 'content', 'excerpt', 'tags', 'category']
                ],
                'analyzer' => [
                    'min_word_length' => 2,
                    'max_word_length' => 50,
                    'lowercase' => true,
                    'strip_html' => true,
                    'strip_punctuation' => true,
                    'expand_contractions' => true,
                    'disable_stop_words' => false
                ]
            ];

            $this->yeti = new YetiSearch($config);

            // Get or create indexer
            $this->indexer = $this->yeti->getIndexer($this->index_name);
            if (!$this->indexer) {
                $this->indexer = $this->yeti->createIndex($this->index_name);
            }
        } catch (\Exception $e) {
            // Silently fail - search will fall back to WordPress default search
        }
    }

    /**
     * Index a single post/page
     */
    public function index_post($post_id) {
        $post = get_post($post_id);

        if (!$post || $post->post_status === 'auto-draft') {
            return false;
        }

        // Delete existing document first
        $this->delete_document($post_id, 'post');

        // Prepare full content for indexing
        $body = wp_strip_all_tags($post->post_content);

        // Index the document with proper field structure
        try {
            // Get additional metadata
            $categories = wp_get_post_categories($post_id, ['fields' => 'names']);
            $tags = wp_get_post_tags($post_id, ['fields' => 'names']);

            $doc = [
                'id' => 'post_' . $post_id,
                'content' => [
                    'title' => $post->post_title,
                    'content' => $body,
                    'excerpt' => $post->post_excerpt,
                    'tags' => !empty($tags) ? implode(' ', $tags) : '',
                    'category' => !empty($categories) ? implode(' ', $categories) : ''
                ],
                'metadata' => [
                    'type' => $post->post_type,
                    'status' => $post->post_status,
                    'post_id' => $post_id
                ],
                'type' => $post->post_type
            ];

            $this->indexer->insert($doc);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Index a single media item
     */
    public function index_media($attachment_id) {
        $attachment = get_post($attachment_id);

        if (!$attachment || $attachment->post_type !== 'attachment') {
            return false;
        }

        // Delete existing document first
        $this->delete_document($attachment_id, 'media');

        $content = $attachment->post_content;
        $excerpt = $attachment->post_excerpt;

        try {
            $this->indexer->insert([
                'id' => 'media_' . $attachment_id,
                'content' => [
                    'title' => $attachment->post_title,
                    'content' => $content,
                    'excerpt' => $excerpt,
                    'tags' => '',
                    'category' => ''
                ],
                'metadata' => [
                    'type' => 'attachment',
                    'status' => 'inherit',
                    'post_id' => $attachment_id
                ],
                'type' => 'attachment'
            ]);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Search users directly from WordPress database (not stored in SQLite)
     * This improves security by not storing user emails/logins in the search database
     */
    private function search_users($query, $limit = 50) {
        // Check if current user can list users
        if (!current_user_can('list_users')) {
            return [];
        }

        $user_query = new \WP_User_Query([
            'search' => '*' . esc_attr($query) . '*',
            'search_columns' => ['user_login', 'user_nicename', 'user_email', 'display_name'],
            'number' => $limit,
            'orderby' => 'display_name',
            'order' => 'ASC'
        ]);

        $users = $user_query->get_results();
        $results = [];

        foreach ($users as $user) {
            $results[] = [
                'document' => [
                    'id' => 'user_' . $user->ID,
                    'type' => 'user',
                    'metadata' => [
                        'type' => 'user',
                        'user_id' => $user->ID,
                        'status' => $user->user_email
                    ]
                ],
                'score' => 1.0 // Base score for direct matches
            ];
        }

        return $results;
    }

    /**
     * Delete a document from the index
     */
    public function delete_document($item_id, $type) {
        $doc_id = $type . '_' . $item_id;

        try {
            $this->indexer->delete($doc_id);
            return true;
        } catch (\Exception $e) {
            // Silently fail if document doesn't exist
            return false;
        }
    }

    /**
     * Prefix search by querying SQLite FTS5 directly to preserve wildcards
     */
    private function search_prefix($query, $limit = 50) {
        try {
            // Add wildcard to last token for prefix matching in FTS5
            $tokens = explode(' ', trim($query));
            if (!empty($tokens)) {
                $lastToken = array_pop($tokens);
                // Only add wildcard if last token doesn't already have one and is at least 2 chars
                if (strlen($lastToken) >= 2 && substr($lastToken, -1) !== '*') {
                    $lastToken .= '*';
                }
                $tokens[] = $lastToken;
                $query = implode(' ', $tokens);
            }

            // Query FTS5 directly to avoid YetiSearch's query tokenization which strips wildcards
            $db = new \PDO('sqlite:' . $this->db_path);
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $sql = "
                SELECT
                    d.id,
                    d.type,
                    d.metadata,
                    bm25({$this->index_name}_fts) as rank
                FROM {$this->index_name} d
                INNER JOIN {$this->index_name}_fts f ON d.id = f.id
                WHERE {$this->index_name}_fts MATCH ?
                ORDER BY rank
                LIMIT ?
            ";

            $stmt = $db->prepare($sql);
            $stmt->execute([$query, $limit]);
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($results)) {
                return [];
            }

            // Convert storage results to our format
            $formatted = [];
            foreach ($results as $result) {
                $metadata = json_decode($result['metadata'] ?? '[]', true);
                $formatted[] = [
                    'document' => [
                        'id' => $result['id'] ?? '',
                        'type' => $result['type'] ?? '',
                        'metadata' => $metadata
                    ],
                    'score' => abs($result['rank'] ?? 0) // BM25 rank is negative
                ];
            }

            return $formatted;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Search with YetiSearch fuzzy matching
     */
    private function search_fuzzy($query, $limit = 50) {
        try {
            // Use YetiSearch's fuzzy search as fallback
            $results = $this->yeti->search($this->index_name, $query, [
                'limit' => $limit,
                'fuzzy' => true,
                'fuzzy_algorithm' => 'levenshtein',
                'levenshtein_threshold' => 2,
                'fuzzy_score_penalty' => 0.8,
                'highlight' => false
            ]);

            if (!isset($results['results']) || empty($results['results'])) {
                return [];
            }

            // Convert YetiSearch results to our format
            $formatted = [];
            foreach ($results['results'] as $result) {
                $formatted[] = [
                    'document' => [
                        'id' => $result['id'] ?? '',
                        'type' => $result['metadata']['type'] ?? '',
                        'metadata' => $result['metadata'] ?? []
                    ],
                    'score' => $result['score'] ?? 0
                ];
            }

            return $formatted;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Search across all content
     * Uses prefix matching first, then falls back to fuzzy search if no results
     * Users are searched directly from WordPress database for security
     */
    public function search($query, $types = ['posts', 'pages', 'media', 'users', 'products'], $limit = 50) {
        try {
            $all_results = [];

            // Search posts/pages/media/products from SQLite
            if ($this->yeti && array_intersect(['posts', 'pages', 'media', 'products'], $types)) {
                // Try prefix matching first (fast and accurate)
                $results = $this->search_prefix($query, $limit);

                // If no results, fall back to fuzzy search for typos/misspellings
                if (empty($results)) {
                    $results = $this->search_fuzzy($query, $limit);
                }

                // Build filters based on types
                $allowed_post_types = [];
                if (in_array('posts', $types)) {
                    $allowed_post_types[] = 'post';
                }
                if (in_array('pages', $types)) {
                    $allowed_post_types[] = 'page';
                }
                if (in_array('products', $types)) {
                    $allowed_post_types[] = 'product';
                }
                if (in_array('media', $types)) {
                    $allowed_post_types[] = 'attachment';
                }

                // Filter results by type and user capabilities
                foreach ($results as $result) {
                    $doc = $result['document'];
                    $type = $doc['type'] ?? null;
                    $metadata = $doc['metadata'] ?? [];

                    if (!$type) {
                        continue;
                    }

                    // Check if post type is allowed
                    if (in_array($type, $allowed_post_types)) {
                        // Check if current user has permission to read this post
                        $post_id = $metadata['post_id'] ?? null;

                        if (!$post_id) {
                            continue;
                        }

                        // Get the post to check its status and capabilities
                        $post = get_post($post_id);

                        if (!$post) {
                            continue;
                        }

                        // Check read permission based on post status
                        if (!$this->user_can_read_post($post)) {
                            continue;
                        }

                        $all_results[] = $result;
                    }
                }
            }

            // Search users directly from WordPress database (not SQLite)
            if (in_array('users', $types)) {
                $user_results = $this->search_users($query, $limit);
                $all_results = array_merge($all_results, $user_results);
            }

            return $all_results;

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Check if current user can read a specific post based on status and capabilities
     *
     * @param WP_Post $post The post object to check
     * @return bool True if user can read the post, false otherwise
     */
    private function user_can_read_post($post) {
        $post_type = get_post_type_object($post->post_type);

        if (!$post_type) {
            return false;
        }

        // Published posts - check basic read capability
        if ($post->post_status === 'publish') {
            return current_user_can($post_type->cap->read);
        }

        // Private posts - check if user is author or has read_private capability
        if ($post->post_status === 'private') {
            $read_private_cap = $post_type->cap->read_private_posts ?? 'read_private_posts';
            return current_user_can($read_private_cap) || $post->post_author == get_current_user_id();
        }

        // Draft, pending, future posts - check if user is author or has edit capability
        if (in_array($post->post_status, ['draft', 'pending', 'future'])) {
            $edit_posts_cap = $post_type->cap->edit_posts ?? 'edit_posts';
            return current_user_can($edit_posts_cap) || $post->post_author == get_current_user_id();
        }

        // Trash - only users with appropriate delete capability
        if ($post->post_status === 'trash') {
            $delete_posts_cap = $post_type->cap->delete_posts ?? 'delete_posts';
            return current_user_can($delete_posts_cap);
        }

        // For any other status, check edit_post capability for the specific post
        return current_user_can('edit_post', $post->ID);
    }

    /**
     * Bulk index all content
     */
    public function reindex_all() {
        $start_time = microtime(true);
        $total = 0;

        // Ensure index exists, create if it doesn't
        if (!$this->indexer) {
            $this->indexer = $this->yeti->createIndex($this->index_name);
        }

        // Clear existing index
        $this->clear_index();

        $search_types = get_option('asb_search_types', ['posts', 'pages', 'media', 'users']);

        // Index posts and pages
        if (array_intersect(['posts', 'pages'], $search_types)) {
            $post_types = [];
            if (in_array('posts', $search_types)) {
                $post_types[] = 'post';
            }
            if (in_array('pages', $search_types)) {
                $post_types[] = 'page';
            }

            $args = [
                'post_type' => $post_types,
                'posts_per_page' => -1,
                'post_status' => 'any',
                'fields' => 'ids'
            ];

            $post_ids = get_posts($args);
            foreach ($post_ids as $post_id) {
                if ($this->index_post($post_id)) {
                    $total++;
                }
            }
        }

        // Index products
        if (in_array('products', $search_types) && (function_exists('WC') || class_exists('WooCommerce'))) {
            $args = [
                'post_type' => 'product',
                'posts_per_page' => -1,
                'post_status' => 'any',
                'fields' => 'ids'
            ];

            $product_ids = get_posts($args);
            foreach ($product_ids as $product_id) {
                if ($this->index_post($product_id)) {
                    $total++;
                }
            }
        }

        // Index media
        if (in_array('media', $search_types)) {
            $args = [
                'post_type' => 'attachment',
                'posts_per_page' => -1,
                'post_status' => 'inherit',
                'fields' => 'ids'
            ];

            $media_ids = get_posts($args);
            foreach ($media_ids as $media_id) {
                if ($this->index_media($media_id)) {
                    $total++;
                }
            }
        }

        // Note: Users are NOT indexed in SQLite for security reasons.
        // User searches are performed directly against WordPress database.

        // Flush and optimize the index
        $this->indexer->flush();
        $this->optimize();

        $duration = microtime(true) - $start_time;

        return [
            'total' => $total,
            'duration' => $duration
        ];
    }

    /**
     * Clear the entire index
     */
    public function clear_index() {
        try {
            $this->indexer->clear();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Optimise the search index
     */
    public function optimize() {
        try {
            $this->indexer->optimize();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get index statistics with breakdown by type
     */
    public function get_stats() {
        try {
            if (!$this->yeti) {
                return [
                    'total_documents' => 0,
                    'database_size' => 0,
                    'breakdown' => []
                ];
            }

            // Get or create indexer to ensure we have latest stats
            $indexer = $this->yeti->getIndexer($this->index_name);

            if (!$indexer) {
                return [
                    'total_documents' => 0,
                    'database_size' => file_exists($this->db_path) ? filesize($this->db_path) : 0
                ];
            }

            $stats = $indexer->getStats();

            return [
                'total_documents' => $stats['document_count'] ?? 0,
                'database_size' => file_exists($this->db_path) ? filesize($this->db_path) : 0
            ];
        } catch (\Exception $e) {
            return [
                'total_documents' => 0,
                'database_size' => file_exists($this->db_path) ? filesize($this->db_path) : 0
            ];
        }
    }
}
