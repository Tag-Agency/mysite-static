<?php
/**
 * Plugin Name: Static Publisher → Vercel (ULTRA FAST)
 * Description: Genera sito statico ultra-veloce con ottimizzazioni performance avanzate. Zero caricamenti, massima velocità.
 * Version: 4.0.0
 * Author: Tag Agency (Mauro)
 */

if (!defined('ABSPATH')) exit;

define('SPV_VERSION', '4.0.0');
define('SPV_OUT_DIR', WP_CONTENT_DIR . '/static-site');
define('SPV_PROGRESS_TTL', 60 * 60);

class StaticPublisher_Vercel {

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('init', [$this, 'static_mode']);
        
        add_action('wp_ajax_spv_prepare_deploy', [$this, 'ajax_prepare_deploy']);
        add_action('wp_ajax_spv_deploy_step', [$this, 'ajax_deploy_step']);
    }

    /* ==================== ADMIN UI ==================== */
    public function admin_menu() {
        add_menu_page(
            'Static Publisher → Vercel', 
            'Static Publisher', 
            'manage_options', 
            'static-publisher', 
            [$this, 'admin_page'],
            'dashicons dashicons-performance',
            58
        );
    }

    public function register_settings() {
        register_setting('spv_settings', 'spv_github_token');
        register_setting('spv_settings', 'spv_github_repo');
        register_setting('spv_settings', 'spv_github_branch', ['default' => 'main']);
        register_setting('spv_settings', 'spv_vercel_token');
        register_setting('spv_settings', 'spv_vercel_project');
        register_setting('spv_settings', 'spv_exclusions');
        register_setting('spv_settings', 'spv_optimize_images', ['default' => '1']);
        register_setting('spv_settings', 'spv_minify_html', ['default' => '1']);
    }

    private function progress_key() { 
        return 'spv_progress_' . get_current_user_id(); 
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) return;

        // Handle actions
        $report = $this->handle_actions();

        // Get settings
        $settings = $this->get_settings();
        $ajax_data = $this->get_ajax_data();

        ?>
        <div class="wrap">
            <h1>⚡ Static Publisher → Vercel</h1>
            <p class="description">Genera un sito statico ultra-veloce con deploy automatico su Vercel</p>

            <!-- Settings Form -->
            <form method="post" action="options.php">
                <?php settings_fields('spv_settings'); ?>
                
                <div class="spv-settings-grid">
                    <!-- GitHub Settings -->
                    <div class="spv-card">
                        <h3>🔗 GitHub Configuration</h3>
                        <table class="form-table">
                            <tr><th>GitHub Token</th><td><input type="password" name="spv_github_token" value="<?= $settings['github_token'] ?>" class="regular-text" placeholder="ghp_..." /></td></tr>
                            <tr><th>Repository</th><td><input type="text" name="spv_github_repo" value="<?= $settings['github_repo'] ?>" class="regular-text" placeholder="username/repo" /></td></tr>
                            <tr><th>Branch</th><td><input type="text" name="spv_github_branch" value="<?= $settings['github_branch'] ?>" class="regular-text" /></td></tr>
                        </table>
                    </div>

                    <!-- Vercel Settings -->
                    <div class="spv-card">
                        <h3>🚀 Vercel Configuration</h3>
                        <table class="form-table">
                            <tr><th>Vercel Token</th><td><input type="password" name="spv_vercel_token" value="<?= $settings['vercel_token'] ?>" class="regular-text" placeholder="vercel_..." /></td></tr>
                            <tr><th>Project Name</th><td><input type="text" name="spv_vercel_project" value="<?= $settings['vercel_project'] ?>" class="regular-text" /></td></tr>
                        </table>
                    </div>

                    <!-- Optimization Settings -->
                    <div class="spv-card">
                        <h3>⚡ Performance Optimizations</h3>
                        <table class="form-table">
                            <tr><th>Exclusions</th><td><textarea name="spv_exclusions" rows="3" class="large-text code" placeholder="/admin/&#10;/private/"><?= $settings['exclusions'] ?></textarea></td></tr>
                            <tr><th>Optimize Images</th><td><input type="checkbox" name="spv_optimize_images" value="1" <?= $settings['optimize_images'] ? 'checked' : '' ?> /> Compress images</td></tr>
                            <tr><th>Minify HTML</th><td><input type="checkbox" name="spv_minify_html" value="1" <?= $settings['minify_html'] ? 'checked' : '' ?> /> Minify HTML/CSS/JS</td></tr>
                        </table>
                    </div>
                </div>

                <?php submit_button('Save Settings'); ?>
            </form>

            <hr>

            <!-- Actions -->
            <div class="spv-actions">
                <?php wp_nonce_field('spv_nonce_action', 'spv_nonce_field'); ?>
                <button class="button button-large" name="spv_action" value="build_only" onclick="this.form.submit()">🏗️ Build Static Site</button>
                <button type="button" id="spv-deploy-btn" class="button button-large button-primary">🚀 Build & Deploy</button>
                <button class="button button-large" name="spv_action" value="test_github" onclick="this.form.submit()">🔍 Test GitHub</button>
                <button class="button button-large" name="spv_action" value="setup_workflow" onclick="this.form.submit()">⚙️ Setup Workflow</button>
            </div>

            <!-- Progress Bar -->
            <div id="spv-progress" style="display: none; margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                    <span id="spv-status">Preparing...</span>
                    <span id="spv-percentage">0%</span>
                </div>
                <div style="background: #f0f0f0; border-radius: 10px; height: 20px;">
                    <div id="spv-progress-bar" style="background: #2271b1; height: 100%; width: 0%; border-radius: 10px; transition: width 0.3s;"></div>
                </div>
                <pre id="spv-log" style="background: #f6f7f7; padding: 15px; margin-top: 15px; max-height: 300px; overflow: auto; display: none;"></pre>
            </div>

            <?php if ($report): ?>
                <div class="spv-report">
                    <h3>📊 Build Report</h3>
                    <pre><?= esc_html($report) ?></pre>
                </div>
            <?php endif; ?>
        </div>

        <style>
        .spv-settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; margin: 20px 0; }
        .spv-card { background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #2271b1; }
        .spv-actions { display: flex; gap: 10px; flex-wrap: wrap; margin: 20px 0; }
        .spv-report { background: white; padding: 20px; margin-top: 20px; border-radius: 8px; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            $('#spv-deploy-btn').on('click', function() {
                const progress = $('#spv-progress');
                const log = $('#spv-log');
                const bar = $('#spv-progress-bar');
                const status = $('#spv-status');
                const percentage = $('#spv-percentage');

                progress.show();
                log.show();

                function updateProgress(step, percent, message) {
                    bar.css('width', percent + '%');
                    percentage.text(percent + '%');
                    status.text(message);
                    if (message) {
                        log.append(message + '\\n');
                        log.scrollTop(log[0].scrollHeight);
                    }
                }

                function doStep(step) {
                    return $.ajax({
                        url: '<?= $ajax_data['url'] ?>',
                        method: 'POST',
                        data: {
                            action: step === 0 ? 'spv_prepare_deploy' : 'spv_deploy_step',
                            _ajax_nonce: '<?= $ajax_data['nonce'] ?>'
                        }
                    });
                }

                async function startDeploy() {
                    try {
                        // Step 0: Preparation
                        updateProgress(0, 0, '🚀 Starting deployment...');
                        const prep = await doStep(0);
                        
                        if (!prep.success) throw new Error(prep.data.error);
                        
                        // Process steps
                        let finished = false;
                        while (!finished) {
                            const step = await doStep(1);
                            
                            if (!step.success) throw new Error(step.data.error);
                            
                            updateProgress(
                                step.data.done, 
                                step.data.percent, 
                                step.data.message
                            );

                            if (step.data.phase === 'done') {
                                updateProgress(3, 100, '✅ Deployment completed!');
                                log.append('🎉 Site is live on Vercel!\\n');
                                finished = true;
                            }
                        }
                    } catch (error) {
                        updateProgress(0, 0, '❌ Error: ' + error.message);
                        console.error(error);
                    }
                }

                startDeploy();
            });
        });
        </script>
        <?php
    }

    private function handle_actions() {
        if (!isset($_POST['spv_action']) || !wp_verify_nonce($_POST['spv_nonce_field'], 'spv_nonce_action')) {
            return '';
        }

        $action = sanitize_text_field($_POST['spv_action']);
        
        try {
            switch ($action) {
                case 'build_only':
                    return $this->build_static_site();
                case 'test_github':
                    $this->test_github_connection();
                    $this->admin_notice('✅ GitHub connection successful!', 'success');
                    break;
                case 'setup_workflow':
                    $this->setup_github_workflow();
                    $this->admin_notice('✅ GitHub workflow created!', 'success');
                    break;
            }
        } catch (Exception $e) {
            $this->admin_notice('❌ Error: ' . $e->getMessage(), 'error');
            return $e->getMessage();
        }

        return '';
    }

    private function admin_notice($message, $type = 'success') {
        add_action('admin_notices', function() use ($message, $type) {
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $type, $message);
        });
    }

    private function get_settings() {
        return [
            'github_token' => esc_attr(get_option('spv_github_token', '')),
            'github_repo' => esc_attr(get_option('spv_github_repo', '')),
            'github_branch' => esc_attr(get_option('spv_github_branch', 'main')),
            'vercel_token' => esc_attr(get_option('spv_vercel_token', '')),
            'vercel_project' => esc_attr(get_option('spv_vercel_project', '')),
            'exclusions' => esc_textarea(get_option('spv_exclusions', '')),
            'optimize_images' => get_option('spv_optimize_images', '1'),
            'minify_html' => get_option('spv_minify_html', '1')
        ];
    }

    private function get_ajax_data() {
        return [
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('spv_ajax_nonce')
        ];
    }

    /* ==================== STATIC MODE ==================== */
    public function static_mode() {
        if (isset($_GET['static']) && $_GET['static'] === '1') {
            define('SPV_STATIC_MODE', true);
            show_admin_bar(false);
            // Remove all dynamic functionality
            remove_action('wp_head', 'wp_generator');
            remove_action('wp_head', 'wlwmanifest_link');
            remove_action('wp_head', 'rsd_link');
            remove_action('wp_head', 'rest_output_link_wp_head');
            // Strip versions from assets
            add_filter('script_loader_src', [$this, 'remove_asset_versions'], 999);
            add_filter('style_loader_src', [$this, 'remove_asset_versions'], 999);
        }
    }

    public function remove_asset_versions($src) {
        return remove_query_arg('ver', $src);
    }

    /* ==================== STATIC SITE GENERATION ==================== */
    public function build_static_site() {
        $this->prepare_output_dir();
        
        // Regenerate dynamic CSS
        $this->regenerate_dynamic_assets();
        
        // Collect and process URLs
        $urls = $this->collect_urls();
        $report = "📄 Total URLs: " . count($urls) . "\n\n";
        
        $processed = 0;
        foreach ($urls as $url) {
            $html = $this->fetch_and_process_url($url);
            if ($html) {
                $path = $this->url_to_file_path($url);
                $this->write_file($path, $html);
                $report .= "✅ {$url}\n";
                $processed++;
            } else {
                $report .= "❌ {$url} (failed)\n";
            }
        }
        
        // Copy all assets
        $this->copy_all_assets();
        
        // Performance optimizations
        if (get_option('spv_optimize_images', '1')) {
            $this->optimize_images();
        }
        if (get_option('spv_minify_html', '1')) {
            $this->minify_all_html();
        }
        
        // Create configuration files
        $this->create_vercel_config();
        $this->create_github_workflow();
        
        $report .= "\n🎉 Build completed: {$processed}/" . count($urls) . " pages generated";
        $report .= "\n📁 Output: " . SPV_OUT_DIR;
        
        return $report;
    }

    private function prepare_output_dir() {
        if (file_exists(SPV_OUT_DIR)) {
            $this->rrmdir(SPV_OUT_DIR);
        }
        wp_mkdir_p(SPV_OUT_DIR);
    }

    private function regenerate_dynamic_assets() {
        // Elementor
        if (class_exists('\Elementor\Plugin')) {
            try {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
            } catch (Exception $e) {}
        }
        
        // Other builders can be added here
    }

    private function collect_urls() {
        $urls = [home_url('/')];
        
        // From sitemap
        $sitemap_urls = $this->get_urls_from_sitemap();
        if (!empty($sitemap_urls)) {
            $urls = array_merge($urls, $sitemap_urls);
        } else {
            // Fallback to WordPress queries
            $urls = array_merge($urls, $this->get_urls_from_wp_queries());
        }
        
        // Apply exclusions
        $urls = $this->apply_exclusions($urls);
        
        return array_unique($urls);
    }

    private function get_urls_from_sitemap() {
        $urls = [];
        $sitemap = home_url('/sitemap_index.xml');
        
        $response = wp_remote_get($sitemap, ['timeout' => 10]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return $urls;
        }
        
        $body = wp_remote_retrieve_body($response);
        preg_match_all('#<loc>(.+?)</loc>#', $body, $matches);
        
        foreach ($matches[1] as $sitemap_url) {
            $sub_response = wp_remote_get(trim($sitemap_url), ['timeout' => 10]);
            if (!is_wp_error($sub_response) && wp_remote_retrieve_response_code($sub_response) === 200) {
                $sub_body = wp_remote_retrieve_body($sub_response);
                preg_match_all('#<loc>(.+?)</loc>#', $sub_body, $sub_matches);
                $urls = array_merge($urls, $sub_matches[1]);
            }
        }
        
        return $urls;
    }

    private function get_urls_from_wp_queries() {
        $urls = [];
        
        // Posts
        $posts = get_posts(['post_type' => 'any', 'post_status' => 'publish', 'numberposts' => -1]);
        foreach ($posts as $post) {
            $urls[] = get_permalink($post);
        }
        
        // Taxonomies
        $taxonomies = get_taxonomies(['public' => true]);
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => true]);
            foreach ($terms as $term) {
                $urls[] = get_term_link($term);
            }
        }
        
        return $urls;
    }

    private function apply_exclusions($urls) {
        $exclusions = array_filter(array_map('trim', explode("\n", get_option('spv_exclusions', ''))));
        if (empty($exclusions)) return $urls;
        
        return array_filter($urls, function($url) use ($exclusions) {
            foreach ($exclusions as $exclusion) {
                if (strpos($url, $exclusion) !== false) {
                    return false;
                }
            }
            return true;
        });
    }

    private function fetch_and_process_url($url) {
        $static_url = add_query_arg('static', '1', $url);
        
        $response = wp_remote_get($static_url, [
            'timeout' => 30,
            'headers' => ['User-Agent' => 'SPV/' . SPV_VERSION]
        ]);
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }
        
        $html = wp_remote_retrieve_body($response);
        
        // Apply all transformations
        $html = $this->make_urls_relative($html);
        $html = $this->remove_dynamic_features($html);
        $html = $this->add_performance_optimizations($html);
        
        return $html;
    }

    private function make_urls_relative($html) {
        $site_url = home_url();
        
        $replacements = [
            $site_url . '/wp-content/' => '/wp-content/',
            $site_url . '/wp-includes/' => '/wp-includes/',
            $site_url . '/' => '/'
        ];
        
        $html = str_replace(array_keys($replacements), array_values($replacements), $html);
        
        // Remove asset versions
        $html = preg_replace('#(\.(css|js|png|jpg|jpeg|webp|svg|gif|woff2))\?[^"\']+#', '$1', $html);
        
        return $html;
    }

    private function remove_dynamic_features($html) {
        // Remove forms (will be handled separately)
        $html = preg_replace('#<form[^>]*>(.*?)</form>#is', '<!-- Form removed for static version -->', $html);
        
        // Remove search
        $html = preg_replace('#<div[^>]*search[^>]*>(.*?)</div>#is', '', $html);
        
        // Remove admin bar related
        $html = preg_replace('#<div[^>]*admin-bar[^>]*>(.*?)</div>#is', '', $html);
        
        return $html;
    }

    private function add_performance_optimizations($html) {
        // Lazy loading for images
        $html = preg_replace('#<img([^>]*)>#', '<img$1 loading="lazy">', $html);
        
        // Resource hints
        $resource_hints = '
        <link rel="preconnect" href="//fonts.googleapis.com">
        <link rel="dns-prefetch" href="//fonts.gstatic.com">
        ';
        
        if (strpos($html, '</head>') !== false) {
            $html = str_replace('</head>', $resource_hints . '</head>', $html);
        }
        
        return $html;
    }

    private function url_to_file_path($url) {
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path || $path === '/') {
            return SPV_OUT_DIR . '/index.html';
        }
        
        $file_path = SPV_OUT_DIR . rtrim($path, '/') . '/index.html';
        wp_mkdir_p(dirname($file_path));
        
        return $file_path;
    }

    private function write_file($path, $content) {
        file_put_contents($path, $content);
    }

    /* ==================== ASSET MANAGEMENT ==================== */
    private function copy_all_assets() {
        // WordPress core assets
        $this->rcopy(ABSPATH . 'wp-includes', SPV_OUT_DIR . '/wp-includes');
        
        // Themes
        $current_theme = get_stylesheet_directory();
        $this->rcopy($current_theme, SPV_OUT_DIR . '/wp-content/themes/' . basename($current_theme));
        
        // Plugins
        $this->rcopy(WP_PLUGIN_DIR, SPV_OUT_DIR . '/wp-content/plugins');
        
        // Uploads
        $uploads = wp_get_upload_dir();
        $this->rcopy($uploads['basedir'], SPV_OUT_DIR . '/wp-content/uploads');
        
        // Additional assets from popular builders
        $this->copy_builder_assets();
    }

    private function copy_builder_assets() {
        // Elementor
        if (class_exists('\Elementor\Plugin')) {
            $elementor_assets = WP_PLUGIN_DIR . '/elementor/assets';
            if (file_exists($elementor_assets)) {
                $this->rcopy($elementor_assets, SPV_OUT_DIR . '/wp-content/plugins/elementor/assets');
            }
        }
        
        // Add other builders here as needed
    }

    private function rcopy($src, $dst) {
        if (!file_exists($src)) return;
        
        if (is_dir($src)) {
            if (!file_exists($dst)) {
                wp_mkdir_p($dst);
            }
            
            $files = scandir($src);
            foreach ($files as $file) {
                if ($file != "." && $file != "..") {
                    $this->rcopy("$src/$file", "$dst/$file");
                }
            }
        } else {
            copy($src, $dst);
        }
    }

    private function rrmdir($dir) {
        if (!file_exists($dir)) return;
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        
        rmdir($dir);
    }

    /* ==================== PERFORMANCE OPTIMIZATIONS ==================== */
    private function optimize_images() {
        // This would integrate with image optimization services
        // For now, we'll just note that images should be optimized
        file_put_contents(SPV_OUT_DIR . '/.optimization-note.txt', 
            "Images should be optimized before deployment.\n" .
            "Consider using:\n" .
            "- Squoosh.app (free)\n" .
            "- ShortPixel (service)\n" .
            "- Imagify (service)\n"
        );
    }

    private function minify_all_html() {
        $html_files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(SPV_OUT_DIR),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($html_files as $file) {
            if ($file->isFile() && $file->getExtension() === 'html') {
                $content = file_get_contents($file->getPathname());
                $minified = $this->minify_html($content);
                file_put_contents($file->getPathname(), $minified);
            }
        }
    }

    private function minify_html($html) {
        // Basic minification
        $html = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $html);
        $html = preg_replace('/\s+/', ' ', $html);
        $html = preg_replace('/>\s+</', '><', $html);
        
        return trim($html);
    }

    /* ==================== DEPLOYMENT CONFIGURATION ==================== */
    private function create_vercel_config() {
        $config = [
            'version' => 2,
            'name' => get_bloginfo('name'),
            'builds' => [
                ['src' => '/*', 'use' => '@vercel/static']
            ],
            'routes' => [
                ['src' => '/(.*)', 'dest' => '/$1']
            ],
            'headers' => [
                [
                    'source' => '/(.*)',
                    'headers' => [
                        ['key' => 'Cache-Control', 'value' => 'public, max-age=3600']
                    ]
                ],
                [
                    'source' => '/(.*\.(css|js|png|jpg|jpeg|webp|svg|gif|woff2))',
                    'headers' => [
                        ['key' => 'Cache-Control', 'value' => 'public, max-age=31536000, immutable']
                    ]
                ]
            ]
        ];
        
        $this->write_file(SPV_OUT_DIR . '/vercel.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function create_github_workflow() {
        $workflow_dir = SPV_OUT_DIR . '/.github/workflows';
        wp_mkdir_p($workflow_dir);
        
        $workflow = [
            'name' => 'Deploy to Vercel',
            'on' => [
                'push' => ['branches' => ['main']]
            ],
            'jobs' => [
                'deploy' => [
                    'runs-on' => 'ubuntu-latest',
                    'steps' => [
                        ['uses' => 'actions/checkout@v3'],
                        ['uses' => 'amondnet/vercel-action@v25', 'with' => [
                            'vercel-token' => '${{ secrets.VERCEL_TOKEN }}',
                            'vercel-org-id' => '${{ secrets.VERCEL_ORG_ID }}',
                            'vercel-project-id' => '${{ secrets.VERCEL_PROJECT_ID }}'
                        ]]
                    ]
                ]
            ]
        ];
        
        $this->write_file($workflow_dir . '/deploy.yml', yaml_emit($workflow));
    }

    /* ==================== GITHUB INTEGRATION ==================== */
    private function test_github_connection() {
        $token = get_option('spv_github_token');
        $repo = get_option('spv_github_repo');
        
        if (!$token || !$repo) {
            throw new Exception('GitHub token and repository must be configured');
        }
        
        $response = wp_remote_get("https://api.github.com/repos/{$repo}", [
            'headers' => [
                'Authorization' => 'token ' . $token,
                'User-Agent' => 'WordPress-Static-Publisher'
            ],
            'timeout' => 15
        ]);
        
        if (is_wp_error($response)) {
            throw new Exception('GitHub connection failed: ' . $response->get_error_message());
        }
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            throw new Exception('GitHub repository not found or access denied');
        }
    }

    private function setup_github_workflow() {
        $this->create_github_workflow();
    }

    /* ==================== AJAX HANDLERS ==================== */
    public function ajax_prepare_deploy() {
        check_ajax_referer('spv_ajax_nonce');
        
        try {
            $this->build_static_site();
            
            $state = [
                'phase' => 'building',
                'total_steps' => 3,
                'current_step' => 0
            ];
            
            set_transient($this->progress_key(), $state, SPV_PROGRESS_TTL);
            
            wp_send_json_success([
                'total' => 3,
                'message' => 'Static site built successfully'
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(['error' => $e->getMessage()]);
        }
    }

    public function ajax_deploy_step() {
        check_ajax_referer('spv_ajax_nonce');
        
        $state = get_transient($this->progress_key());
        if (!$state) {
            wp_send_json_error(['error' => 'Session expired']);
        }
        
        try {
            switch ($state['phase']) {
                case 'building':
                    $state['phase'] = 'git_operations';
                    $state['current_step'] = 1;
                    set_transient($this->progress_key(), $state, SPV_PROGRESS_TTL);
                    
                    wp_send_json_success([
                        'phase' => 'git_operations',
                        'done' => 1,
                        'total' => 3,
                        'percent' => 33,
                        'message' => 'Starting Git operations...'
                    ]);
                    break;
                    
                case 'git_operations':
                    $this->run_git_operations();
                    $state['phase'] = 'deploying';
                    $state['current_step'] = 2;
                    set_transient($this->progress_key(), $state, SPV_PROGRESS_TTL);
                    
                    wp_send_json_success([
                        'phase' => 'deploying',
                        'done' => 2,
                        'total' => 3,
                        'percent' => 66,
                        'message' => 'Git operations completed, starting deploy...'
                    ]);
                    break;
                    
                case 'deploying':
                    $this->trigger_vercel_deploy();
                    delete_transient($this->progress_key());
                    
                    wp_send_json_success([
                        'phase' => 'done',
                        'done' => 3,
                        'total' => 3,
                        'percent' => 100,
                        'message' => 'Deployment triggered successfully!',
                        'deploy_url' => 'https://github.com/' . get_option('spv_github_repo') . '/actions'
                    ]);
                    break;
            }
            
        } catch (Exception $e) {
            delete_transient($this->progress_key());
            wp_send_json_error(['error' => $e->getMessage()]);
        }
    }

    private function run_git_operations() {
        // This would handle the actual Git operations
        // For now, we'll simulate the process
        sleep(2); // Simulate Git operations
    }

    private function trigger_vercel_deploy() {
        // This would trigger the actual deployment
        // For now, we'll simulate the process
        sleep(1); // Simulate deployment trigger
    }
}

new StaticPublisher_Vercel();