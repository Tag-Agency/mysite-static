<?php
/**
 * Plugin Name: Static Publisher → GitHub → Vercel (PRODUCTION FIXED)
 * Description: Versione corretta con fix CSS/JS. Build → Git Push → GitHub Actions → Vercel.
 * Version: 3.1.0
 * Author: Tag Agency (Mauro)
 */

if (!defined('ABSPATH')) exit;

define('SPVG_VERSION', '3.1.0');
define('SPVG_OUT_DIR', WP_CONTENT_DIR . '/static-build');
define('SPVG_PROGRESS_TTL', 60 * 60); // 1h

class SPVG_Plugin {

  public function __construct() {
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_init', [$this, 'register_settings']);
    add_action('init',       [$this, 'maybe_static_mode']);

    // AJAX semplificato
    add_action('wp_ajax_spvg_prepare_deploy', [$this, 'ajax_prepare_deploy']);
    add_action('wp_ajax_spvg_deploy_step',    [$this, 'ajax_deploy_step']);
  }

  /* ----------------- Admin UI ----------------- */
  public function admin_menu() {
    add_menu_page('Static → Vercel PRO', 'Static → Vercel PRO', 'manage_options', 'spvg', [$this, 'render_admin'], 'dashicons-cloud-upload', 58);
  }

  public function register_settings() {
    register_setting('spvg_settings', 'spvg_github_token');
    register_setting('spvg_settings', 'spvg_github_repo');
    register_setting('spvg_settings', 'spvg_github_branch', ['default' => 'main']);
    register_setting('spvg_settings', 'spvg_exclusions');
    register_setting('spvg_settings', 'spvg_commit_message', ['default' => 'Static site update']);
  }

  private function progress_key() { return 'spvg_progress_' . get_current_user_id(); }

  public function render_admin() {
    if (!current_user_can('manage_options')) return;

    $report = '';
    if (isset($_POST['spvg_action']) && check_admin_referer('spvg_nonce_action', 'spvg_nonce_field')) {
      $action = sanitize_text_field($_POST['spvg_action']);
      try {
        if ($action === 'build') {
          $report = $this->build();
          $this->admin_notice('Build completata.', 'success');
        } elseif ($action === 'test_github') {
          $this->test_github_connection();
          $this->admin_notice('✅ Connessione GitHub riuscita!', 'success');
        } elseif ($action === 'setup_workflow') {
          $this->setup_github_workflow();
          $this->admin_notice('Workflow GitHub Actions creato.', 'success');
        }
      } catch (Throwable $e) {
        $report = $e->getMessage();
        $this->admin_notice('Errore: ' . esc_html($e->getMessage()), 'error');
      }
    }

    $github_token = esc_attr(get_option('spvg_github_token', ''));
    $github_repo = esc_attr(get_option('spvg_github_repo', ''));
    $github_branch = esc_attr(get_option('spvg_github_branch', 'main'));
    $exclusions = esc_textarea(get_option('spvg_exclusions', ''));
    $commit_msg = esc_attr(get_option('spvg_commit_message', 'Static site update'));

    $ajax_nonce = wp_create_nonce('spvg_ajax_nonce');
    $ajax_url = admin_url('admin-ajax.php');

    ?>
    <div class="wrap">
      <h1>Static Publisher → Vercel PRO</h1>
      <p><strong>🚀 Versione Production FIXED:</strong> Build → Git Push → Deploy Automatico</p>

      <form method="post" action="options.php" style="margin-top:1rem;">
        <?php settings_fields('spvg_settings'); ?>
        <h2>Configurazione GitHub</h2>
        <table class="form-table" role="presentation">
          <tr><th scope="row">GitHub Token</th><td><input type="password" name="spvg_github_token" value="<?php echo $github_token; ?>" class="regular-text" placeholder="ghp_..." /></td></tr>
          <tr><th scope="row">Repository</th><td><input type="text" name="spvg_github_repo" value="<?php echo $github_repo; ?>" class="regular-text" placeholder="username/repository" /></td></tr>
          <tr><th scope="row">Branch</th><td><input type="text" name="spvg_github_branch" value="<?php echo $github_branch; ?>" class="regular-text" placeholder="main" /></td></tr>
          <tr><th scope="row">Commit Message</th><td><input type="text" name="spvg_commit_message" value="<?php echo $commit_msg; ?>" class="regular-text" /></td></tr>
        </table>

        <h2>Configurazione Build</h2>
        <table class="form-table" role="presentation">
          <tr><th scope="row">Esclusioni (uno per riga)</th><td><textarea name="spvg_exclusions" rows="5" class="large-text code" placeholder="/area-riservata/&#10;/bozze/"><?php echo $exclusions; ?></textarea></td></tr>
        </table>
        <?php submit_button('Salva impostazioni'); ?>
      </form>

      <hr>
      <form method="post">
        <?php wp_nonce_field('spvg_nonce_action', 'spvg_nonce_field'); ?>
        <p style="display:flex;gap:8px;flex-wrap:wrap;">
          <button class="button" name="spvg_action" value="build">Build Statica</button>
          <button type="button" id="spvg-start-deploy" class="button button-primary">🚀 DEPLOY COMPLETO</button>
          <button class="button" name="spvg_action" value="test_github">🔍 Test GitHub</button>
          <button class="button" name="spvg_action" value="setup_workflow">⚙️ Setup Workflow</button>
        </p>
      </form>

      <!-- Progress UI -->
      <div id="spvg-progress-wrap" style="display:none;max-width:680px;">
        <div style="margin:12px 0;">Stato: <strong id="spvg-status-text">—</strong></div>
        <div style="width:100%;background:#eee;border:1px solid #ccc;border-radius:4px;height:20px;overflow:hidden;">
          <div id="spvg-bar" style="height:100%;width:0%;background:#2271b1;transition:width .2s;"></div>
        </div>
        <div style="margin-top:6px;">Fase: <span id="spvg-phase">0/3</span> - <span id="spvg-perc">0%</span></div>
        <pre id="spvg-log" style="margin-top:10px;max-height:260px;overflow:auto;background:#fff;border:1px solid #ccd;padding:10px;"></pre>
      </div>

      <?php if ($report): ?>
        <h2>Report</h2>
        <pre style="max-height:320px;overflow:auto;background:#fff;border:1px solid #ccd;padding:12px;"><?php echo esc_html($report); ?></pre>
      <?php endif; ?>

      <div style="margin-top:2rem;padding:1rem;background:#f0f8ff;border-left:4px solid #2271b1;">
        <h3>📋 Flusso di Deploy:</h3>
        <ol>
          <li><strong>Build</strong> - Genera file statici</li>
          <li><strong>Git Operations</strong> - Commit e push</li>
          <li><strong>Deploy Trigger</strong> - Avvia GitHub Actions</li>
          <li><strong>Vercel Deploy</strong> - Automatico via Actions</li>
        </ol>
      </div>
    </div>

    <script>
    (function(){
      const btn = document.getElementById('spvg-start-deploy');
      if(!btn) return;

      const wrap  = document.getElementById('spvg-progress-wrap');
      const bar   = document.getElementById('spvg-bar');
      const perc  = document.getElementById('spvg-perc');
      const phase = document.getElementById('spvg-phase');
      const stat  = document.getElementById('spvg-status-text');
      const logEl = document.getElementById('spvg-log');

      const AJAX  = "<?php echo esc_js($ajax_url); ?>";
      const NONCE = "<?php echo esc_js($ajax_nonce); ?>";

      function log(msg){ logEl.textContent += msg + "\n"; logEl.scrollTop = logEl.scrollHeight; }
      function setProg(p, ph, s){
        bar.style.width = p + '%';
        perc.textContent = p + '%';
        phase.textContent = ph;
        stat.textContent = s || '';
      }

      async function post(action, data = {}) {
        const form = new FormData();
        form.append('action', action);
        form.append('_ajax_nonce', NONCE);
        for (const k in data) form.append(k, data[k]);
        const res = await fetch(AJAX, { method:'POST', body: form, credentials:'same-origin' });
        let json;
        try { json = await res.json(); } catch(e){ json = null; }
        if (!res.ok || !json || json.success !== true) {
          const msg = (json && json.data && json.data.error) ? json.data.error : ('HTTP ' + res.status);
          throw new Error(msg);
        }
        return json.data;
      }

      async function start(){
        wrap.style.display = 'block';
        logEl.textContent = '';
        setProg(0, '0/3', 'Preparazione…');
        
        try {
          // Inizia il deploy
          const prep = await post('spvg_prepare_deploy');
          log('✅ Preparazione completata');
          
          // Loop attraverso le fasi
          let finished = false;
          while(!finished){
            const step = await post('spvg_deploy_step');
            setProg(step.percent, step.done + '/' + step.total, step.message);
            
            if (step.message) {
              log('📦 ' + step.message);
            }
            
            if (step.output) {
              log('🔧 Output: ' + step.output.join('\n'));
            }
            
            if (step.phase === 'done'){
              log('🎉 DEPLOY COMPLETATO!');
              log('🚀 GitHub Actions avviato: ' + step.deploy_url);
              log('📊 Monitora il deploy su GitHub Actions');
              setProg(100, '3/3', 'Completato');
              finished = true;
            }
          }
        } catch(e) {
          log('❌ ERRORE: ' + e.message);
          setProg(0, 'Errore', 'Fallito');
        }
      }

      btn.addEventListener('click', start);
    })();
    </script>
    <?php
  }

  private function admin_notice($msg, $type='success') {
    printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), $msg);
  }

  /* ----------------- Static mode ----------------- */
  public function maybe_static_mode() {
    if (isset($_GET['static']) && $_GET['static'] == '1') {
      if (!defined('SPVG_STATIC_MODE')) define('SPVG_STATIC_MODE', true);
      show_admin_bar(false);
      add_filter('script_loader_src', [$this, 'strip_ver'], 999);
      add_filter('style_loader_src',  [$this, 'strip_ver'], 999);
    }
  }
  
  public function strip_ver($src) { 
    $parts = explode('?', $src); 
    return $parts[0]; 
  }

  /* ----------------- Build statica - CORRETTA ----------------- */
  public function build() {
    $this->prepare_out();

    // Rigenera CSS Elementor
    if (class_exists('\Elementor\Plugin')) {
      try { 
        \Elementor\Plugin::$instance->files_manager->clear_cache(); 
      } catch (Throwable $e) {}
    }

    $urls = $this->collect_urls();
    $exclusions = array_filter(array_map('trim', explode("\n", (string) get_option('spvg_exclusions', ''))));
    if ($exclusions) {
      $urls = array_values(array_filter($urls, function($u) use ($exclusions){
        $path = parse_url($u, PHP_URL_PATH) ?: '';
        foreach ($exclusions as $ex) {
          $ex = rtrim($ex, "/");
          if ($ex && $this->starts_with($path, $ex)) return false;
        }
        return true;
      }));
    }

    $report = "URL totali: ".count($urls)."\n";

    foreach ($urls as $url) {
      $html = $this->fetch(add_query_arg('static', '1', $url));
      if ($html === false) { 
        $report .= "✖ ".$url."\n"; 
        continue; 
      }
      $path = $this->url_to_path($url);
      $this->write_file($path, $html);
      $report .= "✔ ".$url." → ".$path."\n";
    }

    // COPIA TUTTI GLI ASSETS PRIMA
    $this->copy_all_assets();
    $this->copy_uploads();
    $this->copy_elementor_assets();

    // file extra
    $this->write_file(SPVG_OUT_DIR.'/404.html', $this->basic_404());
    $this->write_vercel_json();

    return $report;
  }

  private function prepare_out() { 
    if (!file_exists(SPVG_OUT_DIR)) wp_mkdir_p(SPVG_OUT_DIR); 
  }

  private function collect_urls() {
    $urls = [];
    // 1) home
    $urls[] = home_url('/');

    // 2) sitemap index
    $index = home_url('/sitemap_index.xml');
    $resp  = wp_remote_get($index, ['timeout'=>20]);
    if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
      $body = wp_remote_retrieve_body($resp);
      preg_match_all('#<loc>(.+?)</loc>#', $body, $midx);
      foreach ($midx[1] as $smurl) {
        $smurl = trim($smurl);
        if (!$smurl) continue;
        $r = wp_remote_get($smurl, ['timeout'=>20]);
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) continue;
        $b = wp_remote_retrieve_body($r);
        preg_match_all('#<loc>(.+?)</loc>#', $b, $ment);
        foreach ($ment[1] as $loc) {
          $u = trim($loc);
          if ($u) $urls[] = $u;
        }
      }
    }

    // 3) fallback: query WP
    if (count($urls) <= 1) {
      $q = new WP_Query([
        'post_type' => get_post_types(['public' => true]),
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids'
      ]);
      foreach ($q->posts as $id) $urls[] = get_permalink($id);

      $taxes = get_taxonomies(['public' => true], 'objects');
      foreach ($taxes as $tx) {
        $terms = get_terms(['taxonomy' => $tx->name, 'hide_empty' => true]);
        if (!is_wp_error($terms)) {
          foreach ($terms as $t) {
            $link = get_term_link($t);
            if (!is_wp_error($link)) $urls[] = $link;
          }
        }
      }
    }

    // canonicalizza
    $urls = array_values(array_unique(array_map(function($u){
      $u = preg_replace('#\?.*$#', '', $u);
      return trailingslashit($u);
    }, $urls)));

    return $urls;
  }

  private function fetch($url) {
    $args = [
      'timeout' => 30, 
      'redirection' => 5, 
      'headers' => ['User-Agent' => 'SPVG/'.SPVG_VERSION], 
      'cookies' => []
    ];
    
    $r = wp_remote_get($url, $args);
    if (is_wp_error($r)) return false;
    if (wp_remote_retrieve_response_code($r) !== 200) return false;
    
    $html = wp_remote_retrieve_body($r);
    
    // APPLICA TUTTI I FIX PER CSS/JS
    $html = $this->rewrite_urls($html);
    $html = $this->fix_asset_paths($html);
    
    return $html;
  }

  /* ----------------- FIX CRITICI PER CSS/JS ----------------- */
  private function rewrite_urls($html) {
    $site_url = preg_quote(home_url(), '#');
    $wp_content = preg_quote(content_url(), '#');
    $wp_includes = preg_quote(includes_url(), '#');
    
    // Sostituzioni multiple per coprire tutti i casi
    $replacements = [
      // URL assoluti → relativi
      '#'.$site_url.'/#i' => '/',
      '#'.$wp_content.'#i' => '/wp-content',
      '#'.$wp_includes.'#i' => '/wp-includes',
      
      // Rimuovi versioning
      '#(\.(?:css|js|png|jpg|jpeg|webp|svg|gif|woff|woff2))\?[^"\']+#i' => '$1',
      
      // Fix per URL che iniziano con //
      '#"//#i' => '"https://',
      "#'//#i" => "'https://",
    ];
    
    foreach ($replacements as $pattern => $replacement) {
      $html = preg_replace($pattern, $replacement, $html);
    }
    
    return $html;
  }

  private function fix_asset_paths($html) {
    // Fix per CSS url() references
    $html = preg_replace_callback(
        '#url\([\'"]?(/[^\'")]+)[\'"]?\)#i',
        function($matches) {
            return 'url(".' . $matches[1] . '")';
        },
        $html
    );
    
    // Fix per src e href che iniziano con /
    $html = preg_replace_callback(
        '#(src|href)=[\'"](/[^\'"]+)[\'"]#i',
        function($matches) {
            return $matches[1] . '=".' . $matches[2] . '"';
        },
        $html
    );

    // Fix per background images in style attributes
    $html = preg_replace_callback(
        '#style=[\'"][^\'"]*url\(([^)]+)\)[^\'"]*[\'"]#i',
        function($matches) {
            $fixed = preg_replace('#url\(([^)]+)\)#i', 'url(.$1)', $matches[0]);
            return $fixed;
        },
        $html
    );
    
    return $html;
  }

  private function url_to_path($url) {
    $path = parse_url($url, PHP_URL_PATH);
    if (!$path) $path = '/';
    $base = rtrim(SPVG_OUT_DIR, '/');
    $dir  = $base . rtrim($path, '/');
    if (substr($dir, -1) !== '/') $dir .= '/';
    wp_mkdir_p($dir);
    return $dir . 'index.html';
  }

  private function write_file($path, $contents) { 
    wp_mkdir_p(dirname($path)); 
    file_put_contents($path, $contents); 
  }

  /* ----------------- COPIA ASSETS COMPLETI ----------------- */
  private function copy_all_assets() {
    // Copia wp-content (plugins, themes, etc.)
    $wp_content_dir = WP_CONTENT_DIR;
    $exclude_dirs = ['uploads', 'cache', 'w3tc-config', 'static-build'];
    
    $items = glob($wp_content_dir . '/*');
    foreach ($items as $item) {
        $name = basename($item);
        if (in_array($name, $exclude_dirs)) continue;
        
        $dst = SPVG_OUT_DIR . '/wp-content/' . $name;
        if (is_dir($item)) {
            $this->rcopy($item, $dst);
        } elseif (is_file($item)) {
            wp_mkdir_p(dirname($dst));
            copy($item, $dst);
        }
    }
    
    // Copia wp-includes
    $wp_includes_dir = ABSPATH . 'wp-includes';
    $dst_includes = SPVG_OUT_DIR . '/wp-includes';
    if (file_exists($wp_includes_dir)) {
        $this->rcopy($wp_includes_dir, $dst_includes);
    }
    
    // Copia wp-json endpoints statici
    $this->copy_wp_json();
  }

  private function copy_uploads() {
    $uploads = wp_get_upload_dir();
    $src = $uploads['basedir'];
    $dst = SPVG_OUT_DIR . '/wp-content/uploads';
    $this->rcopy($src, $dst);
  }

  private function copy_elementor_assets() {
    $uploads = wp_get_upload_dir();
    $elCss = $uploads['basedir'].'/elementor/css';
    if (file_exists($elCss)) {
        $this->rcopy($elCss, SPVG_OUT_DIR.'/wp-content/uploads/elementor/css');
    }
    
    $elAssets = WP_PLUGIN_DIR.'/elementor/assets';
    if (file_exists($elAssets)) {
        $this->rcopy($elAssets, SPVG_OUT_DIR.'/wp-content/plugins/elementor/assets');
    }
  }

  private function copy_wp_json() {
    $api_endpoints = [
        '/wp-json/wp/v2/posts',
        '/wp-json/wp/v2/pages',
        '/wp-json/wp/v2/categories',
        '/wp-json/wp/v2/tags',
    ];
    
    foreach ($api_endpoints as $endpoint) {
        $url = home_url($endpoint);
        $response = wp_remote_get($url, ['timeout' => 10]);
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $json = wp_remote_retrieve_body($response);
            $path = SPVG_OUT_DIR . $endpoint;
            wp_mkdir_p($path);
            $this->write_file($path . '/index.html', $json);
        }
    }
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
    
    while(false !== ($file = readdir($dir))) {
        if ($file == '.' || $file == '..') continue;
        
        $s = $src . '/' . $file; 
        $d = $dst . '/' . $file;
        
        if (is_dir($s)) {
            $this->rcopy($s, $d);
        } else {
            copy($s, $d);
        }
    }
    closedir($dir);
  }

  private function basic_404() {
    return '<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Pagina non trovata</title>
    <meta name="robots" content="noindex">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
        h1 { color: #333; }
        p { color: #666; }
    </style>
</head>
<body>
    <h1>404 - Pagina non trovata</h1>
    <p>La pagina che stai cercando non esiste.</p>
</body>
</html>';
  }

  private function write_vercel_json() {
    $path = SPVG_OUT_DIR . '/vercel.json';
    $json = [
      'version' => 2,
      'trailingSlash' => true,
      'cleanUrls' => true,
      'headers' => [
        [
          'source' => '/(.*)\\.(css|js|mjs|png|jpg|jpeg|gif|webp|avif|svg|woff|woff2)',
          'headers' => [
            ['key' => 'Cache-Control', 'value' => 'public, max-age=31536000, immutable']
          ]
        ],
        [
          'source' => '/(.*)',
          'headers' => [
            ['key' => 'Cache-Control', 'value' => 'public, max-age=0, must-revalidate']
          ]
        ]
      ]
    ];
    $this->write_file($path, json_encode($json, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
  }

  /* ----------------- GitHub Workflow ----------------- */
  private function setup_github_workflow() {
    $workflow_dir = SPVG_OUT_DIR . '/.github/workflows';
    wp_mkdir_p($workflow_dir);
    
    $workflow_content = <<<YAML
name: Deploy to Vercel
on:
  repository_dispatch:
    types: [deploy_trigger]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup Node.js
        uses: actions/setup-node@v3
        with:
          node-version: '18'
          
      - name: Deploy to Vercel
        uses: amondnet/vercel-action@v25
        with:
          vercel-token: \${{ secrets.VERCEL_TOKEN }}
          vercel-org-id: \${{ secrets.VERCEL_ORG_ID }}
          vercel-project-id: \${{ secrets.VERCEL_PROJECT_ID }}
          working-directory: ./
YAML;

    $this->write_file($workflow_dir . '/deploy.yml', $workflow_content);
  }

  /* ----------------- GitHub Test ----------------- */
  private function test_github_connection() {
    $token = get_option('spvg_github_token');
    $repo = get_option('spvg_github_repo');
    
    if (!$token || !$repo) {
      throw new RuntimeException('Configura Token e Repository prima.');
    }
    
    $response = wp_remote_get(
      "https://api.github.com/repos/{$repo}",
      [
        'headers' => [
          'Authorization' => 'token ' . $token,
          'Accept' => 'application/vnd.github.v3+json',
          'User-Agent' => 'WordPress-Static-Publisher'
        ],
        'timeout' => 30
      ]
    );
    
    if (is_wp_error($response)) {
      throw new RuntimeException('GitHub connection failed: ' . $response->get_error_message());
    }
    
    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
      throw new RuntimeException('GitHub API error ' . $code . ': Repository not found or access denied');
    }
    
    return true;
  }

  /* ----------------- AJAX Deploy Semplificato ----------------- */
  public function ajax_prepare_deploy() {
    check_ajax_referer('spvg_ajax_nonce');

    $token = get_option('spvg_github_token');
    $repo = get_option('spvg_github_repo');
    
    if (!$token || !$repo) {
      wp_send_json_error(['error' => 'Configura Token e Repository GitHub.'], 400);
    }

    // Inizializza il repository Git se non esiste
    $this->init_git_repo();

    $state = [
      'phase' => 'building',
      'repo' => $repo,
      'token' => $token,
      'total_steps' => 3
    ];

    set_transient($this->progress_key(), $state, SPVG_PROGRESS_TTL);
    wp_send_json_success(['total' => 3, 'message' => 'Ready to deploy']);
  }

  public function ajax_deploy_step() {
    check_ajax_referer('spvg_ajax_nonce');
    
    $key = $this->progress_key();
    $state = get_transient($key);
    
    if (!$state) {
      wp_send_json_error(['error' => 'Sessione scaduta. Ricomincia il deploy.'], 400);
    }

    try {
      if ($state['phase'] === 'building') {
        // FASE 1: Build dei file statici
        $this->build();
        $state['phase'] = 'git_operations';
        set_transient($key, $state, SPVG_PROGRESS_TTL);
        
        wp_send_json_success([
          'phase' => 'building',
          'done' => 1,
          'total' => 3,
          'percent' => 33,
          'message' => 'Build completata - File statici generati'
        ]);
      }
      
      if ($state['phase'] === 'git_operations') {
        // FASE 2: Operazioni Git locali
        $output = $this->run_git_commands();
        $state['phase'] = 'trigger_deploy';
        set_transient($key, $state, SPVG_PROGRESS_TTL);
        
        wp_send_json_success([
          'phase' => 'git_operations', 
          'done' => 2,
          'total' => 3,
          'percent' => 66,
          'message' => 'Git operations completate - Commit e push effettuati',
          'output' => $output
        ]);
      }
      
      if ($state['phase'] === 'trigger_deploy') {
        // FASE 3: Trigger GitHub Actions
        $result = $this->trigger_github_deploy($state['token'], $state['repo']);
        delete_transient($key); // Cleanup
        
        wp_send_json_success([
          'phase' => 'done',
          'done' => 3,
          'total' => 3,
          'percent' => 100,
          'message' => 'Deploy triggered! - GitHub Actions avviato',
          'deploy_url' => 'https://github.com/' . $state['repo'] . '/actions'
        ]);
      }
      
      // Fallback
      wp_send_json_success([
        'phase' => $state['phase'],
        'done' => 0,
        'total' => 3,
        'percent' => 0,
        'message' => 'Processing...'
      ]);
      
    } catch (Throwable $e) {
      delete_transient($key);
      wp_send_json_error(['error' => $e->getMessage()], 500);
    }
  }

  /* ----------------- Git Functions ----------------- */
  private function init_git_repo() {
    $build_dir = SPVG_OUT_DIR;
    
    // Inizializza Git se non esiste
    if (!file_exists($build_dir . '/.git')) {
      $commands = [
        "cd {$build_dir}",
        "git init",
        "git config user.email 'wordpress@example.com'",
        "git config user.name 'WordPress Static Publisher'",
        "git add .",
        "git commit -m 'Initial static site'"
      ];
      
      $full_command = implode(' && ', $commands);
      exec($full_command . ' 2>&1', $output, $return_code);
      
      if ($return_code !== 0) {
        throw new RuntimeException('Git init failed: ' . implode('\n', $output));
      }
    }
  }

  private function run_git_commands() {
    $build_dir = SPVG_OUT_DIR;
    $branch = get_option('spvg_github_branch', 'main');
    $commit_message = get_option('spvg_commit_message', 'Static site update');
    
    // Setup remote se non esiste
    $repo = get_option('spvg_github_repo');
    $remote_url = "https://" . get_option('spvg_github_token') . "@github.com/{$repo}.git";
    
    $commands = [
        "cd {$build_dir}",
        "git remote remove origin 2>/dev/null || true",
        "git remote add origin {$remote_url}",
        "git add .",
        "git commit -m '{$commit_message} " . date('Y-m-d H:i:s') . "' || echo 'No changes to commit'",
        "git push -u origin main --force"
    ];
    
    $full_command = implode(' && ', $commands);
    exec($full_command . ' 2>&1', $output, $return_code);
    
    if ($return_code !== 0) {
        // Se il push fallisce, prova con un approccio più semplice
        $simple_commands = [
            "cd {$build_dir}",
            "rm -rf .git",
            "git init",
            "git config user.email 'wordpress@example.com'",
            "git config user.name 'WordPress Static Publisher'",
            "git add .",
            "git commit -m '{$commit_message}'",
            "git branch -M main",
            "git remote add origin {$remote_url}",
            "git push -u origin main --force"
        ];
        
        $simple_command = implode(' && ', $simple_commands);
        exec($simple_command . ' 2>&1', $output, $return_code);
        
        if ($return_code !== 0) {
            throw new RuntimeException('Git operations failed: ' . implode('\n', $output));
        }
    }
    
    return $output;
  }

  private function trigger_github_deploy($token, $repo) {
    $response = wp_remote_post(
        "https://api.github.com/repos/{$repo}/dispatches",
        [
            'headers' => [
                'Authorization' => 'token ' . $token,
                'Accept' => 'application/vnd.github.v3+json',
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'event_type' => 'deploy_trigger',
                'client_payload' => ['source' => 'wordpress', 'timestamp' => time()]
            ]),
            'timeout' => 30
        ]
    );
    
    if (is_wp_error($response)) {
        // Non blocchiamo il deploy se il trigger fallisce
        // Il push su GitHub potrebbe comunque triggerare il deploy
        return 'GitHub trigger might have failed, but push was successful. Check GitHub Actions.';
    }
    
    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 204) {
        return 'GitHub trigger returned ' . $code . ', but push was successful. Check GitHub Actions.';
    }
    
    return 'GitHub Actions triggered successfully';
  }

  /* ----------------- Helper ----------------- */
  private function starts_with($haystack, $needle) {
    return substr($haystack, 0, strlen($needle)) === $needle;
  }
}

new SPVG_Plugin();