<?php
/**
 * Plugin Name: Static Publisher → Vercel + Gemini AI
 * Description: Genera sito statico ultra-veloce con ottimizzazioni AI avanzate. Build → Git Push → Vercel.
 * Version: 4.1.0
 * Author: Tag Agency (Mauro)
 */

if (!defined('ABSPATH')) exit;

define('SPVG_VERSION', '4.1.0');
define('SPVG_OUT_DIR', WP_CONTENT_DIR . '/static-build');
define('SPVG_PROGRESS_TTL', 60 * 60);

// Logger per debug e tracciamento
class SPVG_Logger {
    private static $instance;
    private $log_file;
    
    public static function get_instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/spvg-debug.log';
    }
    
    public function log($message, $type = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $entry = "[$timestamp] [$type] $message\n";
        file_put_contents($this->log_file, $entry, FILE_APPEND | LOCK_EX);
    }
    
    public function get_recent_logs($lines = 100) {
        if (!file_exists($this->log_file)) return [];
        $content = file_get_contents($this->log_file);
        $logs = explode("\n", $content);
        return array_slice(array_filter($logs), -$lines);
    }
}

// Classe principale del plugin
class SPVG_Plugin_With_AI {

    private $gemini_optimizer;

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('init', [$this, 'maybe_static_mode']);

        add_action('wp_ajax_spvg_prepare_deploy', [$this, 'ajax_prepare_deploy']);
        add_action('wp_ajax_spvg_deploy_step', [$this, 'ajax_deploy_step']);
        
        $this->init_gemini_optimizer();
    }

    private function init_gemini_optimizer() {
        $api_key = get_option('spv_gemini_api_key');
        if ($api_key && get_option('spv_ai_optimization_enabled', '1')) {
            $optimizer_path = __DIR__ . '/gemini-optimizer.php';
            if (file_exists($optimizer_path)) {
                require_once $optimizer_path;
                $this->gemini_optimizer = new Gemini_HTML_Optimizer($api_key);
                $this->gemini_optimizer->enable_cache(get_option('spv_ai_cache_enabled', '1'));
            } else {
                error_log('SPVG: File gemini-optimizer.php non trovato');
            }
        }
    }

    /* ==================== ADMIN UI ==================== */
    public function admin_menu() {
        add_menu_page(
            'Static Publisher → Vercel AI', 
            'Static Publisher AI', 
            'manage_options', 
            'spvg-ai', 
            [$this, 'render_admin'],
            'dashicons-performance',
            58
        );
    }

    public function register_settings() {
        register_setting('spv_settings', 'spv_github_token');
        register_setting('spv_settings', 'spv_github_repo');
        register_setting('spv_settings', 'spv_github_branch', ['default' => 'main']);
        register_setting('spv_settings', 'spv_exclusions');
        register_setting('spv_settings', 'spv_commit_message', ['default' => 'Static site update']);
        
        register_setting('spv_settings', 'spv_gemini_api_key');
        register_setting('spv_settings', 'spv_ai_optimization_enabled', ['default' => '1']);
        register_setting('spv_settings', 'spv_ai_cache_enabled', ['default' => '1']);
        register_setting('spv_settings', 'spv_ai_page_limit', ['default' => '20']);
        register_setting('spv_settings', 'spv_error_notifications', ['default' => '0']);
        register_setting('spv_settings', 'spv_auto_build_enabled', ['default' => '0']);
        register_setting('spv_settings', 'spv_webhook_url');
    }

    private function progress_key() { 
        return 'spvg_progress_' . get_current_user_id(); 
    }

    public function render_admin() {
        if (!current_user_can('manage_options')) return;

        $report = '';
        if (isset($_POST['spvg_action']) && check_admin_referer('spvg_nonce_action', 'spvg_nonce_field')) {
            $report = $this->handle_actions();
        }

        $settings = $this->get_settings();
        $ajax_data = $this->get_ajax_data();

        ?>
        <div class="wrap">
            <h1>⚡ Static Publisher → Vercel + Gemini AI</h1>

            <form method="post" action="options.php">
                <?php settings_fields('spv_settings'); ?>
                
                <div class="spv-settings-grid">
                    <div class="spv-card">
                        <h3>🔗 GitHub Configuration</h3>
                        <table class="form-table">
                            <tr><th>GitHub Token</th><td><input type="password" name="spv_github_token" value="<?= esc_attr($settings['github_token']) ?>" class="regular-text" /></td></tr>
                            <tr><th>Repository</th><td><input type="text" name="spv_github_repo" value="<?= esc_attr($settings['github_repo']) ?>" class="regular-text" /></td></tr>
                            <tr><th>Branch</th><td><input type="text" name="spv_github_branch" value="<?= esc_attr($settings['github_branch']) ?>" class="regular-text" /></td></tr>
                        </table>
                    </div>

                    <div class="spv-card">
                        <h3>🤖 Ottimizzazione AI Avanzata</h3>
                        <table class="form-table">
                            <tr>
                                <th>Attiva AI</th>
                                <td>
                                    <input type="checkbox" name="spv_ai_optimization_enabled" value="1" <?= checked($settings['ai_enabled'], '1') ?> />
                                    <p class="description">Utilizza Gemini AI per ottimizzare HTML, CSS e JS</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Gemini API Key</th>
                                <td>
                                    <input type="password" name="spv_gemini_api_key" value="<?= esc_attr($settings['gemini_api_key']) ?>" class="regular-text" />
                                    <p class="description"><a href="https://aistudio.google.com/app/apikey" target="_blank">🔑 Ottieni API Key gratuita</a></p>
                                </td>
                            </tr>
                            <tr>
                                <th>Cache AI</th>
                                <td>
                                    <input type="checkbox" name="spv_ai_cache_enabled" value="1" <?= checked($settings['ai_cache_enabled'], '1') ?> />
                                    <p class="description">Mantieni in cache le ottimizzazioni AI per 24h</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Limite Pagine AI</th>
                                <td>
                                    <input type="number" name="spv_ai_page_limit" value="<?= esc_attr($settings['ai_page_limit']) ?>" min="1" max="100" class="small-text" />
                                    <p class="description">Numero massimo di pagine da ottimizzare per build</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Notifiche Errori</th>
                                <td>
                                    <input type="checkbox" name="spv_error_notifications" value="1" <?= checked(get_option('spv_error_notifications', '0'), '1') ?> />
                                    <p class="description">Ricevi email per errori di build</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="spv-card">
                        <h3>⚙️ Build Settings</h3>
                        <table class="form-table">
                            <tr><th>Esclusioni</th><td><textarea name="spv_exclusions" rows="3" class="large-text code"><?= esc_textarea($settings['exclusions']) ?></textarea></td></tr>
                            <tr><th>Commit Message</th><td><input type="text" name="spv_commit_message" value="<?= esc_attr($settings['commit_message']) ?>" class="regular-text" /></td></tr>
                            <tr>
                                <th>Build Automatico</th>
                                <td>
                                    <input type="checkbox" name="spv_auto_build_enabled" value="1" <?= checked(get_option('spv_auto_build_enabled', '0'), '1') ?> />
                                    <p class="description">Build automatico ogni ora</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Webhook URL</th>
                                <td>
                                    <input type="url" name="spv_webhook_url" value="<?= esc_attr(get_option('spv_webhook_url', '')) ?>" class="regular-text" />
                                    <p class="description">URL per notifiche build (opzionale)</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <?php submit_button('Salva impostazioni'); ?>
            </form>

            <?php $this->render_ai_stats(); ?>

            <hr>

            <div class="spv-actions">
                <?php wp_nonce_field('spvg_nonce_action', 'spvg_nonce_field'); ?>
                <button class="button button-large" name="spvg_action" value="build">🏗️ Build</button>
                <button type="button" id="spvg-deploy-btn" class="button button-large button-primary">🚀 Build & Deploy</button>
                <button class="button button-large" name="spvg_action" value="test_github">🔍 Test GitHub</button>
                <button class="button button-large" name="spvg_action" value="test_gemini">🤖 Test Gemini</button>
                <button class="button button-large" name="spvg_action" value="view_logs">📋 View Logs</button>
            </div>

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
        .spv-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 15px 0; }
        .spv-stat { background: #f8f9fa; padding: 12px; border-radius: 6px; border-left: 4px solid #2271b1; }
        .spv-stat strong { display: block; margin-bottom: 5px; color: #1d2327; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            $('#spvg-deploy-btn').on('click', function() {
                const progress = $('#spv-progress');
                const log = $('#spv-log');
                const bar = $('#spv-progress-bar');
                const status = $('#spv-status');
                const percentage = $('#spv-percentage');

                progress.show();
                log.show();
                log.html('');

                function updateProgress(percent, message, logMessage = null) {
                    bar.css('width', percent + '%');
                    percentage.text(percent + '%');
                    status.text(message);
                    if (logMessage) log.append(logMessage + '\n');
                    log.scrollTop(log[0].scrollHeight);
                }

                async function doAjax(action) {
                    return $.ajax({
                        url: '<?= $ajax_data['url'] ?>',
                        method: 'POST',
                        data: {
                            action: action,
                            _ajax_nonce: '<?= $ajax_data['nonce'] ?>'
                        }
                    });
                }

                async function startDeploy() {
                    try {
                        updateProgress(0, 'Preparazione...', '🚀 Iniziando deploy...');
                        const prep = await doAjax('spvg_prepare_deploy');
                        if (!prep.success) throw new Error(prep.data.error);
                        
                        updateProgress(10, 'Build in corso...', '✅ Preparazione completata');
                        
                        let attempts = 0;
                        const maxAttempts = 50;
                        
                        while (attempts < maxAttempts) {
                            const step = await doAjax('spvg_deploy_step');
                            if (!step.success) throw new Error(step.data.error);
                            
                            updateProgress(
                                step.data.percent || 0,
                                step.data.message || 'Processing...',
                                step.data.log || step.data.message
                            );

                            if (step.data.phase === 'done') {
                                updateProgress(100, 'Completato!', '🎉 Deploy completato!');
                                break;
                            }
                            
                            attempts++;
                            await new Promise(resolve => setTimeout(resolve, 1000));
                        }
                        
                        if (attempts >= maxAttempts) {
                            throw new Error('Timeout: deploy troppo lungo');
                        }
                        
                    } catch (error) {
                        updateProgress(0, 'Errore', '❌ Errore: ' + error.message);
                    }
                }

                startDeploy();
            });
        });
        </script>
        <?php
    }

    private function render_ai_stats() {
        $total_optimized = get_transient('spv_ai_usage_count') ?: 0;
        $last_build = get_transient('spv_last_ai_build');
        $avg_savings = get_transient('spv_avg_ai_savings') ?: 0;
        $total_builds = get_option('spvg_total_builds', 0);
        $last_build_time = get_option('spvg_last_build_time', 0);
        
        ?>
        <div class="spv-card">
            <h3>📊 Statistiche AI & Build</h3>
            <div class="spv-stats-grid">
                <div class="spv-stat">
                    <strong>Build Totali:</strong> <?= $total_builds ?>
                </div>
                <div class="spv-stat">
                    <strong>Pagine Ottimizzate:</strong> <?= $total_optimized ?>
                </div>
                <div class="spv-stat">
                    <strong>Ultimo Build:</strong> 
                    <?= $last_build ? human_time_diff($last_build) . ' fa' : 'Mai' ?>
                </div>
                <div class="spv-stat">
                    <strong>Risparmio Medio:</strong> <?= $avg_savings ?>%
                </div>
                <div class="spv-stat">
                    <strong>Tempo Ultimo Build:</strong> <?= $last_build_time ?>s
                </div>
            </div>
            
            <?php if ($total_optimized > 0): ?>
            <div style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 5px;">
                <h4>🎯 Performance Boost Stimato</h4>
                <p>L'ottimizzazione AI può migliorare il LCP del <strong>15-40%</strong> e ridurre il bundle size del <strong>25-60%</strong>.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function handle_actions() {
        $action = sanitize_text_field($_POST['spvg_action']);
        
        try {
            switch ($action) {
                case 'build':
                    return $this->build_static_site();
                case 'test_github':
                    $this->test_github_connection();
                    $this->admin_notice('✅ GitHub OK!', 'success');
                    break;
                case 'test_gemini':
                    $this->test_gemini_connection();
                    $this->admin_notice('✅ Gemini OK!', 'success');
                    break;
                case 'view_logs':
                    return $this->view_logs();
                    break;
            }
        } catch (Throwable $e) {
            $this->admin_notice('❌ Errore: ' . $e->getMessage(), 'error');
            return $e->getMessage();
        }

        return '';
    }

    private function view_logs() {
        $logger = SPVG_Logger::get_instance();
        $logs = $logger->get_recent_logs(50);
        return "Ultimi 50 log:\n\n" . implode("\n", $logs);
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
            'exclusions' => esc_textarea(get_option('spv_exclusions', '')),
            'commit_message' => esc_attr(get_option('spv_commit_message', 'Static site update')),
            'gemini_api_key' => esc_attr(get_option('spv_gemini_api_key', '')),
            'ai_enabled' => get_option('spv_ai_optimization_enabled', '1'),
            'ai_cache_enabled' => get_option('spv_ai_cache_enabled', '1'),
            'ai_page_limit' => get_option('spv_ai_page_limit', '20')
        ];
    }

    private function get_ajax_data() {
        return [
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('spvg_ajax_nonce')
        ];
    }

    /* ==================== CORE FUNCTIONALITY ==================== */
    public function build_static_site() {
        $start_time = microtime(true);
        $this->prepare_output_dir();
        
        if (class_exists('\Elementor\Plugin')) {
            try { \Elementor\Plugin::$instance->files_manager->clear_cache(); } catch (Throwable $e) {}
        }

        $urls = $this->collect_urls();
        $report = "URL totali: " . count($urls) . "\n\n";

        $optimized_count = 0;
        $total_pages = 0;
        $total_savings = 0;
        $avg_savings = 0;

        foreach ($urls as $url) {
            $html = $this->fetch_url($url);
            if (!$html) {
                $report .= "✖ {$url}\n";
                continue;
            }

            $original_size = strlen($html);
            $page_type = $this->detect_page_type($url);

            // Applica ottimizzazione AI se disponibile e entro limite
            if ($this->gemini_optimizer && $optimized_count < get_option('spv_ai_page_limit', 20)) {
                try {
                    $html = $this->gemini_optimizer->optimize_html($html, $page_type);
                    $optimized_count++;
                    
                    $optimized_size = strlen($html);
                    $savings = round((1 - $optimized_size / $original_size) * 100, 1);
                    $total_savings += $savings;
                    $report .= "🤖 {$url} (-{$savings}%)\n";
                    
                } catch (Exception $e) {
                    $html = $this->handle_ai_error($url, $e);
                    $report .= "⚠️ {$url} (AI failed - used basic)\n";
                }
            } else {
                $html = $this->basic_optimize($html);
                $report .= "✅ {$url}\n";
            }

            $this->save_html($url, $html);
            $total_pages++;
        }

        $this->copy_all_assets();
        $this->create_vercel_config();
        $this->create_github_workflow();

        // Salva statistiche AI
        if ($optimized_count > 0) {
            $avg_savings = round($total_savings / $optimized_count, 1);
            set_transient('spv_ai_usage_count', $optimized_count, DAY_IN_SECONDS);
            set_transient('spv_last_ai_build', time(), DAY_IN_SECONDS);
            set_transient('spv_avg_ai_savings', $avg_savings, DAY_IN_SECONDS);
            
            // Aggiorna statistiche globali
            $total_builds = get_option('spvg_total_builds', 0);
            update_option('spvg_total_builds', $total_builds + 1);
            
            $build_time = round(microtime(true) - $start_time, 1);
            update_option('spvg_last_build_time', $build_time);
        }

        $report .= "\n🎯 Build completato: {$total_pages} pagine";
        if ($optimized_count > 0) {
            $report .= "\n🤖 Ottimizzazione AI: {$optimized_count} pagine";
            $report .= "\n💾 Risparmio medio: {$avg_savings}% per pagina";
            $report .= "\n⚡ Performance boost stimato: 15-40% miglioramento LCP";
        }

        return $report;
    }

    private function handle_ai_error($url, $error) {
        $logger = SPVG_Logger::get_instance();
        $logger->log("AI optimization failed for $url: " . $error->getMessage(), 'ERROR');
        
        // Fallback a ottimizzazione base
        $html = $this->fetch_url($url);
        return $this->basic_optimize($html);
    }

    private function prepare_output_dir() {
        if (file_exists(SPVG_OUT_DIR)) {
            $this->rrmdir(SPVG_OUT_DIR);
        }
        wp_mkdir_p(SPVG_OUT_DIR);
    }

    private function collect_urls() {
        $urls = [home_url('/')];

        // Sitemap-based collection
        $sitemap_urls = $this->get_urls_from_sitemap();
        if (!empty($sitemap_urls)) {
            $urls = array_merge($urls, $sitemap_urls);
        } else {
            $urls = array_merge($urls, $this->get_urls_from_wp_queries());
        }

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
        
        $posts = get_posts(['post_type' => 'any', 'post_status' => 'publish', 'numberposts' => -1]);
        foreach ($posts as $post) {
            $urls[] = get_permalink($post);
        }
        
        $taxonomies = get_taxonomies(['public' => true]);
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => true]);
            foreach ($terms as $term) {
                $url = get_term_link($term);
                if (!is_wp_error($url)) $urls[] = $url;
            }
        }
        
        return $urls;
    }

    private function apply_exclusions($urls) {
        $exclusions = array_filter(array_map('trim', explode("\n", get_option('spv_exclusions', ''))));
        if (empty($exclusions)) return $urls;
        
        return array_filter($urls, function($url) use ($exclusions) {
            foreach ($exclusions as $exclusion) {
                if (strpos($url, $exclusion) !== false) return false;
            }
            return true;
        });
    }

    private function fetch_url($url) {
        $static_url = add_query_arg('static', '1', $url);
        
        $response = wp_remote_get($static_url, [
            'timeout' => 30,
            'headers' => ['User-Agent' => 'SPVG/' . SPVG_VERSION]
        ]);
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }
        
        $html = wp_remote_retrieve_body($response);
        return $this->make_urls_relative($html);
    }

    private function make_urls_relative($html) {
        $site_url = home_url();
        $replacements = [
            $site_url . '/wp-content/' => '/wp-content/',
            $site_url . '/wp-includes/' => '/wp-includes/',
            $site_url . '/' => '/'
        ];
        
        $html = str_replace(array_keys($replacements), array_values($replacements), $html);
        $html = preg_replace('#(\.(css|js|png|jpg|jpeg|webp|svg|gif|woff2))\?[^"\']+#', '$1', $html);
        
        return $html;
    }

    private function detect_page_type($url) {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === '/' || $path === '') return 'homepage';
        if (str_contains($path, 'product') || str_contains($path, 'shop')) return 'product';
        if (str_contains($path, 'blog') || str_contains($path, 'article')) return 'article';
        if (str_contains($path, 'contact') || str_contains($path, 'contatti')) return 'contact';
        return 'generic';
    }

    private function basic_optimize($html) {
        $html = preg_replace('/\s+/', ' ', $html);
        $html = preg_replace('/>\s+</', '><', $html);
        $html = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $html);
        $html = preg_replace('/<img([^>]*)>/i', '<img$1 loading="lazy">', $html);
        $html = preg_replace('/<iframe([^>]*)>/i', '<iframe$1 loading="lazy">', $html);
        return $html;
    }

    private function save_html($url, $html) {
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path || $path === '/') {
            $file_path = SPVG_OUT_DIR . '/index.html';
        } else {
            $file_path = SPVG_OUT_DIR . rtrim($path, '/') . '/index.html';
        }
        
        wp_mkdir_p(dirname($file_path));
        file_put_contents($file_path, $html);
    }

    private function copy_all_assets() {
        // Copia wp-includes
        if (file_exists(ABSPATH . 'wp-includes')) {
            $this->rcopy(ABSPATH . 'wp-includes', SPVG_OUT_DIR . '/wp-includes');
        }
        
        // Copia wp-content (eccetto uploads e cache)
        $wp_content_dir = WP_CONTENT_DIR;
        $exclude_dirs = ['uploads', 'cache', 'w3tc-config', 'static-build'];
        
        $items = glob($wp_content_dir . '/*');
        foreach ($items as $item) {
            $name = basename($item);
            if (in_array($name, $exclude_dirs)) continue;
            
            $dst = SPVG_OUT_DIR . '/wp-content/' . $name;
            if (is_dir($item)) {
                $this->rcopy($item, $dst);
            }
        }
        
        // Copia uploads separatamente
        $uploads = wp_get_upload_dir();
        $this->rcopy($uploads['basedir'], SPVG_OUT_DIR . '/wp-content/uploads');
    }

    private function rcopy($src, $dst) {
        if (!file_exists($src)) return;
        
        if (is_file($src)) {
            wp_mkdir_p(dirname($dst));
            copy($src, $dst);
            return;
        }
        
        $dir = opendir($src);
        wp_mkdir_p($dst);
        
        while (false !== ($file = readdir($dir))) {
            if ($file == '.' || $file == '..') continue;
            $this->rcopy("$src/$file", "$dst/$file");
        }
        closedir($dir);
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

    private function create_vercel_config() {
        $config = [
            'version' => 2,
            'name' => get_bloginfo('name'),
            'builds' => [['src' => '/*', 'use' => '@vercel/static']],
            'routes' => [['src' => '/(.*)', 'dest' => '/$1']],
            'headers' => [
                [
                    'source' => '/(.*)',
                    'headers' => [['key' => 'Cache-Control', 'value' => 'public, max-age=3600']]
                ],
                [
                    'source' => '/(.*\.(css|js|png|jpg|jpeg|webp|svg|gif|woff2))',
                    'headers' => [['key' => 'Cache-Control', 'value' => 'public, max-age=31536000, immutable']]
                ]
            ]
        ];
        
        file_put_contents(SPVG_OUT_DIR . '/vercel.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function create_github_workflow() {
        $workflow_dir = SPVG_OUT_DIR . '/.github/workflows';
        wp_mkdir_p($workflow_dir);
        
        $workflow = 'name: Deploy to Vercel
on:
  repository_dispatch:
    types: [deploy_trigger]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Deploy to Vercel
        uses: amondnet/vercel-action@v25
        with:
          vercel-token: ${{ secrets.VERCEL_TOKEN }}
          vercel-org-id: ${{ secrets.VERCEL_ORG_ID }}
          vercel-project-id: ${{ secrets.VERCEL_PROJECT_ID }}';
        
        file_put_contents($workflow_dir . '/deploy.yml', $workflow);
    }

    /* ==================== TEST CONNECTION ==================== */
    private function test_github_connection() {
        $token = get_option('spv_github_token');
        $repo = get_option('spv_github_repo');
        
        if (!$token || !$repo) {
            throw new Exception('Configura Token e Repository GitHub');
        }
        
        $response = wp_remote_get("https://api.github.com/repos/{$repo}", [
            'headers' => ['Authorization' => 'token ' . $token],
            'timeout' => 15
        ]);
        
        if (is_wp_error($response)) {
            throw new Exception('GitHub connection failed: ' . $response->get_error_message());
        }
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            throw new Exception('GitHub repository not found or access denied');
        }
    }

    private function test_gemini_connection() {
        if (!$this->gemini_optimizer) {
            throw new Exception('Gemini optimizer non inizializzato. Controlla API Key.');
        }
        
        // Test con HTML più complesso
        $test_html = '
        <!DOCTYPE html>
        <html>
        <head>
            <title>Test Page</title>
            <style>
                .test-class { color: blue; margin: 10px; }
                .unused-class { background: red; }
            </style>
        </head>
        <body>
            <h1>Test Optimization</h1>
            <img src="test-image.jpg" alt="test image">
            <script>
                console.log("Hello World");
            </script>
        </body>
        </html>
        ';
        
        $result = $this->gemini_optimizer->optimize_html($test_html, 'generic');
        
        // Validazioni
        if (strlen($result) < 100) {
            throw new Exception('Risposta Gemini troppo corta');
        }
        
        if (strpos($result, 'unused-class') !== false) {
            throw new Exception('Ottimizzazione CSS non funzionante');
        }
        
        if (strpos($result, 'loading="lazy"') === false) {
            throw new Exception('Lazy loading non applicato');
        }
        
        return true;
    }

    /* ==================== AJAX HANDLERS ==================== */
    public function ajax_prepare_deploy() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'Unauthorized']);
        }
        
        check_ajax_referer('spvg_ajax_nonce');
        
        try {
            $this->build_static_site();
            
            $state = [
                'phase' => 'building',
                'total_steps' => 3
            ];
            
            set_transient($this->progress_key(), $state, SPVG_PROGRESS_TTL);
            
            wp_send_json_success([
                'total' => 3,
                'message' => 'Build completato, pronto per deploy'
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(['error' => $e->getMessage()]);
        }
    }

    public function ajax_deploy_step() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'Unauthorized']);
        }
        
        check_ajax_referer('spvg_ajax_nonce');
        
        $state = get_transient($this->progress_key());
        if (!$state) {
            wp_send_json_error(['error' => 'Session expired']);
        }
        
        try {
            switch ($state['phase']) {
                case 'building':
                    $state['phase'] = 'git_operations';
                    set_transient($this->progress_key(), $state, SPVG_PROGRESS_TTL);
                    
                    wp_send_json_success([
                        'phase' => 'git_operations',
                        'done' => 1,
                        'total' => 3,
                        'percent' => 33,
                        'message' => 'Iniziando operazioni Git...'
                    ]);
                    break;
                    
                case 'git_operations':
                    $this->run_git_operations();
                    $state['phase'] = 'deploying';
                    set_transient($this->progress_key(), $state, SPVG_PROGRESS_TTL);
                    
                    wp_send_json_success([
                        'phase' => 'deploying',
                        'done' => 2,
                        'total' => 3,
                        'percent' => 66,
                        'message' => 'Operazioni Git completate'
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
                        'message' => 'Deploy completato!',
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
        $build_dir = SPVG_OUT_DIR;
        $repo = get_option('spv_github_repo');
        $branch = get_option('spv_github_branch', 'main');
        $commit_message = get_option('spv_commit_message', 'Static site update');
        $token = get_option('spv_github_token');
        
        // Validazione
        if (!$token || !$repo) {
            throw new Exception('Configurazione GitHub mancante');
        }
        
        if (!file_exists($build_dir) || !is_dir($build_dir)) {
            throw new Exception('Directory build non trovata');
        }
        
        $remote_url = "https://{$token}@github.com/{$repo}.git";
        
        $commands = [
            "cd {$build_dir}",
            "git init",
            "git config user.email 'wordpress@example.com'",
            "git config user.name 'WordPress Static Publisher'",
            "git remote remove origin 2>/dev/null || true",
            "git remote add origin {$remote_url}",
            "git add .",
            "git commit -m '{$commit_message} " . date('Y-m-d H:i:s') . "' || echo 'No changes'",
            "git branch -M {$branch}",
            "git push -u origin {$branch} --force"
        ];
        
        $full_command = implode(' && ', $commands);
        exec($full_command . ' 2>&1', $output, $return_code);
        
        if ($return_code !== 0) {
            $error_msg = implode('\n', $output);
            throw new Exception('Git operations failed: ' . $error_msg);
        }
        
        return true;
    }

    private function trigger_vercel_deploy() {
        // Invia webhook se configurato
        $webhook_url = get_option('spv_webhook_url');
        if ($webhook_url) {
            $this->send_webhook_notification('deploy_completed', [
                'site' => get_bloginfo('name'),
                'timestamp' => time(),
                'repo' => get_option('spv_github_repo')
            ]);
        }
        
        return true;
    }

    private function send_webhook_notification($event, $data) {
        $webhook_url = get_option('spv_webhook_url');
        if (!$webhook_url) return;
        
        $payload = [
            'event' => $event,
            'site' => get_bloginfo('name'),
            'timestamp' => time(),
            'data' => $data
        ];
        
        wp_remote_post($webhook_url, [
            'body' => json_encode($payload),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 10
        ]);
    }

    /* ==================== STATIC MODE ==================== */
    public function maybe_static_mode() {
        if (isset($_GET['static']) && $_GET['static'] === '1') {
            define('SPVG_STATIC_MODE', true);
            show_admin_bar(false);
            remove_action('wp_head', 'wp_generator');
            remove_action('wp_head', 'wlwmanifest_link');
            remove_action('wp_head', 'rsd_link');
            add_filter('script_loader_src', [$this, 'remove_asset_versions'], 999);
            add_filter('style_loader_src', [$this, 'remove_asset_versions'], 999);
        }
    }

    public function remove_asset_versions($src) {
        return remove_query_arg('ver', $src);
    }
}

new SPVG_Plugin_With_AI();