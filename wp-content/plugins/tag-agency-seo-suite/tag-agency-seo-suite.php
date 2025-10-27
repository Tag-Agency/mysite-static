<?php
/**
 * Plugin Name: SEO Suite
 * Plugin URI:  https://tagagency.it
 * Description: Un'unica suite per l'ottimizzazione SEO di post e immagini con Gemini. Supporta WooCommerce e WPML.
 * Version:     1.4.0
 * Author:      Mauro Biancu
 * License:     GPLv2 or later
 * Text Domain: tag-agency-seo-suite
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Tag_Agency_SEO_Suite {
    /* ---------- COSTANTI UNIFICATE ---------- */
    const TASS_OPT_GROUP       = 'tass_settings';
    const TASS_OPT_GEMINI_KEY  = 'tass_gemini_key';
    const TASS_OPT_MODEL       = 'tass_gemini_model';
    const TASS_OPT_LANG        = 'tass_language';
    const TASS_OPT_DAILY_LIMIT = 'tass_daily_limit';
    const TASS_OPT_POST_DEBUG  = 'tass_post_debug';
    const TASS_OPT_POST_OVERRIDE= 'tass_post_override';

    // Meta per i post
    const TASS_META_TITLE = '_tass_title';
    const TASS_META_DESC  = '_tass_description';
    const TASS_META_KW    = '_tass_keywords';
    const TASS_META_FOCUS = '_tass_focus_kw';
    const TASS_META_SCORE = '_tass_quality_score';

    // Nonce
    const TASS_NONCE = 'tass_nonce';

    public function __construct() {
        // Ganci per il menu e le impostazioni
        add_action('admin_menu', [$this, 'add_settings_pages']);
        add_action('admin_init', [$this, 'register_settings']);
        
        // Aggiungi link "Impostazioni" nella pagina dei plugin
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
        
        // Ganci per la metabox dei post
        add_action('add_meta_boxes', [$this, 'add_post_metabox']);
        add_action('save_post', [$this, 'save_post_meta']);
        
        // Ganci AJAX per i post
        add_action('wp_ajax_tass_generate_meta',  [$this, 'ajax_generate_meta_post']);
        add_action('wp_ajax_tass_quality_check',  [$this, 'ajax_quality_check_post']);
        add_action('wp_ajax_tass_apply_yoast',    [$this, 'ajax_apply_yoast']);
        add_action('wp_ajax_tass_yoast_diag',     [$this, 'ajax_yoast_diag']);
        
        // Ganci per l'output in front-end
        add_action('wp_head', [$this, 'output_meta_head'], 1);
        add_action('init', [$this, 'add_yoast_filters']);

        // Ganci per l'enqueue degli script
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Ganci per la REST API delle immagini
        add_action('rest_api_init', [$this, 'register_rest_endpoints']);

        // Ganci per il contatore e l'admin bar
        add_action('admin_bar_menu', [$this, 'adminbar_debug'], 100);
        
        // Gancio per salvare le opzioni dalla pagina delle impostazioni
        add_action( 'admin_post_tass_save_settings', [$this, 'save_settings']);
        
        // ========== INTEGRAZIONE WOOCOMMERCE ==========
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_woocommerce_metabox']);
        add_action('woocommerce_process_product_meta', [$this, 'save_woocommerce_meta']);
        add_action('woocommerce_product_after_variable_attributes', [$this, 'add_variation_metabox'], 10, 3);
        add_action('woocommerce_save_product_variation', [$this, 'save_variation_meta'], 10, 2);
        add_filter('woocommerce_structured_data_product', [$this, 'enhance_structured_data'], 10, 2);
        add_action('wp_ajax_tass_generate_meta_product', [$this, 'ajax_generate_meta_product']);
        add_action('wp_ajax_tass_quality_check_product', [$this, 'ajax_quality_check_product']);
        
        // ========== INTEGRAZIONE WPML ==========
        add_action('wpml_after_save_post', [$this, 'sync_translations_meta'], 10, 3);
        add_filter('wpml_pre_parse_config_settings', [$this, 'add_custom_meta_to_wpml']);
        add_filter('tass_meta_title', [$this, 'translate_meta'], 10, 2);
        add_filter('tass_meta_description', [$this, 'translate_meta'], 10, 2);
    }

    public function add_settings_pages() {
        add_menu_page(
            __('SEO Suite', 'tag-agency-seo-suite'),
            __('SEO Suite', 'tag-agency-seo-suite'),
            'manage_options',
            'tass-main-page',
            [$this, 'render_main_settings_page'],
            'dashicons-star-filled'
        );
        add_submenu_page(
            'tass-main-page',
            __('Ottimizzazione Immagini', 'tag-agency-seo-suite'),
            __('Ottimizzazione Immagini', 'tag-agency-seo-suite'),
            'upload_files',
            'tass-image-optimizer',
            [$this, 'render_image_optimizer_page']
        );
    }
    
    public function add_settings_link($links) {
        $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=tass-main-page' ) ) . '">' . __('Impostazioni', 'tag-agency-seo-suite') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function register_settings() {
        // La registrazione delle opzioni viene gestita manualmente
    }
    
    public function render_main_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $opts = get_option(self::TASS_OPT_GROUP, []);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('tass_settings_nonce'); ?>
                <input type="hidden" name="action" value="tass_save_settings" />
                <h2>Impostazioni Gemini</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="tass_gemini_key">Gemini API Key</label></th>
                        <td>
                            <input type="password" id="tass_gemini_key" name="<?php echo esc_attr(self::TASS_OPT_GEMINI_KEY); ?>" value="<?php echo esc_attr($opts[self::TASS_OPT_GEMINI_KEY] ?? ''); ?>" class="regular-text" />
                            <p class="description"><?php echo sprintf(
                                __('Ottieni la tua Gemini API Key qui: %s', 'tag-agency-seo-suite'),
                                '<a href="https://aistudio.google.com/apikey" target="_blank">https://aistudio.google.com/apikey</a>'
                            ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tass_gemini_model">Modello</label></th>
                        <td><input type="text" id="tass_gemini_model" name="<?php echo esc_attr(self::TASS_OPT_MODEL); ?>" value="<?php echo esc_attr($opts[self::TASS_OPT_MODEL] ?? 'gemini-1.5-flash'); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tass_language">Lingua Target</label></th>
                        <td><input type="text" id="tass_language" name="<?php echo esc_attr(self::TASS_OPT_LANG); ?>" value="<?php echo esc_attr($opts[self::TASS_OPT_LANG] ?? get_locale()); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tass_daily_limit">Limite Giornaliero (chiamate)</label></th>
                        <td><input type="number" id="tass_daily_limit" name="<?php echo esc_attr(self::TASS_OPT_DAILY_LIMIT); ?>" value="<?php echo esc_attr($opts[self::TASS_OPT_DAILY_LIMIT] ?? 25); ?>" class="small-text" /></td>
                    </tr>
                </table>
                <h2>Impostazioni Post & Pagine</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="tass_post_override">Override Yoast via filtri</label></th>
                        <td><input type="checkbox" id="tass_post_override" name="<?php echo esc_attr(self::TASS_OPT_POST_OVERRIDE); ?>" value="1" <?php checked( !empty($opts[self::TASS_OPT_POST_OVERRIDE]) ); ?> /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tass_post_debug">Modalità Diagnostica</label></th>
                        <td><input type="checkbox" id="tass_post_debug" name="<?php echo esc_attr(self::TASS_OPT_POST_DEBUG); ?>" value="1" <?php checked( !empty($opts[self::TASS_OPT_POST_DEBUG]) ); ?> /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function render_image_optimizer_page() {
        if ( ! current_user_can( 'upload_files' ) ) { return; }
        $opts = get_option(self::TASS_OPT_GROUP, []);
        $usage = $this->get_usage();
        $limit = intval($opts[self::TASS_OPT_DAILY_LIMIT] ?? 25);
        $remaining = max( 0, $limit - intval($usage['count']) );
        $nonce_rest = wp_create_nonce( 'wp_rest' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('AI Image SEO Optimizer', 'tag-agency-seo-suite'); ?></h1>
            <p><?php esc_html_e('Genera metadati SEO per le immagini usando Gemini.', 'tag-agency-seo-suite'); ?></p>
            
            <div class="notice <?php echo $remaining > 0 ? 'notice-info' : 'notice-warning'; ?>">
                <p><strong><?php echo sprintf( esc_html__('Limite giornaliero: %d — Utilizzato oggi: %d — Rimanenti: %d', 'tag-agency-seo-suite'), $limit, intval($usage['count']), $remaining ); ?></strong><br/>
                <em><?php esc_html_e('Il contatore si resetta ogni giorno (UTC). Puoi modificare il limite nella pagina delle impostazioni.', 'tag-agency-seo-suite'); ?></em></p>
            </div>
            
            <hr />

            <h2 style="margin-top:24px;"><?php esc_html_e('Ottimizza immagini', 'tag-agency-seo-suite'); ?></h2>
            <p><?php esc_html_e('Seleziona una o più immagini dalla Libreria Media, inserisci una focus keyword (opzionale) e genera i metadati SEO.', 'tag-agency-seo-suite'); ?></p>

            <div style="margin-bottom:12px;">
                <input type="text" id="tass-focus-image" placeholder="<?php esc_attr_e('Focus keyword (opzionale)', 'tag-agency-seo-suite'); ?>" style="width:280px; margin-right:8px;" />
                <button id="tass-select" class="button button-secondary"><?php esc_html_e('Seleziona immagini', 'tag-agency-seo-suite'); ?></button>
                <button id="tass-generate-image" class="button button-primary" disabled><?php esc_html_e('Genera metadati', 'tag-agency-seo-suite'); ?></button>
            </div>

            <div id="tass-selected-images" style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px;"></div>
            <div id="tass-results-images"></div>
            <div id="tass-notice-images" style="margin-top:10px;"></div>
        </div>
        
        <script>
        (function(){
            const nonce = <?php echo json_encode( $nonce_rest ); ?>;
            const restBase = <?php echo json_encode( esc_url_raw( rest_url('tass/v1') ) ); ?>;
            const autoApply = false;
            let selected = [];
            
            const $btnSelect = document.getElementById('tass-select');
            const $btnGen = document.getElementById('tass-generate-image');
            const $wrapSel = document.getElementById('tass-selected-images');
            const $results = document.getElementById('tass-results-images');
            const $focus = document.getElementById('tass-focus-image');
            const $notice = document.getElementById('tass-notice-images');

            function renderSelected(){
                $wrapSel.innerHTML='';
                if(!selected.length){ $btnGen.disabled = true; return; }
                $btnGen.disabled = false;
                selected.forEach(item => {
                    const d = document.createElement('div');
                    d.style.border='1px solid #ddd'; d.style.padding='6px'; d.style.width='120px';
                    d.innerHTML = '<div style="font-size:11px; margin-bottom:4px;">#'+item.id+'</div>' +
                                  (item.url ? '<img src="'+item.url+'" style="width:100%; height:auto; display:block;" />' : '') +
                                  '<button type="button" class="button-link-delete" data-id="'+item.id+'">Rimuovi</button>';
                    $wrapSel.appendChild(d);
                });
                $wrapSel.querySelectorAll('button[data-id]').forEach(btn=>{
                    btn.addEventListener('click', () => {
                        const id = parseInt(btn.getAttribute('data-id'));
                        selected = selected.filter(s => s.id !== id);
                        renderSelected();
                    });
                });
            }

            $btnSelect.addEventListener('click', function(e){
                e.preventDefault();
                const frame = wp.media({
                    title: 'Seleziona immagini',
                    button: { text: 'Usa selezione' },
                    multiple: true,
                    library: { type: ['image'] }
                });
                frame.on('select', function(){
                    const items = frame.state().get('selection').toJSON();
                    items.forEach(m => {
                        if(!selected.find(s=>s.id===m.id)){
                            selected.push({ id: m.id, url: m.sizes?.thumbnail?.url || m.url });
                        }
                    });
                    renderSelected();
                });
                frame.open();
            });

            $btnGen.addEventListener('click', async function(e){
                e.preventDefault();
                $btnGen.disabled = true;
                $notice.textContent = 'Generazione in corso...';
                try{
                    const resp = await fetch(restBase + '/generate-image', {
                        method:'POST',
                        headers: { 'Content-Type':'application/json', 'X-WP-Nonce': nonce },
                        body: JSON.stringify({ attachment_ids: selected.map(s=>s.id), focus_keyword: $focus.value || '' })
                    });
                    const data = await resp.json();
                    if(!resp.ok){ throw new Error(data?.message || 'Errore API'); }
                    $results.innerHTML='';
                    let okCount = 0;
                    data.results.forEach(r => {
                        const card = document.createElement('div');
                        card.style.border='1px solid #ddd'; card.style.padding='10px'; card.style.margin='10px 0';
                        if(r.error){
                            card.innerHTML = '<strong>ID '+r.id+':</strong> <span style="color:#b00;">Errore: '+r.error+'</span>';
                        } else {
                            card.innerHTML = '<strong>ID '+r.id+'</strong><br />' +
                                '<div style="display:flex; gap:10px; margin-top:6px;">' +
                                '<textarea rows="1" style="flex:1;" class="tass-title" data-id="'+r.id+'">'+(r.title||'')+'</textarea>' +
                                '<textarea rows="2" style="flex:1;" class="tass-alt" data-id="'+r.id+'">'+(r.alt||'')+'</textarea>' +
                                '</div>' +
                                '<textarea rows="3" style="width:100%; margin-top:6px;" class="tass-desc" data-id="'+r.id+'">'+(r.description||'')+'</textarea>' +
                                '<div style="margin-top:6px;"><button class="button apply-one" data-id="'+r.id+'">Applica</button></div>';
                            okCount++;
                        }
                        $results.appendChild(card);
                    });

                    if(okCount && autoApply){
                        const tasks = Array.from($results.querySelectorAll('.apply-one'));
                        for(const btn of tasks){
                            btn.click();
                            await new Promise(res => setTimeout(res, 200));
                        }
                    }
                    $notice.textContent = 'Completato.';
                } catch(err){
                    console.error(err);
                    $notice.textContent = 'Errore: ' + err.message;
                } finally {
                    $btnGen.disabled = false;
                }
            });

            $results.addEventListener('click', async function(e){
                const btn = e.target.closest('.apply-one');
                if(!btn) return;
                e.preventDefault();
                const id = parseInt(btn.getAttribute('data-id'));
                const title = $results.querySelector('.tass-title[data-id="'+id+'"]').value;
                const alt = $results.querySelector('.tass-alt[data-id="'+id+'"]').value;
                const desc = $results.querySelector('.tass-desc[data-id="'+id+'"]').value;
                btn.disabled = true;
                btn.textContent = 'Applicazione...';
                try{
                    const resp = await fetch(restBase + '/apply-image', {
                        method:'POST',
                        headers: { 'Content-Type':'application/json', 'X-WP-Nonce': nonce },
                        body: JSON.stringify({ attachment_id:id, title, alt, description: desc })
                    });
                    const data = await resp.json();
                    if(!resp.ok){ throw new Error(data?.message || 'Errore durante l\'applicazione'); }
                    btn.textContent = 'Applicato ✓';
                } catch(err){
                    btn.textContent = 'Errore';
                    alert(err.message);
                } finally {
                    btn.disabled = false;
                }
            });
        })();
        </script>
        <?php
    }

    public function add_post_metabox() {
        foreach (['post', 'page'] as $s) {
            add_meta_box('tass_post_box', 'SEO Suite', [$this, 'render_post_metabox'], $s, 'side', 'high');
        }
    }

    public function render_post_metabox($post) {
        wp_nonce_field(self::TASS_NONCE, self::TASS_NONCE);
        $title = get_post_meta($post->ID, self::TASS_META_TITLE, true);
        $desc = get_post_meta($post->ID, self::TASS_META_DESC, true);
        $kw = get_post_meta($post->ID, self::TASS_META_KW, true);
        $focus = get_post_meta($post->ID, self::TASS_META_FOCUS, true);
        $score = get_post_meta($post->ID, self::TASS_META_SCORE, true);
        $perma = get_permalink($post);

        echo '<p><label>Keyword target</label><input type="text" name="' . self::TASS_META_FOCUS . '" value="' . esc_attr($focus) . '" class="widefat" placeholder="es. frollini artigianali sardi" /></p>';
        echo '<p><label>Meta Title <small id="tass-title-meter"></small></label><input type="text" name="' . self::TASS_META_TITLE . '" value="' . esc_attr($title) . '" class="widefat tass-watch" data-type="title" /></p>';
        echo '<p><label>Meta Description <small id="tass-desc-meter"></small></label><textarea name="' . self::TASS_META_DESC . '" class="widefat tass-watch" rows="3" data-type="desc">' . esc_textarea($desc) . '</textarea></p>';
        echo '<p><label>Keywords (facoltative)</label><input type="text" name="' . self::TASS_META_KW . '" value="' . esc_attr($kw) . '" class="widefat" placeholder="separate da virgola" /></p>';

        echo '<div id="tass-serp" class="tass-serp">';
        echo '<div id="tass-serp-title">' . esc_html($title ?: 'Anteprima titolo') . '</div>';
        echo '<div id="tass-serp-url">' . esc_html($perma) . '</div>';
        echo '<div id="tass-serp-desc">' . esc_html($desc ?: 'Anteprima descrizione') . '</div>';
        echo '</div>';

        echo '<p><button class="button button-primary" id="tass-generate" data-post="' . $post->ID . '">Genera meta con AI</button></p>';
        echo '<p><button class="button" id="tass-quality" data-post="' . $post->ID . '">Controllo qualità</button></p>';
        echo '<p><button class="button button-secondary" id="tass-apply-yoast" data-post="' . $post->ID . '">Applica a Yoast</button></p>';
        if (get_option(self::TASS_OPT_POST_DEBUG, '0') === '1') {
            echo '<p><button class="button" id="tass-yoast-diag" data-post="' . $post->ID . '">Diagnostica Yoast</button></p>';
        }

        if ($score !== '') {
            echo '<p><strong>SEO Quality Score:</strong> ' . intval($score) . '/100</p>';
        }

        echo '<script>
        (function($){
            $(document).ready(function(){
                const nonce = "' . esc_js(wp_create_nonce(self::TASS_NONCE)) . '";
                const ajaxUrl = "' . esc_js(admin_url('admin-ajax.php')) . '";
                const TASS_META_TITLE = "' . esc_js(self::TASS_META_TITLE) . '";
                const TASS_META_DESC = "' . esc_js(self::TASS_META_DESC) . '";
                const TASS_META_FOCUS = "' . esc_js(self::TASS_META_FOCUS) . '";
                const post_id = ' . intval($post->ID) . ';
                const debugMode = ' . json_encode(get_option(self::TASS_OPT_POST_DEBUG, '0') === '1') . ';

                function showNotice(msg, type = "info") {
                    console.log("TASS Notice: " + msg);
                    const notice = $(\'<div class="notice notice-\' + type + \' is-dismissible"><p>\' + msg + \'</p></div>\');
                    notice.insertBefore("#tass-serp");
                }
            });
        })(jQuery);
        </script>';
    }

    public function enqueue_admin_assets($hook) {
        wp_enqueue_style('tass-admin-style', plugin_dir_url(__FILE__) . 'assets/style.css', [], '1.2.0');
        
        if (in_array($hook, ['post.php', 'post-new.php'])) {
            wp_enqueue_script('tass-admin-post', plugin_dir_url(__FILE__) . 'assets/assets-admin.js', ['jquery'], '1.2.0', true);
            wp_localize_script('tass-admin-post', 'TASS', [
                'nonce' => wp_create_nonce(self::TASS_NONCE),
                'ajax' => admin_url('admin-ajax.php'),
                'titleMin' => 30, 'titleMax' => 65,
                'descMin' => 100, 'descMax' => 150,
                'debug' => get_option(self::TASS_OPT_POST_DEBUG, '0') === '1',
            ]);
        }
        
        if ($hook === 'seo-suite_page_tass-image-optimizer') {
            wp_enqueue_media();
            wp_enqueue_script('wp-api-fetch');
        }
        
        // Script per WooCommerce
        if (in_array($hook, ['post.php', 'post-new.php']) && get_post_type() === 'product') {
            wp_enqueue_script('tass-woocommerce', plugin_dir_url(__FILE__) . 'assets/assets-woocommerce.js', ['jquery'], '1.1.0', true);
            wp_localize_script('tass-woocommerce', 'TASS', [
                'nonce' => wp_create_nonce(self::TASS_NONCE),
                'ajax' => admin_url('admin-ajax.php'),
            ]);
        }
    }
    
    public function save_post_meta($post_id) {
        if (!isset($_POST[self::TASS_NONCE]) || !wp_verify_nonce($_POST[self::TASS_NONCE], self::TASS_NONCE)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        foreach ([self::TASS_META_TITLE, self::TASS_META_DESC, self::TASS_META_KW, self::TASS_META_FOCUS] as $f) {
            if (isset($_POST[$f])) update_post_meta($post_id, $f, sanitize_text_field((string)$_POST[$f]));
        }
    }

    public function save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Permesso negato.');
        }

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'tass_settings_nonce')) {
            wp_die('Nonce non valido.');
        }

        $opts = get_option(self::TASS_OPT_GROUP, []);

        $new_opts = [
            self::TASS_OPT_GEMINI_KEY    => sanitize_text_field($_POST[self::TASS_OPT_GEMINI_KEY] ?? ''),
            self::TASS_OPT_MODEL         => sanitize_text_field($_POST[self::TASS_OPT_MODEL] ?? ''),
            self::TASS_OPT_LANG          => sanitize_text_field($_POST[self::TASS_OPT_LANG] ?? ''),
            self::TASS_OPT_DAILY_LIMIT   => intval($_POST[self::TASS_OPT_DAILY_LIMIT] ?? 25),
            self::TASS_OPT_POST_OVERRIDE => isset($_POST[self::TASS_OPT_POST_OVERRIDE]) ? 1 : 0,
            self::TASS_OPT_POST_DEBUG    => isset($_POST[self::TASS_OPT_POST_DEBUG]) ? 1 : 0,
        ];
        
        update_option(self::TASS_OPT_GROUP, array_merge($opts, $new_opts));
        
        wp_safe_redirect( add_query_arg(['page' => 'tass-main-page', 'settings-updated' => 'true'], admin_url('admin.php')) );
        exit;
    }

    public function ajax_generate_meta_post() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], self::TASS_NONCE)) wp_send_json_error(['error' => 'Nonce non valido']);
        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id || !current_user_can('edit_post', $post_id)) wp_send_json_error(['error' => 'Permesso negato']);
        $rl = $this->rl_check();
        if (is_wp_error($rl)) wp_send_json_error(['error' => $rl->get_error_message()]);
        $focus_kw = sanitize_text_field($_POST['focus_kw'] ?? '');
        $res = $this->generate_meta_for_post($post_id, $focus_kw);
        is_wp_error($res) ? wp_send_json_error(['error' => $res->get_error_message()]) : wp_send_json_success($res);
    }

    private function generate_meta_for_post($post_id, $focus_kw = '') {
        if (!$post_id) return new WP_Error('no_post', 'Post ID mancante');
        $content = $this->get_post_rendered_content($post_id);
        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $opts = get_option(self::TASS_OPT_GROUP, []);
        $api_key = trim($opts[self::TASS_OPT_GEMINI_KEY] ?? '');
        $model = trim($opts[self::TASS_OPT_MODEL] ?? 'gemini-1.5-flash');
        $lang = trim($opts[self::TASS_OPT_LANG] ?? 'it_IT');
        if (!$api_key || !$model) return new WP_Error('config', 'Configura Gemini API Key e Model nelle impostazioni.');
        
        $prompt_user = "Sito: {$site_name}\nURL: " . get_permalink($post_id) . "\nTitolo contenuto: " . get_the_title($post_id) . "\n" .
            ($focus_kw ? "Keyword target: {$focus_kw}\n" : "") .
            "Contenuto (estratto pulito):\n" . $this->truncate($this->strip($content), 3000);
        $system = "Sei un assistente SEO. Genera meta title (<= 65 caratteri), meta description (<= 150 caratteri), e keywords (opz.) in {$lang}. Rispondi SOLO JSON {title,description,keywords}.";
        
        $resp = $this->call_gemini_generate_content($api_key, $model, $system, $prompt_user);
        if (is_wp_error($resp)) return $resp;
        $this->rl_bump();
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        $text = '';
        if (is_array($data) && !empty($data['candidates'][0]['content']['parts'])) {
            foreach ($data['candidates'][0]['content']['parts'] as $p) if (!empty($p['text'])) $text .= $p['text'] . "\n";
            $text = trim($text);
        }
        $parsed = $this->parse_json_from_text($text);
        if (!is_array($parsed)) $parsed = ['title' => $this->hard_limit($text, 65), 'description' => $this->hard_limit($text, 150), 'keywords' => ''];
        $kwField = $parsed['keywords'] ?? '';
        if (is_array($kwField)) $kwField = implode(', ', array_map('trim', $kwField));
        $title = $this->hard_limit($parsed['title'] ?? '', 65);
        $desc = $this->truncate_meta_desc($parsed['description'] ?? '', 150);
        $kw = $this->hard_limit($kwField, 300);
        
        update_post_meta($post_id, self::TASS_META_TITLE, $title);
        update_post_meta($post_id, self::TASS_META_DESC,  $desc);
        update_post_meta($post_id, self::TASS_META_KW,    $kw);
        if ($focus_kw) update_post_meta($post_id, self::TASS_META_FOCUS, $focus_kw);
        
        return ['post_id' => $post_id, 'title' => $title, 'description' => $desc, 'keywords' => $kw];
    }
    
    private function call_gemini_generate_content($api_key, $model, $system, $user_text) {
        $endpoint = trailingslashit('https://generativelanguage.googleapis.com/v1beta') . 'models/' . rawurlencode($model) . ':generateContent';
        $url = add_query_arg(['key' => $api_key], $endpoint);
        $body = [
            'systemInstruction' => ['role' => 'system', 'parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $user_text]]]],
            'generationConfig' => [
                'temperature' => 0.3,
                'response_mime_type' => 'application/json'
            ],
        ];
        $args = ['headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($body), 'timeout' => 30];
        $resp = wp_remote_post($url, $args);
        
        if (is_wp_error($resp)) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) return new WP_Error('gemini_error', 'Gemini API error (HTTP ' . $code . '): ' . wp_remote_retrieve_body($resp));
        
        return $resp;
    }
    
    public function ajax_quality_check_post() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], self::TASS_NONCE)) wp_send_json_error(['error' => 'Nonce non valido']);
        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id || !current_user_can('edit_post', $post_id)) wp_send_json_error(['error' => 'Permesso negato']);
        $score_data = $this->compute_quality_score($post_id);
        update_post_meta($post_id, self::TASS_META_SCORE, $score_data['score']);
        wp_send_json_success($score_data);
    }

    // CONTROLLO QUALITÀ AVANZATO PER PAGINE E ARTICOLI
    private function compute_quality_score($post_id) {
        $focus = get_post_meta($post_id, self::TASS_META_FOCUS, true);
        $focus_primary = strpos($focus, ',') !== false ? trim(explode(',', $focus, 2)[0]) : $focus;
        $title = get_post_meta($post_id, self::TASS_META_TITLE, true);
        $desc = get_post_meta($post_id, self::TASS_META_DESC, true);
        
        $post = get_post($post_id);
        $content = $this->get_post_rendered_content($post_id);
        $text = $this->strip($content);
        $words = str_word_count($text, 0, 'ÀÈÉÌÍÒÓÙÚàèéìíòóùúçÇ');
        
        // Analisi HTML avanzata
        $has_h1 = (bool) preg_match('/<h1[^>]*>.*?<\/h1>/i', $content);
        preg_match_all('/<h2[^>]*>.*?<\/h2>/i', $content, $h2_matches);
        $h2_count = count($h2_matches[0]);
        preg_match_all('/<h3[^>]*>.*?<\/h3>/i', $content, $h3_matches);
        $h3_count = count($h3_matches[0]);
        
        // Analisi immagini
        preg_match_all('/<img[^>]*>/i', $content, $imgs);
        $img_count = count($imgs[0]);
        $img_alt_ok = 0;
        $img_with_dimensions = 0;
        foreach ($imgs[0] as $img) {
            if (preg_match('/alt\s*=\s*"(?:[^"]+)"/i', $img)) $img_alt_ok++;
            if (preg_match('/(width|height)\s*=\s*["\']\d+["\']/i', $img)) $img_with_dimensions++;
        }
        
        // Analisi link
        preg_match_all('/<a\s[^>]*href=/i', $content, $links);
        $link_count = count($links[0]);
        $internal_links = 0;
        $external_links = 0;
        $site_url = site_url();
        
        foreach ($links[0] as $link) {
            if (preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $link, $matches)) {
                $href = $matches[1];
                if (strpos($href, $site_url) !== false || strpos($href, '/') === 0) {
                    $internal_links++;
                } else {
                    $external_links++;
                }
            }
        }
        
        // Analisi contenuto
        $paragraphs = substr_count($content, '<p>');
        $lists = substr_count($content, '<ul>') + substr_count($content, '<ol>');
        $blocks = substr_count($content, '<!-- wp:');
        
        // Metriche SEO avanzate
        $title_ok = (mb_strlen($title) >= 30 && mb_strlen($title) <= 65);
        $desc_ok = (mb_strlen($desc) >= 100 && mb_strlen($desc) <= 150);
        $len_ok = ($words >= 300);
        $has_focus_kw = !empty($focus_primary);
        
        // Densità keyword avanzata
        $density_ok = true;
        $density = 0;
        $kw_in_title = false;
        $kw_in_description = false;
        $kw_in_first_paragraph = false;
        $kw_in_h1 = false;
        $kw_in_h2 = false;
        
        if ($focus_primary) {
            $kw_lower = mb_strtolower($focus_primary);
            $text_lower = mb_strtolower($text);
            $title_lower = mb_strtolower($title);
            $desc_lower = mb_strtolower($desc);
            
            $occ = mb_substr_count($text_lower, $kw_lower);
            $density = $words ? ($occ / max($words, 1)) * 100 : 0;
            $density_ok = ($density >= 0.5 && $density <= 2.5);
            
            $kw_in_title = (mb_strpos($title_lower, $kw_lower) !== false);
            $kw_in_description = (mb_strpos($desc_lower, $kw_lower) !== false);
            
            // Keyword in H1
            if (preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $content, $h1_match)) {
                $kw_in_h1 = (mb_strpos(mb_strtolower(strip_tags($h1_match[1])), $kw_lower) !== false);
            }
            
            // Keyword in H2
            if ($h2_count > 0) {
                $kw_in_h2 = (mb_strpos(mb_strtolower(strip_tags(implode(' ', $h2_matches[0]))), $kw_lower) !== false);
            }
            
            // Keyword in primo paragrafo
            if (preg_match('/<p>(.*?)<\/p>/i', $content, $first_p)) {
                $kw_in_first_paragraph = (mb_strpos(mb_strtolower(strip_tags($first_p[1])), $kw_lower) !== false);
            }
        }
        
        // Leggibilità
        $sentences = max(1, preg_match_all('/[\.!\?]+/u', $text, $m));
        $syllables = max(1, preg_match_all('/[aeiouàèéìíòóùú]/iu', $text, $m2));
        $flesch = 206.835 - 1.015 * ($words / $sentences) - 84.6 * ($syllables / max($words, 1));
        $read_ok = ($flesch >= 50);
        
        // Meta tags social
        $has_og_title = (bool) get_post_meta($post_id, '_yoast_wpseo_opengraph-title', true);
        $has_og_description = (bool) get_post_meta($post_id, '_yoast_wpseo_opengraph-description', true);
        $has_og_image = (bool) get_post_meta($post_id, '_yoast_wpseo_opengraph-image', true);
        
        // Schema markup
        $has_schema = (bool) preg_match('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>.*?<\/script>/is', $content);
        
        // Calcolo punteggio avanzato
        $score = 0;
        
        // SEO Base (25 punti)
        $score += ($title_ok ? 8 : 0);
        $score += ($desc_ok ? 8 : 0);
        $score += ($has_focus_kw ? 4 : 0);
        $score += ($density_ok ? 5 : 0);
        
        // Contenuto (30 punti)
        $score += ($len_ok ? 8 : 0);
        $score += ($has_h1 ? 5 : 0);
        $score += (($h2_count >= 2) ? 5 : 0);
        $score += (($paragraphs >= 3) ? 5 : 0);
        $score += ($read_ok ? 4 : 0);
        $score += (($lists >= 1) ? 3 : 0);
        
        // Immagini (15 punti)
        $score += (($img_count > 0) ? 5 : 0);
        $score += (($img_count > 0 && $img_alt_ok === $img_count) ? 5 : 0);
        $score += (($img_count > 0 && $img_with_dimensions >= $img_count * 0.5) ? 5 : 0);
        
        // Link (15 punti)
        $score += (($internal_links >= 2) ? 5 : 0);
        $score += (($external_links >= 1) ? 5 : 0);
        $score += (($link_count >= 3) ? 5 : 0);
        
        // Keyword Placement (10 punti)
        if ($focus_primary) {
            $score += ($kw_in_title ? 3 : 0);
            $score += ($kw_in_description ? 2 : 0);
            $score += ($kw_in_h1 ? 2 : 0);
            $score += ($kw_in_first_paragraph ? 2 : 0);
            $score += ($kw_in_h2 ? 1 : 0);
        }
        
        // Social & Technical (5 punti)
        $score += (($has_og_title && $has_og_description) ? 3 : 0);
        $score += ($has_schema ? 2 : 0);
        
        $score = min(100, $score);
        
        return [
            'score' => $score,
            'details' => [
                // SEO Base
                'title_length' => mb_strlen($title),
                'description_length' => mb_strlen($desc),
                'title_ok' => $title_ok,
                'description_ok' => $desc_ok,
                'has_focus_kw' => $has_focus_kw,
                
                // Contenuto
                'word_count' => $words,
                'length_ok' => $len_ok,
                'paragraph_count' => $paragraphs,
                'list_count' => $lists,
                'block_count' => $blocks,
                'has_h1' => $has_h1,
                'h2_count' => $h2_count,
                'h3_count' => $h3_count,
                
                // Immagini
                'image_count' => $img_count,
                'images_with_alt' => $img_alt_ok,
                'images_with_dimensions' => $img_with_dimensions,
                'images_alt_ok' => ($img_count > 0 && $img_alt_ok === $img_count),
                'images_dimensions_ok' => ($img_count > 0 && $img_with_dimensions >= $img_count * 0.5),
                
                // Link
                'total_links' => $link_count,
                'internal_links' => $internal_links,
                'external_links' => $external_links,
                'internal_links_ok' => ($internal_links >= 2),
                'external_links_ok' => ($external_links >= 1),
                'total_links_ok' => ($link_count >= 3),
                
                // Keyword
                'focus_kw' => $focus,
                'focus_primary' => $focus_primary,
                'focus_density_pct' => round($density, 2),
                'density_ok' => $density_ok,
                'kw_in_title' => $kw_in_title,
                'kw_in_description' => $kw_in_description,
                'kw_in_h1' => $kw_in_h1,
                'kw_in_h2' => $kw_in_h2,
                'kw_in_first_paragraph' => $kw_in_first_paragraph,
                
                // Leggibilità
                'flesch_estimate' => round($flesch, 1),
                'readability_ok' => $read_ok,
                'sentences_count' => $sentences,
                'syllables_count' => $syllables,
                
                // Social & Technical
                'has_og_title' => $has_og_title,
                'has_og_description' => $has_og_description,
                'has_og_image' => $has_og_image,
                'has_schema' => $has_schema,
                'social_ok' => ($has_og_title && $has_og_description),
                
                // Riepilogo checks
                'checks' => [
                    'title_ok' => $title_ok,
                    'description_ok' => $desc_ok,
                    'length_ok' => $len_ok,
                    'has_focus_kw' => $has_focus_kw,
                    'density_ok' => $density_ok,
                    'has_h1' => $has_h1,
                    'h2_ok' => ($h2_count >= 2),
                    'paragraphs_ok' => ($paragraphs >= 3),
                    'readability_ok' => $read_ok,
                    'lists_ok' => ($lists >= 1),
                    'images_ok' => ($img_count > 0),
                    'images_alt_ok' => ($img_count > 0 && $img_alt_ok === $img_count),
                    'internal_links_ok' => ($internal_links >= 2),
                    'external_links_ok' => ($external_links >= 1),
                    'total_links_ok' => ($link_count >= 3),
                    'kw_in_title' => $kw_in_title,
                    'kw_in_description' => $kw_in_description,
                    'kw_in_h1' => $kw_in_h1,
                    'kw_in_first_paragraph' => $kw_in_first_paragraph,
                    'social_ok' => ($has_og_title && $has_og_description),
                    'schema_ok' => $has_schema
                ]
            ]
        ];
    }

    private function get_post_rendered_content($post_id) {
        $post = get_post($post_id);
        if (!$post) return '';
        if (isset($post->post_content) && function_exists('apply_filters')) return apply_filters('the_content', $post->post_content);
        return $post->post_content ?? '';
    }
    private function strip($html) { return trim(preg_replace('/\s\s+/', ' ', strip_tags($html))); }
    private function truncate($text, $limit) {
        if (mb_strlen($text) <= $limit) return $text;
        $t = mb_substr($text, 0, $limit);
        $t = mb_substr($t, 0, mb_strrpos($t, ' '));
        return $t . '...';
    }
    private function hard_limit($text, $limit) { return mb_substr($text, 0, $limit); }

    private function truncate_meta_desc($text, $limit) {
        if (mb_strlen($text) <= $limit) return $text;
        $safe_text = mb_substr($text, 0, $limit + 10);
        $last_punctuation_pos = max(mb_strrpos($safe_text, '.'), mb_strrpos($safe_text, '!'), mb_strrpos($safe_text, '?'));
        if ($last_punctuation_pos !== false) {
            return mb_substr($text, 0, $last_punctuation_pos + 1);
        } else {
            return $this->truncate($text, $limit);
        }
    }
    
    private function parse_json_from_text($text) {
        $json_start = strpos($text, '{');
        $json_end = strrpos($text, '}');
        if ($json_start === false || $json_end === false) return false;
        $json_string = substr($text, $json_start, $json_end - $json_start + 1);
        return json_decode(trim($json_string), true);
    }
    
    public function add_yoast_filters() {
        if ($this->has_yoast() && get_option(self::TASS_OPT_POST_OVERRIDE, '1') === '1') {
            add_filter('wpseo_title', [$this, 'yoast_filter_title'], 99);
            add_filter('wpseo_metadesc', [$this, 'yoast_filter_desc'], 99);
            add_filter('wpseo_opengraph_title', [$this, 'yoast_filter_title'], 99);
            add_filter('wpseo_opengraph_desc', [$this, 'yoast_filter_desc'], 99);
            add_filter('wpseo_twitter_title', [$this, 'yoast_filter_title'], 99);
            add_filter('wpseo_twitter_description', [$this, 'yoast_filter_desc'], 99);
        }
        add_filter('pre_get_document_title', [$this, 'fallback_document_title'], 99);
    }

    public function yoast_filter_title($current) {
        if (!is_singular()) return $current;
        global $post;
        $t = trim(get_post_meta($post->ID, self::TASS_META_TITLE, true));
        
        // Applica filtro traduzione WPML
        $t = apply_filters('tass_meta_title', $t, $post->ID);
        
        return $t !== '' ? $t : $current;
    }

    public function yoast_filter_desc($current) {
        if (!is_singular()) return $current;
        global $post;
        $d = trim(get_post_meta($post->ID, self::TASS_META_DESC, true));
        
        // Applica filtro traduzione WPML
        $d = apply_filters('tass_meta_description', $d, $post->ID);
        
        return $d !== '' ? $d : $current;
    }

    public function fallback_document_title($title) {
        if (!is_singular()) return $title;
        global $post;
        $t = trim(get_post_meta($post->ID, self::TASS_META_TITLE, true));
        
        // Applica filtro traduzione WPML
        $t = apply_filters('tass_meta_title', $t, $post->ID);
        
        return $t !== '' ? $t : $title;
    }

    public function ajax_apply_yoast() {
        // Verifica nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], self::TASS_NONCE)) {
            wp_send_json_error(['error' => 'Nonce di sicurezza non valido']);
        }

        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['error' => 'Permessi insufficienti']);
        }

        // Verifica che Yoast sia attivo
        if (!$this->is_yoast_active()) {
            wp_send_json_error(['error' => 'Yoast SEO non è attivo o installato']);
        }

        $tsk_title = sanitize_text_field($_POST['title'] ?? '');
        $tsk_desc = sanitize_text_field($_POST['description'] ?? '');
        $tsk_focus = sanitize_text_field($_POST['focus_kw'] ?? '');

        // Salva prima nei meta del plugin
        update_post_meta($post_id, self::TASS_META_TITLE, $tsk_title);
        update_post_meta($post_id, self::TASS_META_DESC, $tsk_desc);
        update_post_meta($post_id, self::TASS_META_FOCUS, $tsk_focus);

        $results = [];
        $errors = [];

        try {
            // Applica a Yoast SEO
            if (!empty($tsk_title)) {
                $success = $this->yoast_set_value('title', $tsk_title, $post_id);
                $results['title_saved'] = $success;
                if (!$success) $errors[] = 'Titolo Yoast';
            }

            if (!empty($tsk_desc)) {
                $success = $this->yoast_set_value('metadesc', $tsk_desc, $post_id);
                $results['desc_saved'] = $success;
                if (!$success) $errors[] = 'Descrizione Yoast';
            }

            if (!empty($tsk_focus)) {
                $success = $this->yoast_set_value('focuskw', $tsk_focus, $post_id);
                $results['focus_saved'] = $success;
                if (!$success) $errors[] = 'Focus Keyword Yoast';
            }

            // Forza l'aggiornamento della cache di Yoast
            $this->yoast_rebuild_indexable($post_id);

            if (!empty($errors)) {
                wp_send_json_success([
                    'results' => $results,
                    'warnings' => 'Alcuni campi non sono stati salvati in Yoast: ' . implode(', ', $errors)
                ]);
            } else {
                wp_send_json_success([
                    'results' => $results,
                    'message' => 'Tutti i metadati sono stati applicati con successo a Yoast SEO'
                ]);
            }

        } catch (Exception $e) {
            wp_send_json_error([
                'error' => 'Errore durante il salvataggio in Yoast: ' . $e->getMessage()
            ]);
        }
    }

    // FUNZIONI AUSILIARIE PER YOAST
    private function is_yoast_active() {
        return class_exists('WPSEO_Meta') && class_exists('WPSEO_Frontend');
    }

    private function yoast_set_value($key, $value, $post_id) {
        if (class_exists('WPSEO_Meta')) {
            // Usa il metodo ufficiale di Yoast
            WPSEO_Meta::set_value($key, $value, $post_id);
            return true;
        }
        return false;
    }

    private function yoast_rebuild_indexable($post_id) {
        if (!function_exists('YoastSEO')) {
            return false;
        }

        try {
            $yoast = YoastSEO();
            
            // Metodo 1: Usa il builder di indexable se disponibile
            if (isset($yoast->helpers) && 
                isset($yoast->helpers->indexables) && 
                method_exists($yoast->helpers->indexables, 'get_post')) {
                
                $indexable = $yoast->helpers->indexables->get_post($post_id);
                if ($indexable) {
                    clean_post_cache($post_id);
                    return true;
                }
            }
            
            // Metodo 2: Pulisci la cache di Yoast
            if (function_exists('wpseo_flush_cache')) {
                wpseo_flush_cache();
            }
            
            // Metodo 3: Pulisci la cache generale
            clean_post_cache($post_id);
            wp_cache_delete($post_id, 'post_meta');
            
            // Trigger azione per Yoast
            do_action('wpseo_save_post', $post_id);
            
            return true;
            
        } catch (Exception $e) {
            // Log error silently
            error_log('TASS Yoast rebuild error: ' . $e->getMessage());
            return false;
        }
    }

    public function ajax_yoast_diag() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], self::TASS_NONCE)) wp_send_json_error(['error' => 'Nonce non valido']);
        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id || !current_user_can('edit_post', $post_id)) wp_send_json_error(['error' => 'Permesso negato']);
        $tsk_title = get_post_meta($post_id, self::TASS_META_TITLE, true);
        $tsk_desc  = get_post_meta($post_id, self::TASS_META_DESC, true);
        $yoast_title = $this->yoast_get($post_id, 'title');
        $yoast_desc  = $this->yoast_get($post_id, 'metadesc');
        $yoast_focus = $this->yoast_get($post_id, 'focuskw');
        $yoast_indexable_data = ['status' => 'Not available'];
        if (function_exists('YoastSEO')) {
            $yoast = YoastSEO();
            if (isset($yoast->helpers) && isset($yoast->helpers->indexables) && method_exists($yoast->helpers->indexables, 'get_post')) {
                $indexable = $yoast->helpers->indexables->get_post($post_id);
                if ($indexable) {
                    $yoast_indexable_data = [
                        'title' => $indexable->title,
                        'description' => $indexable->description,
                        'is_cornerstone' => $indexable->is_cornerstone,
                        'post_status' => $indexable->post_status,
                        'object_type' => $indexable->object_type,
                    ];
                }
            }
        }
        wp_send_json_success([
            'tsk_meta' => ['title' => $tsk_title, 'description' => $tsk_desc],
            'yoast_meta_direct' => ['title' => $yoast_title, 'description' => $yoast_desc, 'focuskw' => $yoast_focus],
            'yoast_indexable' => $yoast_indexable_data,
            'yoast_version' => defined('WPSEO_VERSION') ? WPSEO_VERSION : 'N/A',
            'yoast_active' => $this->is_yoast_active(),
        ]);
    }

    private function has_yoast() { return defined('WPSEO_VERSION') || class_exists('WPSEO_Meta') || class_exists('WPSEO_Frontend'); }
    private function yoast_get($post_id, $key) {
        $meta_keys = ['title' => '_yoast_wpseo_title', 'metadesc' => '_yoast_wpseo_metadesc', 'focuskw' => '_yoast_wpseo_focuskw'];
        if (isset($meta_keys[$key])) { return (string) get_post_meta($post_id, $meta_keys[$key], true); }
        return '';
    }
    
    public function output_meta_head() {
        if (is_admin() || !is_singular()) return;
        $force = get_option(self::TASS_OPT_POST_OVERRIDE, '0') === '1';
        if (!$force && $this->has_yoast()) { return; }
        global $post;
        if (!$post) return;
        $title = get_post_meta($post->ID, self::TASS_META_TITLE, true);
        $desc = get_post_meta($post->ID, self::TASS_META_DESC, true);
        $kw = get_post_meta($post->ID, self::TASS_META_KW, true);
        
        // Applica filtri traduzione WPML
        $title = apply_filters('tass_meta_title', $title, $post->ID);
        $desc = apply_filters('tass_meta_description', $desc, $post->ID);
        
        if ($title) echo "\n<title>" . esc_html($title) . "</title>\n";
        if ($desc) echo '<meta name="description" content="' . esc_attr($desc) . '" />' . "\n";
        if ($kw) echo '<meta name="keywords" content="' . esc_attr($kw) . '" />' . "\n";
    }

    private function rl_check() {
        $opts = get_option(self::TASS_OPT_GROUP, []);
        $limit = intval($opts[self::TASS_OPT_DAILY_LIMIT] ?? 25);
        $user_id = get_current_user_id();
        $key = 'tass_rate_limit_' . date('Ymd') . '_' . $user_id;
        $count = intval(get_transient($key));
        if ($count >= $limit) return new WP_Error('rate_limit_daily', 'Limite giornaliero di ' . $limit . ' richieste raggiunto.');
        return true;
    }
    private function rl_bump() {
        $opts = get_option(self::TASS_OPT_GROUP, []);
        $user_id = get_current_user_id();
        $key = 'tass_rate_limit_' . date('Ymd') . '_' . $user_id;
        $count = intval(get_transient($key));
        set_transient($key, $count + 1, DAY_IN_SECONDS);
    }
    
    public function adminbar_debug($wp_admin_bar) {
        $opts = get_option(self::TASS_OPT_GROUP, []);
        if (empty($opts[self::TASS_OPT_POST_DEBUG])) return;
        if (!current_user_can('manage_options')) return;
        
        $user_id = get_current_user_id();
        $key = 'tass_rate_limit_' . date('Ymd') . '_' . $user_id;
        $count = intval(get_transient($key));
        $limit = intval($opts[self::TASS_OPT_DAILY_LIMIT] ?? 25);
        
        // Aggiungi informazioni Yoast
        $yoast_status = $this->is_yoast_active() ? 'Yoast: ✔' : 'Yoast: ✘';
        
        $wp_admin_bar->add_node([
            'id' => 'tass-debug', 
            'title' => 'SEO Suite: ' . $count . '/' . $limit . ' | ' . $yoast_status, 
            'href' => admin_url('admin.php?page=tass-main-page'), 
            'meta' => ['class' => 'ab-item']
        ]);
    }
    
    public function register_rest_endpoints() {
        register_rest_route( 'tass/v1', '/generate-image', array(
            'methods'  => 'POST',
            'callback' => [$this, 'rest_generate_image'],
            'permission_callback' => function(){ return current_user_can('upload_files'); },
            'args' => ['attachment_ids' => ['required' => true, 'type'=>'array'], 'focus_keyword' => ['type'=>'string']],
        ));
        register_rest_route( 'tass/v1', '/apply-image', array(
            'methods'  => 'POST',
            'callback' => [$this, 'rest_apply_image'],
            'permission_callback' => function(){ return current_user_can('upload_files'); },
            'args' => ['attachment_id' => ['required' => true, 'type'=>'integer']],
        ));
    }
    
    public function get_usage() {
        $usage = get_option( 'tass_usage', ['date' => gmdate('Y-m-d'), 'count' => 0] );
        $today = gmdate('Y-m-d');
        if ( empty($usage['date']) || $usage['date'] !== $today ) {
            $usage = ['date' => $today, 'count' => 0];
            update_option('tass_usage', $usage);
        }
        return $usage;
    }
    
    public function inc_usage($n = 1) {
        $usage = $this->get_usage();
        $usage['count'] = max(0, intval($usage['count'])) + max(0, intval($n));
        update_option('tass_usage', $usage);
        return $usage;
    }
    
    public function rest_generate_image( WP_REST_Request $req ) {
        if ( ! wp_verify_nonce( $req->get_header('X-WP-Nonce'), 'wp_rest' ) ) {
            return new WP_Error( 'bad_nonce', 'Nonce non valida', array('status'=>403) );
        }
        $ids = array_map( 'intval', (array) $req->get_param('attachment_ids') );
        $focus = sanitize_text_field( (string) $req->get_param('focus_keyword') );
        $opts = get_option(self::TASS_OPT_GROUP, []);
        $lang = $opts[self::TASS_OPT_LANG] ?? 'it_IT';
        $maxTitle = max(1, intval($opts['max_title_len'] ?? 60));
        $maxAlt = max(1, intval($opts['max_alt_len'] ?? 125));
        $limit = max(1, intval($opts[self::TASS_OPT_DAILY_LIMIT] ?? 25));

        $usage = $this->get_usage();
        $remaining = max( 0, $limit - intval($usage['count']) );

        $results = array();
        if ( $remaining <= 0 ) {
            foreach ($ids as $id) { $results[] = array('id'=>$id, 'error'=>'daily_limit_reached'); }
            return rest_ensure_response( array('results'=>$results, 'remaining'=>0) );
        }

        $to_process = array_slice($ids, 0, $remaining);
        $skipped = array_slice($ids, $remaining);

        foreach ( $to_process as $id ) {
            if ( 'attachment' !== get_post_type( $id ) ) {
                $results[] = array('id'=>$id, 'error'=>'invalid_attachment'); continue;
            }
            $url = wp_get_attachment_url( $id );
            if( ! $url ){ $results[] = array('id'=>$id, 'error'=>'missing_url'); continue; }
            $resp = wp_remote_get( $url, array('timeout'=>20) );
            if ( is_wp_error($resp) ) { $results[] = array('id'=>$id, 'error'=>$resp->get_error_message()); continue; }
            $bytes = wp_remote_retrieve_body( $resp );
            if ( empty($bytes) ) { $results[] = array('id'=>$id, 'error'=>'empty_image'); continue; }
            $mime = get_post_mime_type( $id );
            if ( ! in_array( $mime, ['image/jpeg','image/png','image/webp'], true ) ) { $mime = 'image/jpeg'; }

            $gen = $this->call_gemini_image($bytes, $mime, $focus, $lang, $maxTitle, $maxAlt);
            if (is_wp_error($gen)) {
                $results[] = array('id'=>$id, 'error'=>$gen->get_error_message());
            } else {
                $results[] = array('id'=>$id) + $gen;
                $this->inc_usage(1);
            }
        }
        foreach ( $skipped as $id ) {
            $results[] = array('id'=>$id, 'error'=>'daily_limit_exceeded_queue_or_retry_tomorrow');
        }

        $usage = $this->get_usage();
        $remaining_after = max(0, $limit - intval($usage['count']));
        return rest_ensure_response( array('results'=>$results, 'remaining'=>$remaining_after) );
    }

    private function call_gemini_image($bytes, $mime, $focus, $language, $maxTitle, $maxAlt) {
        $opts = get_option(self::TASS_OPT_GROUP, []);
        $api_key = $opts[self::TASS_OPT_GEMINI_KEY] ?? '';
        $model = $opts[self::TASS_OPT_MODEL] ?? 'gemini-1.5-flash';
        if ( empty($api_key) ) { return new WP_Error('no_key', 'Imposta la Gemini API Key.'); }
    
        $b64 = base64_encode( $bytes );
        $prompt = "Sei un assistente SEO. Analizza l'immagine e genera metadati in {$language}. ";
        if ( !empty($focus) ) { $prompt .= "Integra la keyword: '{$focus}'. "; }
        $prompt .= "Rispondi in JSON:
{
  \"title\": \"Un titolo SEO rilevante (massimo {$maxTitle} caratteri)\",
  \"alt\": \"Testo ALT breve e descrittivo (massimo {$maxAlt} caratteri)\",
  \"description\": \"Una descrizione dell'immagine dettagliata per il campo descrizione del media (massimo 150 caratteri)\",
  \"caption\": \"Una breve didascalia per l'immagine\"
}";
        
        $body = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inlineData' => [
                                'mimeType' => $mime,
                                'data' => $b64,
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.4,
                'response_mime_type' => 'application/json'
            ],
        ];

        $endpoint = trailingslashit('https://generativelanguage.googleapis.com/v1beta') . 'models/' . rawurlencode($model) . ':generateContent';
        $url = add_query_arg(['key' => $api_key], $endpoint);
        $resp = wp_remote_post($url, array('headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($body), 'timeout' => 45));

        if ( is_wp_error($resp) ) { return new WP_Error('http_error', $resp->get_error_message()); }

        $code = wp_remote_retrieve_response_code( $resp );
        $json = json_decode( wp_remote_retrieve_body($resp), true );
        
        if ( $code < 200 || $code >= 300 ) {
            $msg = 'Errore Gemini API (HTTP ' . $code . ')';
            if ( !empty($json['error']) && !empty($json['error']['message']) ) {
                $msg .= ': ' . $json['error']['message'];
            }
            if( strpos(strtolower($msg), 'gemini-1.5-flash') !== false && strpos(strtolower($msg), 'permission denied') !== false){
                 $msg = 'API Key non valida, o non ha i permessi necessari. Controlla le tue credenziali e che il modello gemini-1.5-flash sia disponibile.';
            }
            if( strpos(strtolower($msg), '403') !== false){
                $msg .= ' Probabile causa: 1) API Key non valida, 2) la tua API ha restriction di referrer per chiamate server-side, 3) il modello non è disponibile nella tua regione.';
            }
            return new WP_Error('http_error', $msg);
        }

        $text = '';
        if ( !empty($json['candidates'][0]['content']['parts']) ) {
            foreach ( $json['candidates'][0]['content']['parts'] as $p ) {
                if ( !empty($p['text']) ) { $text .= $p['text']; }
            }
        }
        if ( empty($text) && !empty($json['candidates'][0]['content']['parts'][0]['text']) ) {
            $text = $json['candidates'][0]['content']['parts'][0]['text'];
        }
        if ( empty($text) ) { return new WP_Error('bad_response', 'Risposta vuota dal provider'); }

        // Estrai JSON dal testo
        $decoded = null;
        if ( preg_match('/\\{.*\\}/s', $text, $m) ) {
            $decoded = json_decode($m[0], true);
        }
        if ( !is_array($decoded) ) {
            $decoded = array(
                'title'       => wp_trim_words( strip_tags($text), 10, '' ),
                'alt'         => wp_trim_words( strip_tags($text), 20, '' ),
                'description' => $text,
                'caption'     => '',
            );
        }

        $title = mb_substr( sanitize_text_field($decoded['title'] ?? ''), 0, $maxTitle );
        $alt   = mb_substr( sanitize_text_field($decoded['alt'] ?? ''), 0, $maxAlt );
        $desc  = mb_substr( sanitize_textarea_field($decoded['description'] ?? ''), 0, 150 );
        $cap   = mb_substr( sanitize_text_field($decoded['caption'] ?? ''), 0, 150 );

        return ['title' => $title, 'alt' => $alt, 'description' => $desc, 'caption' => $cap];
    }
    
    public function rest_apply_image( WP_REST_Request $req ) {
        if ( ! wp_verify_nonce( $req->get_header('X-WP-Nonce'), 'wp_rest' ) ) {
            return new WP_Error( 'bad_nonce', 'Nonce non valida', array('status'=>403) );
        }
        $id = intval($req->get_param('attachment_id'));
        if ( 'attachment' !== get_post_type($id) ) { return new WP_Error( 'invalid_attachment', 'ID allegato non valido.', array('status'=>404) ); }
        if ( !current_user_can('upload_files') ) { return new WP_Error( 'forbidden', 'Permessi insufficienti.', array('status'=>403) ); }
        
        $title = sanitize_text_field($req->get_param('title'));
        $alt = sanitize_text_field($req->get_param('alt'));
        $desc = sanitize_textarea_field($req->get_param('description'));

        if( !empty($title) ){ wp_update_post( ['ID'=>$id, 'post_title'=>$title] ); }
        if( !empty($alt) ){ update_post_meta($id, '_wp_attachment_image_alt', $alt); }
        if( !empty($desc) ){ wp_update_post( ['ID'=>$id, 'post_content'=>$desc] ); }
        
        return rest_ensure_response( array('success'=>true) );
    }

    // ========== INTEGRAZIONE WOOCOMMERCE ==========
    
    public function add_woocommerce_metabox() {
        echo '<div class="options_group">';
        echo '<h4>SEO Suite - Ottimizzazione Prodotti</h4>';
        
        wp_nonce_field(self::TASS_NONCE, self::TASS_NONCE);
        
        $product_id = get_the_ID();
        $title = get_post_meta($product_id, self::TASS_META_TITLE, true);
        $desc = get_post_meta($product_id, self::TASS_META_DESC, true);
        $focus = get_post_meta($product_id, self::TASS_META_FOCUS, true);
        $kw = get_post_meta($product_id, self::TASS_META_KW, true);
        
        // Campo Focus Keyword
        woocommerce_wp_text_input([
            'id' => self::TASS_META_FOCUS,
            'label' => __('Keyword Target', 'tag-agency-seo-suite'),
            'placeholder' => 'es. smartphone android economico',
            'value' => $focus,
            'desc_tip' => true,
            'description' => __('Inserisci la keyword principale per questo prodotto', 'tag-agency-seo-suite')
        ]);
        
        // Campo Meta Title
        woocommerce_wp_text_input([
            'id' => self::TASS_META_TITLE,
            'label' => __('Meta Title SEO', 'tag-agency-seo-suite'),
            'placeholder' => __('Titolo ottimizzato per SEO (max 65 caratteri)', 'tag-agency-seo-suite'),
            'value' => $title,
            'desc_tip' => true,
            'description' => __('Titolo che apparirà nei risultati di ricerca', 'tag-agency-seo-suite')
        ]);
        
        // Campo Meta Description
        woocommerce_wp_textarea_input([
            'id' => self::TASS_META_DESC,
            'label' => __('Meta Description', 'tag-agency-seo-suite'),
            'placeholder' => __('Descrizione ottimizzata per SEO (max 150 caratteri)', 'tag-agency-seo-suite'),
            'value' => $desc,
            'desc_tip' => true,
            'description' => __('Descrizione che apparirà nei risultati di ricerca', 'tag-agency-seo-suite')
        ]);
        
        // Campo Keywords
        woocommerce_wp_text_input([
            'id' => self::TASS_META_KW,
            'label' => __('Keywords aggiuntive', 'tag-agency-seo-suite'),
            'placeholder' => 'keyword1, keyword2, keyword3',
            'value' => $kw,
            'desc_tip' => true,
            'description' => __('Keyword aggiuntive separate da virgola', 'tag-agency-seo-suite')
        ]);
        
        // Pulsanti azione
        echo '<p class="form-field">';
        echo '<button type="button" class="button button-primary tass-generate-product" data-product="' . $product_id . '">' . __('Genera Meta con AI', 'tag-agency-seo-suite') . '</button>';
        echo ' <button type="button" class="button tass-quality-product" data-product="' . $product_id . '">' . __('Controllo Qualità', 'tag-agency-seo-suite') . '</button>';
        echo ' <button type="button" class="button button-secondary tass-apply-yoast-product" data-product="' . $product_id . '">' . __('Applica a Yoast', 'tag-agency-seo-suite') . '</button>';
        echo '</p>';
        
        echo '</div>';
    }

    public function add_variation_metabox($loop, $variation_data, $variation) {
        echo '<div class="options_group" style="border: 1px solid #ddd; padding: 10px; margin: 10px 0;">';
        echo '<h4>SEO Suite - Variazione</h4>';
        
        $title = get_post_meta($variation->ID, self::TASS_META_TITLE, true);
        $desc = get_post_meta($variation->ID, self::TASS_META_DESC, true);
        
        // Meta Title per variazione
        woocommerce_wp_text_input([
            'id' => 'tass_variation_title[' . $variation->ID . ']',
            'name' => 'tass_variation_title[' . $variation->ID . ']',
            'label' => __('Meta Title Variazione', 'tag-agency-seo-suite'),
            'value' => $title,
            'wrapper_class' => 'form-row form-row-first'
        ]);
        
        // Meta Description per variazione
        woocommerce_wp_textarea_input([
            'id' => 'tass_variation_desc[' . $variation->ID . ']',
            'name' => 'tass_variation_desc[' . $variation->ID . ']',
            'label' => __('Meta Description Variazione', 'tag-agency-seo-suite'),
            'value' => $desc,
            'wrapper_class' => 'form-row form-row-last'
        ]);
        
        echo '</div>';
    }

    public function save_woocommerce_meta($product_id) {
        if (!isset($_POST[self::TASS_NONCE]) || !wp_verify_nonce($_POST[self::TASS_NONCE], self::TASS_NONCE)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        
        $fields = [
            self::TASS_META_TITLE,
            self::TASS_META_DESC,
            self::TASS_META_KW,
            self::TASS_META_FOCUS
        ];
        
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($product_id, $field, sanitize_text_field($_POST[$field]));
            }
        }
    }

    public function save_variation_meta($variation_id, $i) {
        if (isset($_POST['tass_variation_title'][$variation_id])) {
            update_post_meta($variation_id, self::TASS_META_TITLE, sanitize_text_field($_POST['tass_variation_title'][$variation_id]));
        }
        if (isset($_POST['tass_variation_desc'][$variation_id])) {
            update_post_meta($variation_id, self::TASS_META_DESC, sanitize_textarea_field($_POST['tass_variation_desc'][$variation_id]));
        }
    }

    public function enhance_structured_data($markup, $product) {
        $product_id = $product->get_id();
        
        // Aggiungi meta title personalizzato
        $custom_title = get_post_meta($product_id, self::TASS_META_TITLE, true);
        if (!empty($custom_title)) {
            $markup['name'] = $custom_title;
        }
        
        // Aggiungi meta description personalizzata
        $custom_desc = get_post_meta($product_id, self::TASS_META_DESC, true);
        if (!empty($custom_desc)) {
            $markup['description'] = $custom_desc;
        }
        
        // Aggiungi keywords
        $keywords = get_post_meta($product_id, self::TASS_META_KW, true);
        if (!empty($keywords)) {
            $markup['keywords'] = $keywords;
        }
        
        return $markup;
    }

    private function generate_meta_for_product($product_id, $focus_kw = '') {
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('invalid_product', 'Prodotto non valido');
        }
        
        $product_data = [
            'name' => $product->get_name(),
            'description' => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'price' => $product->get_price(),
            'sku' => $product->get_sku(),
            'categories' => wp_strip_all_tags($product->get_categories()),
            'attributes' => $this->get_product_attributes($product)
        ];
        
        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $opts = get_option(self::TASS_OPT_GROUP, []);
        $api_key = trim($opts[self::TASS_OPT_GEMINI_KEY] ?? '');
        $model = trim($opts[self::TASS_OPT_MODEL] ?? 'gemini-1.5-flash');
        $lang = trim($opts[self::TASS_OPT_LANG] ?? 'it_IT');
        
        if (!$api_key || !$model) {
            return new WP_Error('config', 'Configura Gemini API Key e Model nelle impostazioni.');
        }
        
        $prompt_user = "Sito e-commerce: {$site_name}\n";
        $prompt_user .= "Prodotto: {$product_data['name']}\n";
        $prompt_user .= "Prezzo: {$product_data['price']}\n";
        if (!empty($product_data['sku'])) {
            $prompt_user .= "SKU: {$product_data['sku']}\n";
        }
        if (!empty($focus_kw)) {
            $prompt_user .= "Keyword target: {$focus_kw}\n";
        }
        $prompt_user .= "Categorie: {$product_data['categories']}\n";
        $prompt_user .= "Descrizione prodotto:\n{$product_data['description']}\n";
        $prompt_user .= "Descrizione breve:\n{$product_data['short_description']}\n";
        
        $system = "Sei un assistente SEO specializzato in e-commerce. Genera meta title (<= 65 caratteri), meta description (<= 150 caratteri), e keywords per prodotti in {$lang}. Includi prezzo e USP (Unique Selling Proposition). Rispondi SOLO JSON {title,description,keywords}.";
        
        $resp = $this->call_gemini_generate_content($api_key, $model, $system, $prompt_user);
        if (is_wp_error($resp)) return $resp;
        
        $this->rl_bump();
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        $text = '';
        
        if (is_array($data) && !empty($data['candidates'][0]['content']['parts'])) {
            foreach ($data['candidates'][0]['content']['parts'] as $p) {
                if (!empty($p['text'])) $text .= $p['text'] . "\n";
            }
            $text = trim($text);
        }
        
        $parsed = $this->parse_json_from_text($text);
        if (!is_array($parsed)) {
            $parsed = [
                'title' => $this->hard_limit($text, 65),
                'description' => $this->hard_limit($text, 150),
                'keywords' => ''
            ];
        }
        
        $kwField = $parsed['keywords'] ?? '';
        if (is_array($kwField)) $kwField = implode(', ', array_map('trim', $kwField));
        
        $title = $this->hard_limit($parsed['title'] ?? '', 65);
        $desc = $this->truncate_meta_desc($parsed['description'] ?? '', 150);
        $kw = $this->hard_limit($kwField, 300);
        
        update_post_meta($product_id, self::TASS_META_TITLE, $title);
        update_post_meta($product_id, self::TASS_META_DESC, $desc);
        update_post_meta($product_id, self::TASS_META_KW, $kw);
        if ($focus_kw) update_post_meta($product_id, self::TASS_META_FOCUS, $focus_kw);
        
        return [
            'product_id' => $product_id,
            'title' => $title,
            'description' => $desc,
            'keywords' => $kw
        ];
    }

    private function get_product_attributes($product) {
        $attributes = [];
        foreach ($product->get_attributes() as $attribute) {
            $attributes[] = $attribute->get_name() . ': ' . implode(', ', $attribute->get_options());
        }
        return implode(', ', $attributes);
    }

    public function ajax_generate_meta_product() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], self::TASS_NONCE)) {
            wp_send_json_error(['error' => 'Nonce non valido']);
        }
        
        $product_id = intval($_POST['product_id'] ?? 0);
        if (!$product_id || !current_user_can('edit_product', $product_id)) {
            wp_send_json_error(['error' => 'Permesso negato']);
        }
        
        $rl = $this->rl_check();
        if (is_wp_error($rl)) {
            wp_send_json_error(['error' => $rl->get_error_message()]);
        }
        
        $focus_kw = sanitize_text_field($_POST['focus_kw'] ?? '');
        $res = $this->generate_meta_for_product($product_id, $focus_kw);
        
        is_wp_error($res) ? wp_send_json_error(['error' => $res->get_error_message()]) : wp_send_json_success($res);
    }

    // ANALISI QUALITÀ PRODOTTI WOOCOMMERCE
    public function ajax_quality_check_product() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], self::TASS_NONCE)) {
            wp_send_json_error(['error' => 'Nonce non valido']);
        }
        
        $product_id = intval($_POST['product_id'] ?? 0);
        if (!$product_id || !current_user_can('edit_product', $product_id)) {
            wp_send_json_error(['error' => 'Permesso negato']);
        }
        
        $score_data = $this->compute_quality_score_product($product_id);
        update_post_meta($product_id, self::TASS_META_SCORE, $score_data['score']);
        wp_send_json_success($score_data);
    }

    private function compute_quality_score_product($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return ['score' => 0, 'details' => ['error' => 'Prodotto non trovato']];
        }
        
        $focus = get_post_meta($product_id, self::TASS_META_FOCUS, true);
        $focus_primary = strpos($focus, ',') !== false ? trim(explode(',', $focus, 2)[0]) : $focus;
        $title = get_post_meta($product_id, self::TASS_META_TITLE, true);
        $desc = get_post_meta($product_id, self::TASS_META_DESC, true);
        
        // Dati del prodotto
        $product_name = $product->get_name();
        $product_description = $product->get_description();
        $short_description = $product->get_short_description();
        $price = $product->get_price();
        $regular_price = $product->get_regular_price();
        $sale_price = $product->get_sale_price();
        $sku = $product->get_sku();
        $stock_status = $product->get_stock_status();
        $categories = wp_strip_all_tags($product->get_categories());
        $tags = wp_strip_all_tags($product->get_tags());
        
        // Contenuto combinato per analisi
        $content = $product_name . ' ' . $short_description . ' ' . $product_description . ' ' . $categories . ' ' . $tags;
        $text = $this->strip($content);
        $words = str_word_count($text, 0, 'ÀÈÉÌÍÒÓÙÚàèéìíòóùúçÇ');
        
        // Analisi immagini
        $image_id = $product->get_image_id();
        $gallery_ids = $product->get_gallery_image_ids();
        $total_images = ($image_id ? 1 : 0) + count($gallery_ids);
        
        // Controllo ALT immagini
        $images_with_alt = 0;
        if ($image_id) {
            $alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
            if (!empty($alt)) $images_with_alt++;
        }
        foreach ($gallery_ids as $gallery_id) {
            $alt = get_post_meta($gallery_id, '_wp_attachment_image_alt', true);
            if (!empty($alt)) $images_with_alt++;
        }
        
        // Metriche specifiche prodotti
        $title_ok = (mb_strlen($title) >= 30 && mb_strlen($title) <= 65);
        $desc_ok = (mb_strlen($desc) >= 100 && mb_strlen($desc) <= 150);
        $has_short_desc = !empty($short_description);
        $short_desc_ok = (mb_strlen($short_description) >= 50 && mb_strlen($short_description) <= 200);
        $has_price = !empty($price);
        $has_sku = !empty($sku);
        $has_categories = !empty($categories);
        $has_main_image = (bool) $image_id;
        $has_gallery = (count($gallery_ids) >= 1);
        $in_stock = ($stock_status === 'instock');
        
        // Densità keyword
        $density_ok = true;
        $density = 0;
        if ($focus_primary) {
            $occ = mb_substr_count(mb_strtolower($text), mb_strtolower($focus_primary));
            $density = $words ? ($occ / max($words, 1)) * 100 : 0;
            $density_ok = ($density >= 0.5 && $density <= 2.5);
        }
        
        // Leggibilità
        $sentences = max(1, preg_match_all('/[\.!\?]+/u', $text, $m));
        $syllables = max(1, preg_match_all('/[aeiouàèéìíòóùú]/iu', $text, $m2));
        $flesch = 206.835 - 1.015 * ($words / $sentences) - 84.6 * ($syllables / max($words, 1));
        $read_ok = ($flesch >= 50);
        
        // Calcolo punteggio per e-commerce
        $score = 0;
        
        // SEO Base (30 punti)
        $score += ($title_ok ? 10 : 0);
        $score += ($desc_ok ? 10 : 0);
        $score += ($density_ok ? 10 : 0);
        
        // Contenuto prodotto (25 punti)
        $score += ($has_short_desc ? 5 : 0);
        $score += ($short_desc_ok ? 5 : 0);
        $score += ($has_categories ? 5 : 0);
        $score += ($read_ok ? 5 : 0);
        $score += (($words >= 200) ? 5 : 0);
        
        // Immagini (20 punti)
        $score += ($has_main_image ? 10 : 0);
        $score += ($has_gallery ? 5 : 0);
        $score += (($total_images > 0 && $images_with_alt === $total_images) ? 5 : 0);
        
        // Dati prodotto (15 punti)
        $score += ($has_price ? 5 : 0);
        $score += ($has_sku ? 5 : 0);
        $score += ($in_stock ? 5 : 0);
        
        // Prezzo scontato (10 punti)
        if (!empty($sale_price) && !empty($regular_price)) {
            $discount_percentage = (($regular_price - $sale_price) / $regular_price) * 100;
            $score += ($discount_percentage > 0) ? 10 : 0;
        }
        
        $score = min(100, $score);
        
        return [
            'score' => $score,
            'details' => [
                // SEO
                'title_length' => mb_strlen($title),
                'description_length' => mb_strlen($desc),
                'title_ok' => $title_ok,
                'description_ok' => $desc_ok,
                
                // Prodotto
                'product_name' => $product_name,
                'has_short_description' => $has_short_desc,
                'short_description_length' => mb_strlen($short_description),
                'short_description_ok' => $short_desc_ok,
                'word_count' => $words,
                'has_price' => $has_price,
                'price' => $price,
                'has_sku' => $has_sku,
                'sku' => $sku,
                'in_stock' => $in_stock,
                'stock_status' => $stock_status,
                'has_categories' => $has_categories,
                'categories' => $categories,
                
                // Immagini
                'has_main_image' => $has_main_image,
                'has_gallery' => $has_gallery,
                'total_images' => $total_images,
                'images_with_alt' => $images_with_alt,
                'gallery_count' => count($gallery_ids),
                
                // Keyword
                'focus_kw' => $focus,
                'focus_primary' => $focus_primary,
                'focus_density_pct' => round($density, 2),
                'density_ok' => $density_ok,
                
                // Leggibilità
                'flesch_estimate' => round($flesch, 1),
                'readability_ok' => $read_ok,
                
                // Promozioni
                'has_discount' => !empty($sale_price) && !empty($regular_price),
                'regular_price' => $regular_price,
                'sale_price' => $sale_price,
                'discount_percentage' => !empty($sale_price) && !empty($regular_price) ? 
                    round((($regular_price - $sale_price) / $regular_price) * 100, 1) : 0,
                
                // Riepilogo checks
                'checks' => [
                    'title_ok' => $title_ok,
                    'description_ok' => $desc_ok,
                    'short_description_ok' => $short_desc_ok,
                    'has_price' => $has_price,
                    'has_sku' => $has_sku,
                    'in_stock' => $in_stock,
                    'has_categories' => $has_categories,
                    'has_main_image' => $has_main_image,
                    'has_gallery' => $has_gallery,
                    'images_alt_ok' => ($total_images > 0 && $images_with_alt === $total_images),
                    'density_ok' => $density_ok,
                    'readability_ok' => $read_ok,
                    'has_discount' => (!empty($sale_price) && !empty($regular_price))
                ]
            ]
        ];
    }

    // ========== INTEGRAZIONE WPML ==========
    
    public function add_custom_meta_to_wpml($settings) {
        $custom_meta = [
            self::TASS_META_TITLE => ['encode' => 0],
            self::TASS_META_DESC => ['encode' => 0],
            self::TASS_META_KW => ['encode' => 0],
            self::TASS_META_FOCUS => ['encode' => 0],
            self::TASS_META_SCORE => ['encode' => 0]
        ];
        
        if (!isset($settings['custom_fields_translation'])) {
            $settings['custom_fields_translation'] = [];
        }
        
        $settings['custom_fields_translation'] = array_merge(
            $settings['custom_fields_translation'],
            $custom_meta
        );
        
        return $settings;
    }

    public function sync_translations_meta($original_post_id, $translations, $post_type) {
        if (!function_exists('wpml_get_default_language')) return;
        
        $default_lang = wpml_get_default_language();
        $source_post_id = null;
        
        // Trova il post sorgente
        foreach ($translations as $lang => $translation) {
            if ($lang === $default_lang) {
                $source_post_id = $translation->element_id;
                break;
            }
        }
        
        if (!$source_post_id) return;
        
        // Meta da sincronizzare
        $meta_fields = [
            self::TASS_META_TITLE,
            self::TASS_META_DESC,
            self::TASS_META_KW,
            self::TASS_META_FOCUS
        ];
        
        foreach ($meta_fields as $meta_field) {
            $meta_value = get_post_meta($source_post_id, $meta_field, true);
            if (!empty($meta_value)) {
                update_post_meta($original_post_id, $meta_field, $meta_value);
            }
        }
    }

    public function translate_meta($value, $post_id) {
        if (!function_exists('wpml_get_current_language')) {
            return $value;
        }
        
        $current_lang = wpml_get_current_language();
        $default_lang = wpml_get_default_language();
        
        if ($current_lang === $default_lang) {
            return $value;
        }
        
        // Cerca la traduzione del meta field
        $translated_meta = get_post_meta($post_id, self::TASS_META_TITLE . '_' . $current_lang, true);
        
        return !empty($translated_meta) ? $translated_meta : $value;
    }

    public function generate_multilingual_meta($post_id, $focus_kw = '', $target_language = '') {
        if (empty($target_language)) {
            $target_language = $this->get_current_language();
        }
        
        $original_content = $this->get_post_content_for_translation($post_id);
        $translated_content = $this->translate_content($original_content, $target_language);
        
        if (is_wp_error($translated_content)) {
            return $translated_content;
        }
        
        // Usa il contenuto tradotto per generare i meta
        return $this->generate_meta_from_content($translated_content, $focus_kw, $target_language);
    }

    private function get_current_language() {
        if (function_exists('wpml_get_current_language')) {
            return wpml_get_current_language();
        }
        
        if (function_exists('pll_current_language')) {
            return pll_current_language();
        }
        
        return get_locale();
    }

    private function translate_content($content, $target_language) {
        // Qui puoi integrare con Google Translate API o servizi simili
        // Per ora restituiamo il contenuto originale
        return $content;
    }
}

// Inizializzazione
$tag_agency_seo_suite = new Tag_Agency_SEO_Suite();