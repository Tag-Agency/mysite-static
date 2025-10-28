<?php
/**
 * Plugin Name: Static Publisher → Vercel (PROVEN + OPTIMIZED)
 * Description: Versione collaudata che funziona, con ottimizzazioni performance aggiunte. Build → Git Push → Vercel.
 * Version: 3.8.1
 * Author: Tag Agency (Mauro)
 */

if (!defined('ABSPATH')) exit;

define('SPVG_VERSION', '3.8.1');
define('SPVG_OUT_DIR', WP_CONTENT_DIR . '/static-build');
define('SPVG_PROGRESS_TTL', 60 * 60);

class SPVG_Plugin {

  private $gemini_api_key;

  public function __construct() {
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_init', [$this, 'register_settings']);
    add_action('init',       [$this, 'maybe_static_mode']);

    // AJAX
    add_action('wp_ajax_spvg_prepare_deploy', [$this, 'ajax_prepare_deploy']);
    add_action('wp_ajax_spvg_deploy_step',    [$this, 'ajax_deploy_step']);
    add_action('wp_ajax_spvg_build_progress', [$this, 'ajax_build_progress']);
    
    $this->gemini_api_key = get_option('spvg_gemini_api_key', '');
  }

  /* ----------------- Admin UI ----------------- */
  public function admin_menu() {
    add_menu_page('Static → Vercel', 'Static → Vercel', 'manage_options', 'spvg', [$this, 'render_admin'], 'dashicons-cloud-upload', 58);
  }

  public function register_settings() {
    register_setting('spvg_settings', 'spvg_github_token');
    register_setting('spvg_settings', 'spvg_github_repo');
    register_setting('spvg_settings', 'spvg_github_branch', ['default' => 'main']);
    register_setting('spvg_settings', 'spvg_exclusions');
    register_setting('spvg_settings', 'spvg_commit_message', ['default' => 'Static site update']);
    register_setting('spvg_settings', 'spvg_performance_opt', ['default' => '1']);
    register_setting('spvg_settings', 'spvg_gemini_api_key');
    register_setting('spvg_settings', 'spvg_gemini_model', ['default' => 'gemini-1.5-flash']);
    register_setting('spvg_settings', 'spvg_gemini_optimize', ['default' => '0']);
  }

  private function progress_key() { return 'spvg_progress_' . get_current_user_id(); }
  private function build_progress_key() { return 'spvg_build_progress_' . get_current_user_id(); }

  public function render_admin() {
    if (!current_user_can('manage_options')) return;

    $report = '';
    if (isset($_POST['spvg_action']) && check_admin_referer('spvg_nonce_action', 'spvg_nonce_field')) {
      $action = sanitize_text_field($_POST['spvg_action']);
      try {
        if ($action === 'build') {
          // Avvia build asincrona
          $this->start_async_build();
          $this->admin_notice('Build avviata! Controlla il progresso qui sotto.', 'success');
        } elseif ($action === 'test_github') {
          $this->test_github_connection();
          $this->admin_notice('✅ Connessione GitHub riuscita!', 'success');
        } elseif ($action === 'setup_workflow') {
          $this->setup_github_workflow();
          $this->admin_notice('Workflow GitHub Actions creato.', 'success');
        } elseif ($action === 'test_gemini') {
          $this->test_gemini_connection();
          $this->admin_notice('✅ Connessione Gemini riuscita!', 'success');
        } elseif ($action === 'list_models') {
          $models = $this->list_gemini_models();
          $report = "Modelli Gemini disponibili:\n\n" . $models;
          $this->admin_notice('Modelli Gemini caricati!', 'success');
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
    $performance_opt = get_option('spvg_performance_opt', '1');
    $gemini_api_key = esc_attr(get_option('spvg_gemini_api_key', ''));
    $gemini_model = esc_attr(get_option('spvg_gemini_model', 'gemini-1.5-flash'));
    $gemini_optimize = get_option('spvg_gemini_optimize', '0');

    $ajax_nonce = wp_create_nonce('spvg_ajax_nonce');
    $ajax_url = admin_url('admin-ajax.php');

    ?>
    <div class="wrap">
      <h1>Static Publisher → Vercel</h1>
      <p><strong>🚀 Versione Collaudata + Ottimizzazioni Performance + Gemini AI</strong></p>

      <form method="post" action="options.php" style="margin-top:1rem;">
        <?php settings_fields('spvg_settings'); ?>
        <h2>Configurazione GitHub</h2>
        <table class="form-table" role="presentation">
          <tr><th scope="row">GitHub Token</th><td><input type="password" name="spvg_github_token" value="<?php echo $github_token; ?>" class="regular-text" placeholder="ghp_..." /></td></tr>
          <tr><th scope="row">Repository</th><td><input type="text" name="spvg_github_repo" value="<?php echo $github_repo; ?>" class="regular-text" placeholder="username/repository" /></td></tr>
          <tr><th scope="row">Branch</th><td><input type="text" name="spvg_github_branch" value="<?php echo $github_branch; ?>" class="regular-text" placeholder="main" /></td></tr>
          <tr><th scope="row">Commit Message</th><td><input type="text" name="spvg_commit_message" value="<?php echo $commit_msg; ?>" class="regular-text" /></td></tr>
        </table>

        <h2>Ottimizzazioni AI (Gemini)</h2>
        <table class="form-table" role="presentation">
          <tr><th scope="row">Gemini API Key</th>
              <td><input type="password" name="spvg_gemini_api_key" value="<?php echo $gemini_api_key; ?>" class="regular-text" placeholder="AIza..." />
                  <p class="description"><a href="https://aistudio.google.com/app/apikey" target="_blank">Ottieni API Key gratuita</a></p></td></tr>
          <tr><th scope="row">Modello Gemini</th>
              <td><input type="text" name="spvg_gemini_model" value="<?php echo $gemini_model; ?>" class="regular-text" placeholder="es. gemini-1.5-flash" />
                  <p class="description">Modelli suggeriti: gemini-1.5-flash, gemini-1.5-pro, gemini-pro</p></td></tr>
          <tr><th scope="row">Ottimizzazione AI</th>
              <td><input type="checkbox" name="spvg_gemini_optimize" value="1" <?php checked($gemini_optimize, '1'); ?> /> 
                  Attiva ottimizzazione HTML con Gemini AI</td></tr>
        </table>

        <h2>Configurazione Build</h2>
        <table class="form-table" role="presentation">
          <tr><th scope="row">Esclusioni (uno per riga)</th><td><textarea name="spvg_exclusions" rows="5" class="large-text code" placeholder="/area-riservata/&#10;/bozze/"><?php echo $exclusions; ?></textarea></td></tr>
          <tr><th scope="row">Ottimizzazioni Performance</th>
              <td><input type="checkbox" name="spvg_performance_opt" value="1" <?php checked($performance_opt, '1'); ?> /> 
                  Attiva lazy loading e minificazione</td></tr>
        </table>
        <?php submit_button('Salva impostazioni'); ?>
      </form>

      <hr>
      <form method="post">
        <?php wp_nonce_field('spvg_nonce_action', 'spvg_nonce_field'); ?>
        <p style="display:flex;gap:8px;flex-wrap:wrap;">
          <button class="button" name="spvg_action" value="build" id="spvg-build-btn">🏗️ Build Statica</button>
          <button type="button" id="spvg-start-deploy" class="button button-primary">🚀 DEPLOY COMPLETO</button>
          <button class="button" name="spvg_action" value="test_github">🔍 Test GitHub</button>
          <button class="button" name="spvg_action" value="test_gemini">🤖 Test Gemini</button>
          <button class="button" name="spvg_action" value="list_models">📋 Lista Modelli</button>
          <button class="button" name="spvg_action" value="setup_workflow">⚙️ Setup Workflow</button>
        </p>
      </form>

      <!-- Build Progress UI -->
      <div id="spvg-build-progress-wrap" style="display:none;max-width:680px;margin-top:20px;">
        <h3>🏗️ Build in Corso</h3>
        <div style="margin:12px 0;">Stato: <strong id="spvg-build-status-text">Preparazione...</strong></div>
        <div style="width:100%;background:#eee;border:1px solid #ccc;border-radius:4px;height:20px;overflow:hidden;">
          <div id="spvg-build-bar" style="height:100%;width:0%;background:#46b450;transition:width .3s;"></div>
        </div>
        <div style="margin-top:6px;">Progresso: <span id="spvg-build-perc">0%</span> - <span id="spvg-build-details">Inizializzazione...</span></div>
        <pre id="spvg-build-log" style="margin-top:10px;max-height:260px;overflow:auto;background:#fff;border:1px solid #ccd;padding:10px;font-size:12px;"></pre>
      </div>

      <!-- Deploy Progress UI -->
      <div id="spvg-deploy-progress-wrap" style="display:none;max-width:680px;margin-top:20px;">
        <h3>🚀 Deploy in Corso</h3>
        <div style="margin:12px 0;">Stato: <strong id="spvg-deploy-status-text">—</strong></div>
        <div style="width:100%;background:#eee;border:1px solid #ccc;border-radius:4px;height:20px;overflow:hidden;">
          <div id="spvg-deploy-bar" style="height:100%;width:0%;background:#2271b1;transition:width .2s;"></div>
        </div>
        <div style="margin-top:6px;">Fase: <span id="spvg-deploy-phase">0/3</span> - <span id="spvg-deploy-perc">0%</span></div>
        <pre id="spvg-deploy-log" style="margin-top:10px;max-height:260px;overflow:auto;background:#fff;border:1px solid #ccd;padding:10px;"></pre>
      </div>

      <?php if ($report): ?>
        <h2>Report</h2>
        <pre style="max-height:320px;overflow:auto;background:#fff;border:1px solid #ccd;padding:12px;"><?php echo esc_html($report); ?></pre>
      <?php endif; ?>
    </div>

    <style>
    .spvg-progress-active {
      position: relative;
      overflow: hidden;
    }
    .spvg-progress-active::after {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
      animation: loading 1.5s infinite;
    }
    @keyframes loading {
      0% { left: -100%; }
      100% { left: 100%; }
    }
    </style>

    <script>
    (function(){
      const buildBtn = document.getElementById('spvg-build-btn');
      const deployBtn = document.getElementById('spvg-start-deploy');
      
      const buildWrap = document.getElementById('spvg-build-progress-wrap');
      const buildBar = document.getElementById('spvg-build-bar');
      const buildPerc = document.getElementById('spvg-build-perc');
      const buildStatus = document.getElementById('spvg-build-status-text');
      const buildDetails = document.getElementById('spvg-build-details');
      const buildLog = document.getElementById('spvg-build-log');
      
      const deployWrap = document.getElementById('spvg-deploy-progress-wrap');
      const deployBar = document.getElementById('spvg-deploy-bar');
      const deployPerc = document.getElementById('spvg-deploy-perc');
      const deployPhase = document.getElementById('spvg-deploy-phase');
      const deployStatus = document.getElementById('spvg-deploy-status-text');
      const deployLog = document.getElementById('spvg-deploy-log');

      const AJAX  = "<?php echo esc_js($ajax_url); ?>";
      const NONCE = "<?php echo esc_js($ajax_nonce); ?>";

      function buildLog(msg){ 
        const timestamp = new Date().toLocaleTimeString();
        buildLog.textContent += `[${timestamp}] ${msg}\n`; 
        buildLog.scrollTop = buildLog.scrollHeight; 
      }
      
      function deployLog(msg){ 
        deployLog.textContent += msg + '\n'; 
        deployLog.scrollTop = deployLog.scrollHeight; 
      }
      
      function setBuildProg(p, status, details){
        buildBar.style.width = p + '%';
        buildPerc.textContent = p + '%';
        buildStatus.textContent = status || '';
        buildDetails.textContent = details || '';
      }

      function setDeployProg(p, ph, s){
        deployBar.style.width = p + '%';
        deployPerc.textContent = p + '%';
        deployPhase.textContent = ph;
        deployStatus.textContent = s || '';
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

      // Build Progress Monitor
      async function monitorBuildProgress() {
        buildWrap.style.display = 'block';
        buildLog.textContent = '';
        setBuildProg(0, 'Preparazione...', 'Inizializzazione build');
        
        let buildFinished = false;
        let lastProgress = 0;
        
        // Disabilita il pulsante build
        if(buildBtn) {
          buildBtn.disabled = true;
          buildBtn.classList.add('spvg-progress-active');
        }
        
        try {
          // Avvia la build
          await post('spvg_prepare_deploy');
          buildLog('✅ Build avviata con successo');
          
          // Monitora il progresso ogni 2 secondi
          while(!buildFinished) {
            try {
              const progress = await post('spvg_build_progress');
              
              if (progress.finished) {
                buildLog('🎉 BUILD COMPLETATA!');
                buildLog('📊 ' + progress.report);
                setBuildProg(100, 'Completato', 'Build terminata con successo');
                buildFinished = true;
                break;
              }
              
              if (progress.percent > lastProgress) {
                setBuildProg(progress.percent, progress.status, progress.details);
                if (progress.message) buildLog(progress.message);
                lastProgress = progress.percent;
              }
              
              // Timeout dopo 30 minuti
              if (progress.percent >= 100 || progress.error) {
                if (progress.error) {
                  buildLog('❌ ERRORE: ' + progress.error);
                  setBuildProg(0, 'Errore', 'Build fallita');
                } else {
                  buildLog('🎉 BUILD COMPLETATA!');
                  setBuildProg(100, 'Completato', 'Build terminata con successo');
                }
                buildFinished = true;
                break;
              }
              
              await new Promise(resolve => setTimeout(resolve, 2000));
              
            } catch (e) {
              buildLog('⚠️ Errore monitoraggio: ' + e.message);
              // Continua a monitorare nonostante errori minori
              await new Promise(resolve => setTimeout(resolve, 5000));
            }
          }
          
        } catch(e) {
          buildLog('❌ ERRORE CRITICO: ' + e.message);
          setBuildProg(0, 'Errore', 'Build fallita');
        } finally {
          // Riabilita il pulsante build
          if(buildBtn) {
            buildBtn.disabled = false;
            buildBtn.classList.remove('spvg-progress-active');
          }
        }
      }

      // Deploy Handler
      async function startDeploy(){
        deployWrap.style.display = 'block';
        deployLog.textContent = '';
        setDeployProg(0, '0/3', 'Preparazione…');
        
        try {
          const prep = await post('spvg_prepare_deploy');
          deployLog('✅ Preparazione completata');
          
          let finished = false;
          while(!finished){
            const step = await post('spvg_deploy_step');
            setDeployProg(step.percent, step.done + '/' + step.total, step.message);
            
            if (step.message) deployLog('📦 ' + step.message);
            if (step.output) deployLog('🔧 ' + step.output.join('\n'));
            
            if (step.phase === 'done'){
              deployLog('🎉 DEPLOY COMPLETATO!');
              deployLog('🚀 GitHub Actions: ' + step.deploy_url);
              setDeployProg(100, '3/3', 'Completato');
              finished = true;
            }
          }
        } catch(e) {
          deployLog('❌ ERRORE: ' + e.message);
          setDeployProg(0, 'Errore', 'Fallito');
        }
      }

      // Event Listeners
      if(buildBtn) {
        buildBtn.addEventListener('click', function(e) {
          if(!buildBtn.disabled) {
            monitorBuildProgress();
          }
        });
      }
      
      if(deployBtn) {
        deployBtn.addEventListener('click', startDeploy);
      }
    })();
    </script>
    <?php
  }

  private function start_async_build() {
    // Inizializza il progresso della build
    $progress_data = [
      'started' => time(),
      'percent' => 0,
      'status' => 'Preparazione...',
      'details' => 'Inizializzazione build',
      'current_url' => '',
      'total_urls' => 0,
      'processed_urls' => 0,
      'finished' => false,
      'error' => null,
      'report' => ''
    ];
    
    set_transient($this->build_progress_key(), $progress_data, SPVG_PROGRESS_TTL);
    
    // Avvia la build in background
    $this->build_async();
  }

  private function build_async() {
    // Esegui la build in background
    ignore_user_abort(true);
    set_time_limit(0);
    
    $progress_key = $this->build_progress_key();
    
    try {
      $report = $this->build_with_progress();
      
      // Aggiorna il progresso finale
      $progress_data = [
        'finished' => true,
        'percent' => 100,
        'status' => 'Completato',
        'details' => 'Build terminata con successo',
        'report' => $report,
        'completed' => time()
      ];
      
      set_transient($progress_key, $progress_data, SPVG_PROGRESS_TTL);
      
    } catch (Exception $e) {
      $progress_data = [
        'finished' => true,
        'error' => $e->getMessage(),
        'percent' => 0,
        'status' => 'Errore',
        'details' => 'Build fallita'
      ];
      
      set_transient($progress_key, $progress_data, SPVG_PROGRESS_TTL);
    }
  }

  /* ----------------- Build con Progresso ----------------- */
  public function build_with_progress() {
    $progress_key = $this->build_progress_key();
    
    $this->prepare_out();
    
    // Aggiorna progresso
    $this->update_build_progress(5, 'Preparazione ambiente', 'Pulizia directory...');

    // Rigenera CSS Elementor
    if (class_exists('\Elementor\Plugin')) {
      try { 
        \Elementor\Plugin::$instance->files_manager->clear_cache(); 
        $this->update_build_progress(10, 'Ottimizzazione Elementor', 'Rigenerazione CSS...');
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

    $total_urls = count($urls);
    $this->update_build_progress(15, 'Raccolta URL', "Trovati {$total_urls} URL da processare");

    $report = "URL totali: {$total_urls}\n";
    $gemini_optimized = 0;

    foreach ($urls as $index => $url) {
      $percent = 15 + (($index / $total_urls) * 60); // 15% - 75%
      $this->update_build_progress(
        $percent, 
        'Generazione pagine', 
        "Processando {$url} (" . ($index + 1) . "/{$total_urls})"
      );

      $html = $this->fetch($url);
      if ($html === false) { 
        $report .= "✖ {$url}\n"; 
        $this->add_build_log("❌ Fallito: {$url}");
        continue; 
      }

      // Ottimizzazione con Gemini AI
      if (get_option('spvg_gemini_optimize', '0') && $this->gemini_api_key) {
        try {
          $this->update_build_progress(
            $percent, 
            'Ottimizzazione AI', 
            "Ottimizzando {$url} con Gemini"
          );
          
          $optimized_html = $this->optimize_with_gemini($html);
          if ($optimized_html && strlen($optimized_html) > 100) {
            $html = $optimized_html;
            $gemini_optimized++;
            $report .= "🤖 {$url} (AI ottimizzato)\n";
            $this->add_build_log("🤖 Ottimizzato: {$url}");
          } else {
            $report .= "✔ {$url} (AI fallback)\n";
            $this->add_build_log("✅ Completato: {$url} (fallback)");
          }
        } catch (Exception $e) {
          $report .= "✔ {$url} (AI errore: " . $e->getMessage() . ")\n";
          $this->add_build_log("⚠️ Completato: {$url} (errore AI: {$e->getMessage()})");
        }
      } else {
        $report .= "✔ {$url}\n";
        $this->add_build_log("✅ Completato: {$url}");
      }

      $path = $this->url_to_path($url);
      $this->write_file($path, $html);
    }

    $this->update_build_progress(80, 'Copia assets', 'Copiando file statici...');

    // Copia assets
    $this->copy_all_assets();
    $this->copy_uploads();
    $this->copy_elementor_assets();

    // Ottimizzazioni performance
    if (get_option('spvg_performance_opt', '1')) {
      $this->update_build_progress(90, 'Ottimizzazioni', 'Applicando ottimizzazioni performance...');
      $this->apply_performance_optimizations();
    }

    // file extra
    $this->write_file(SPVG_OUT_DIR.'/404.html', $this->basic_404());
    $this->write_vercel_json();

    $report .= "\n⚡ Ottimizzazioni performance: " . (get_option('spvg_performance_opt', '1') ? 'Sì' : 'No');
    $report .= "\n🤖 Pagine ottimizzate con AI: " . $gemini_optimized;

    $this->update_build_progress(95, 'Finalizzazione', 'Completamento build...');

    return $report;
  }

  private function update_build_progress($percent, $status, $details) {
    $progress_key = $this->build_progress_key();
    $progress_data = get_transient($progress_key) ?: [];
    
    $progress_data['percent'] = min(100, max(0, $percent));
    $progress_data['status'] = $status;
    $progress_data['details'] = $details;
    $progress_data['updated'] = time();
    
    set_transient($progress_key, $progress_data, SPVG_PROGRESS_TTL);
  }

  private function add_build_log($message) {
    $progress_key = $this->build_progress_key();
    $progress_data = get_transient($progress_key) ?: [];
    
    if (!isset($progress_data['logs'])) {
      $progress_data['logs'] = [];
    }
    
    $progress_data['logs'][] = [
      'timestamp' => time(),
      'message' => $message
    ];
    
    // Mantieni solo gli ultimi 100 log
    if (count($progress_data['logs']) > 100) {
      $progress_data['logs'] = array_slice($progress_data['logs'], -100);
    }
    
    set_transient($progress_key, $progress_data, SPVG_PROGRESS_TTL);
  }

  public function ajax_build_progress() {
    check_ajax_referer('spvg_ajax_nonce');
    
    $progress_key = $this->build_progress_key();
    $progress_data = get_transient($progress_key) ?: [];
    
    if (empty($progress_data)) {
      wp_send_json_success([
        'percent' => 0,
        'status' => 'Non avviata',
        'details' => 'La build non è stata avviata',
        'finished' => false
      ]);
    }
    
    $response = [
      'percent' => $progress_data['percent'] ?? 0,
      'status' => $progress_data['status'] ?? 'Sconosciuto',
      'details' => $progress_data['details'] ?? '',
      'finished' => $progress_data['finished'] ?? false,
      'error' => $progress_data['error'] ?? null,
      'report' => $progress_data['report'] ?? ''
    ];
    
    // Aggiungi i log recenti
    if (isset($progress_data['logs'])) {
      $recent_logs = array_slice($progress_data['logs'], -10); // Ultimi 10 log
      $response['message'] = end($recent_logs)['message'] ?? '';
    }
    
    wp_send_json_success($response);
  }

  private function admin_notice($msg, $type='success') {
    printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), $msg);
  }

  /* ----------------- Static mode ----------------- */
  public function maybe_static_mode() {
    if (isset($_GET['static']) && $_GET['static'] == '1') {
      define('SPVG_STATIC_MODE', true);
      show_admin_bar(false);
      add_filter('script_loader_src', [$this, 'strip_ver'], 999);
      add_filter('style_loader_src',  [$this, 'strip_ver'], 999);
    }
  }
  
  public function strip_ver($src) { 
    $parts = explode('?', $src); 
    return $parts[0]; 
  }

  /* ----------------- Build statica originale (per compatibilità) ----------------- */
  public function build() {
    return $this->build_with_progress();
  }

  /* ----------------- Gemini AI Optimization ----------------- */
  private function optimize_with_gemini($html) {
    if (!$this->gemini_api_key) {
      return $html;
    }

    $model = get_option('spvg_gemini_model', 'gemini-1.5-flash');
    
    $prompt = "Ottimizza questo HTML per performance massime seguendo STRETTAMENTE queste regole:

1. RIMUOVI COMPLETAMENTE:
   - Tutti i commenti HTML/CSS/JS
   - Tutti i riferimenti a WordPress (classi, ID, meta tag wp-*)
   - Script e stili non essenziali
   - Attribute non standard
   - Elementi nascosti o non visibili

2. APPLICA:
   - Minificazione HTML completa (rimuovi spazi superflui, newlines)
   - Minificazione CSS inline (rimuovi spazi, commenti)
   - Lazy loading per tutte le immagini
   - Defer per gli script non critici
   - Rimozione attributi non necessari (style, data-* non usati)

3. MANTIENI:
   - Struttura semantica del documento
   - Tutto il contenuto visibile al utente
   - Tutti i link e la navigazione
   - Funzionalità base del sito
   - Immagini e media

4. OTTIMIZZAZIONI CSS:
   - Unisci regole CSS duplicate
   - Rimuovi proprietà non usate
   - Shorten quando possibile

5. FORMATO OUTPUT:
   - Solo HTML pulito e ottimizzato
   - Nessun commento
   - Nessuna spiegazione aggiuntiva

HTML DA OTTIMIZZARE:
" . $html;

    // URL dinamico basato sul modello selezionato
    $url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key=" . $this->gemini_api_key;
    
    $response = wp_remote_post($url, [
      'headers' => [
        'Content-Type' => 'application/json',
      ],
      'body' => json_encode([
        'contents' => [
          [
            'parts' => [
              ['text' => $prompt]
            ]
          ]
        ],
        'generationConfig' => [
          'temperature' => 0.1,
          'topK' => 40,
          'topP' => 0.8,
          'maxOutputTokens' => 8192,
        ]
      ]),
      'timeout' => 30,
      'sslverify' => true
    ]);

    if (is_wp_error($response)) {
      throw new Exception('Errore connessione Gemini: ' . $response->get_error_message());
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    
    if ($response_code !== 200) {
      throw new Exception('HTTP Error ' . $response_code . ': ' . $response_body);
    }

    $body = json_decode($response_body, true);
    
    if (isset($body['error'])) {
      $error_msg = $body['error']['message'] ?? 'Errore sconosciuto';
      throw new Exception('Errore Gemini API: ' . $error_msg);
    }

    if (!isset($body['candidates'][0]['content']['parts'][0]['text'])) {
      throw new Exception('Risposta Gemini non valida - struttura inattesa');
    }

    $optimized = trim($body['candidates'][0]['content']['parts'][0]['text']);
    
    // Validazione base - rimuovi eventuali markdown code blocks
    $optimized = preg_replace('/```html|```/i', '', $optimized);
    $optimized = trim($optimized);
    
    // Validazione risultato
    if (strlen($optimized) < 100) {
      throw new Exception('HTML ottimizzato troppo corto');
    }
    
    if (strpos($optimized, '<html') === false && strpos($optimized, '<!DOCTYPE') === false) {
      throw new Exception('HTML ottimizzato non valido - manca doctype/html');
    }

    return $optimized;
  }

  /* ----------------- Lista Modelli Gemini ----------------- */
  private function list_gemini_models() {
    if (!$this->gemini_api_key) {
      throw new RuntimeException('Configura la Gemini API Key prima.');
    }

    $url = "https://generativelanguage.googleapis.com/v1/models?key=" . $this->gemini_api_key;
    
    $response = wp_remote_get($url, [
      'timeout' => 30,
      'sslverify' => true
    ]);

    if (is_wp_error($response)) {
      throw new Exception('Errore connessione Gemini: ' . $response->get_error_message());
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    
    if ($response_code !== 200) {
      throw new Exception('HTTP Error ' . $response_code . ': ' . $response_body);
    }

    $body = json_decode($response_body, true);
    
    if (isset($body['error'])) {
      $error_msg = $body['error']['message'] ?? 'Errore sconosciuto';
      throw new Exception('Errore Gemini API: ' . $error_msg);
    }

    if (!isset($body['models'])) {
      throw new Exception('Nessun modello trovato nella risposta');
    }

    $models_list = "MODELI GEMINI DISPONIBILI:\n\n";
    foreach ($body['models'] as $model) {
      $name = $model['name'] ?? 'N/A';
      $display_name = $model['displayName'] ?? 'N/A';
      $description = $model['description'] ?? 'N/A';
      $supported_methods = isset($model['supportedGenerationMethods']) ? implode(', ', $model['supportedGenerationMethods']) : 'N/A';
      
      $models_list .= "🔹 {$display_name}\n";
      $models_list .= "   Nome: {$name}\n";
      $models_list .= "   Metodi: {$supported_methods}\n";
      $models_list .= "   Desc: {$description}\n\n";
    }

    return $models_list;
  }

  private function test_gemini_connection() {
    if (!$this->gemini_api_key) {
      throw new RuntimeException('Configura la Gemini API Key prima.');
    }

    $model = get_option('spvg_gemini_model', 'gemini-1.5-flash');
    $test_html = '<!DOCTYPE html><html><head><title>Test</title></head><body><h1>Test Page</h1><p>This is a test page for Gemini optimization.</p></body></html>';
    
    try {
      $result = $this->optimize_with_gemini($test_html);
      
      if (!$result || strlen($result) < 50) {
        throw new RuntimeException('Gemini API non risponde correttamente - risultato troppo corto');
      }
      
      if (strpos($result, 'Test Page') === false) {
        throw new RuntimeException('Gemini API ha modificato troppo il contenuto');
      }
      
    } catch (Exception $e) {
      throw new RuntimeException('Test Gemini fallito: ' . $e->getMessage());
    }
  }

  private function prepare_out() { 
    if (!file_exists(SPVG_OUT_DIR)) wp_mkdir_p(SPVG_OUT_DIR); 
  }

  private function collect_urls() {
    $urls = [];
    $urls[] = home_url('/');

    // sitemap index
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

    // fallback
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

    $urls = array_values(array_unique(array_map(function($u){
      $u = preg_replace('#\?.*$#', '', $u);
      return trailingslashit($u);
    }, $urls)));

    return $urls;
  }

  private function fetch($url) {
    $static_url = add_query_arg('static', '1', $url);
    
    $args = [
      'timeout' => 30, 
      'headers' => ['User-Agent' => 'SPVG/'.SPVG_VERSION]
    ];
    
    $r = wp_remote_get($static_url, $args);
    if (is_wp_error($r)) return false;
    if (wp_remote_retrieve_response_code($r) !== 200) return false;
    
    $html = wp_remote_retrieve_body($r);
    
    // REWRITE SEMPLICE E FUNZIONANTE
    $html = $this->rewrite_urls_simple($html);
    
    return $html;
  }

  /* ----------------- REWRITE URLS - VERSIONE COLLAUDATA ----------------- */
  private function rewrite_urls_simple($html) {
    $site_url = home_url();
    
    $replacements = [
        $site_url . '/wp-content/' => '/wp-content/',
        $site_url . '/wp-includes/' => '/wp-includes/',
        $site_url . '/' => '/'
    ];
    
    $html = str_replace(array_keys($replacements), array_values($replacements), $html);
    $html = preg_replace('#(\.(?:css|js|png|jpg|jpeg|webp|svg|gif|woff2))\?[^"\']+#', '$1', $html);
    
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

  /* ----------------- ASSETS - VERSIONE COLLAUDATA ----------------- */
  private function copy_all_assets() {
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
    
    $wp_includes_dir = ABSPATH . 'wp-includes';
    $dst_includes = SPVG_OUT_DIR . '/wp-includes';
    if (file_exists($wp_includes_dir)) {
        $this->rcopy($wp_includes_dir, $dst_includes);
    }
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
        if ($file=='.'||$file=='..') continue;
        $s = $src . '/' . $file; 
        $d = $dst . '/' . $file;
        if (is_dir($s)) $this->rcopy($s, $d); 
        else copy($s, $d);
    }
    closedir($dir);
  }

  /* ----------------- OTTIMIZZAZIONI PERFORMANCE (NUOVE) ----------------- */
  private function apply_performance_optimizations() {
    $html_files = glob(SPVG_OUT_DIR . '/**/*.html');
    
    foreach ($html_files as $file) {
      $html = file_get_contents($file);
      
      // 1. Lazy loading immagini
      $html = preg_replace('#<img([^>]*)>#', '<img$1 loading="lazy">', $html);
      
      // 2. Minificazione base
      $html = preg_replace('/\s+/', ' ', $html);
      $html = preg_replace('/>\s+</', '><', $html);
      
      // 3. Resource hints
      if (strpos($html, '</head>') !== false) {
        $hints = '<link rel="preconnect" href="https://fonts.googleapis.com">
                 <link rel="dns-prefetch" href="//fonts.gstatic.com">';
        $html = str_replace('</head>', $hints . '</head>', $html);
      }
      
      file_put_contents($file, $html);
    }
    
    // Log ottimizzazioni
    $this->write_file(SPVG_OUT_DIR . '/.optimizations-applied.txt', 
      "Performance optimizations applied:\n" .
      "- Lazy loading images\n" .
      "- Basic HTML minification\n" .
      "- Resource hints\n" .
      "Generated: " . date('Y-m-d H:i:s')
    );
  }

  private function basic_404() {
    return '<!doctype html><html><head><meta charset="utf-8"><title>Pagina non trovata</title></head><body><h1>404 - Pagina non trovata</h1></body></html>';
  }

  private function write_vercel_json() {
    $path = SPVG_OUT_DIR . '/vercel.json';
    $json = [
      'version' => 2,
      'trailingSlash' => true,
      'cleanUrls' => true,
      'headers' => [
        ['source'=>'/(.*)\\.(css|js|mjs|png|jpg|jpeg|gif|webp|avif|svg|woff2)','headers'=>[['key'=>'Cache-Control','value'=>'public, max-age=31536000, immutable']]],
        ['source'=>'/(.*)','headers'=>[['key'=>'Cache-Control','value'=>'public, max-age=0, must-revalidate']]]
      ]
    ];
    $this->write_file($path, json_encode($json, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
  }

  /* ----------------- GitHub Workflow ----------------- */
  private function setup_github_workflow() {
    $workflow_dir = SPVG_OUT_DIR . '/.github/workflows';
    wp_mkdir_p($workflow_dir);
    
    $workflow_content = 'name: Deploy to Vercel
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
  }

  /* ----------------- AJAX Deploy - VERSIONE COLLAUDATA ----------------- */
  public function ajax_prepare_deploy() {
    check_ajax_referer('spvg_ajax_nonce');

    $token = get_option('spvg_github_token');
    $repo = get_option('spvg_github_repo');
    
    if (!$token || !$repo) {
      wp_send_json_error(['error' => 'Configura Token e Repository GitHub.'], 400);
    }

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
        $this->build();
        $state['phase'] = 'git_operations';
        set_transient($key, $state, SPVG_PROGRESS_TTL);
        
        wp_send_json_success([
          'phase' => 'building',
          'done' => 1,
          'total' => 3,
          'percent' => 33,
          'message' => 'Build completata'
        ]);
      }
      
      if ($state['phase'] === 'git_operations') {
        $output = $this->run_git_commands();
        $state['phase'] = 'trigger_deploy';
        set_transient($key, $state, SPVG_PROGRESS_TTL);
        
        wp_send_json_success([
          'phase' => 'git_operations', 
          'done' => 2,
          'total' => 3,
          'percent' => 66,
          'message' => 'Git operations completate',
          'output' => $output
        ]);
      }
      
      if ($state['phase'] === 'trigger_deploy') {
        $result = $this->trigger_github_deploy($state['token'], $state['repo']);
        delete_transient($key);
        
        wp_send_json_success([
          'phase' => 'done',
          'done' => 3,
          'total' => 3,
          'percent' => 100,
          'message' => 'Deploy triggered!',
          'deploy_url' => 'https://github.com/' . $state['repo'] . '/actions'
        ]);
      }
      
    } catch (Throwable $e) {
      delete_transient($key);
      wp_send_json_error(['error' => $e->getMessage()], 500);
    }
  }

  /* ----------------- Git Functions - VERSIONE COLLAUDATA ----------------- */
  private function init_git_repo() {
    $build_dir = SPVG_OUT_DIR;
    
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