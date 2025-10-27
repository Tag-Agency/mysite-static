<?php
/*
Plugin Name: Prenotazione Ristorante PRO
Description: Prenotazioni via email (no WhatsApp). DB + thank you page, testi email personalizzabili, filtri/ordinamenti, eliminazione massiva, export CSV, stati (nuova/confermata/rinunciata) con conferma (modifica orario) o rinuncia da admin. reCAPTCHA v3 e limite per slot opzionali. Formato date dd-mm-yyyy.
Version: 2.12.0
Author: Tag Agency
*/

if (!defined('ABSPATH')) exit;

/* =========================
 * Default settings
 * ========================= */
function prp_default_settings(){
  return [
    'title' => 'Prenotazione Ristorante',
    // Slot & tempi
    'lunch_start' => '12:00',
    'lunch_end'   => '13:30',
    'dinner_start'=> '20:00',
    'dinner_end'  => '22:00',
    'step_minutes'=> 30,
    // Ospiti
    'guests'      => '1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,+15',
    // Campi opzionali
    'show_notes' => 1,
    'notes_label' => 'Note (es. ricorrenze, preferenze tavolo)',
    'show_allergies' => 1,
    'allergies_label' => 'Allergie / Intolleranze',
    'show_preview'=> 1,
    'button_text' => 'Invia richiesta',
    // Limiti data
    'min_date_offset_days' => 0,
    'max_date_offset_days' => 365,
    'disable_weekdays' => '',
    'closed_dates' => '',
    // Tema
    'primary_color' => '#111111',
    'bg_color' => '#ffffff',
    'text_color' => '#111111',
    'radius' => 12,
    'font_family' => 'inherit',
    'dark_mode' => 0,
    // Privacy
    'privacy_url' => '',
    'privacy_text' => 'Dichiaro di aver preso visione dell’informativa sulla privacy e acconsento al trattamento dei dati personali secondo quanto stabilito dal regolamento europeo n. 679/2016 (GDPR).',
    'marketing_text' => 'Acconsento al trattamento dei dati personali per l’invio di comunicazioni promozionali tramite mailing list, sms e whatsapp marketing.',
    // reCAPTCHA (opz.)
    'recaptcha_enabled' => 0,
    'recaptcha_site_key' => '',
    'recaptcha_secret_key' => '',
    // Limite per slot (opz.)
    'slot_limit_enabled' => 0,
    'slot_limit_number' => 0,
    // Thank you
    'thankyou_message' => 'Grazie! Abbiamo ricevuto la tua richiesta. Ti contatteremo a breve dopo aver verificato la disponibilità.',
    'redirect_enabled' => 0,
    'redirect_url' => '',
    'redirect_delay' => 4,
    // Email — ricevuta iniziale
    'notify_admin_enabled' => 1,
    'notify_admin_email' => '',
    'email_admin_subject' => 'Nuova richiesta di prenotazione',
    'email_admin_body' => "Hai ricevuto una nuova richiesta:\n\nCliente: {NOME} {COGNOME}\nTelefono: {TELEFONO}\nEmail: {EMAIL}\nData: {DATA} • {SERVIZIO} @ {FASCIA}\nOspiti: {OSPITI}\nNote: {NOTE}\nAllergie: {ALLERGIE}\n\nPagina: {SOURCE}\n",
    'email_customer_subject' => 'Abbiamo ricevuto la tua richiesta di prenotazione',
    'email_customer_body' => "Ciao {NOME},\n\nti confermiamo la ricezione della tua richiesta per il {DATA} • {SERVIZIO} @ {FASCIA}.\nIl ristorante sta verificando la disponibilità e ti ricontatterà a breve.\n\nDettagli:\nOspiti: {OSPITI}\nTelefono: {TELEFONO}\nNote: {NOTE}\nAllergie: {ALLERGIE}\n\nGrazie!",
    // Email — cambi stato
    'email_status_confirm_subject' => 'Prenotazione CONFERMATA: {DATA} • {SERVIZIO} @ {FASCIA}',
    'email_status_confirm_body' => "Ciao {NOME},\n\nti confermiamo la prenotazione per il {DATA} • {SERVIZIO} alle {FASCIA}.\nSe l'orario è stato aggiornato rispetto alla richiesta iniziale, questo è l'orario definitivo confermato.\n\nA presto!\n",
    'email_status_cancel_subject' => 'Prenotazione non disponibile',
    'email_status_cancel_body' => "Ciao {NOME},\n\npurtroppo non possiamo accogliere la prenotazione per il {DATA} • {SERVIZIO} @ {FASCIA}.\nSe vuoi, rispondi a questa email indicando un orario alternativo o una data diversa.\n",
  ];
}
function prp_get_settings(){
  $d = prp_default_settings();
  $s = get_option('prp_settings', []);
  if (!is_array($s)) $s = [];
  return wp_parse_args($s, $d);
}

/* =========================
 * Helpers formati
 * ========================= */
function prp_db_to_it($ymd){
  if (!$ymd) return '';
  $p = explode('-', substr($ymd,0,10));
  if (count($p)!==3) return $ymd;
  return $p[2].'-'.$p[1].'-'.$p[0];
}
function prp_dt_to_it($dt){
  if (!$dt) return '';
  $ts = strtotime($dt);
  return $ts ? date_i18n('d-m-Y H:i', $ts) : $dt;
}
function prp_it_to_db($it){
  if (!$it) return '';
  if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/',$it,$m)){
    return $m[3].'-'.$m[2].'-'.$m[1];
  }
  return $it;
}
function prp_replace_placeholders($tpl, $data){
  $map = [
    '{NOME}' => trim($data['nome'] ?? ''),
    '{COGNOME}' => trim($data['cognome'] ?? ''),
    '{TELEFONO}' => $data['telefono'] ?? '',
    '{EMAIL}' => $data['email'] ?? '',
    '{DATA}' => prp_db_to_it($data['data_prenotazione'] ?? ''),
    '{SERVIZIO}' => strtoupper($data['servizio'] ?? ''),
    '{FASCIA}' => $data['fascia'] ?? '',
    '{OSPITI}' => $data['ospiti'] ?? '',
    '{NOTE}' => $data['note'] ?? '',
    '{ALLERGIE}' => $data['allergie'] ?? '',
    '{SOURCE}' => $data['source_url'] ?? '',
  ];
  return strtr($tpl, $map);
}

/* =========================
 * Attivazione / DB
 * ========================= */
function prp_activate(){ prp_maybe_update_db(); }
register_activation_hook(__FILE__, 'prp_activate');

function prp_maybe_update_db(){
  global $wpdb;
  $table = $wpdb->prefix . 'prp_requests';
  $charset_collate = $wpdb->get_charset_collate();
  $sql = "CREATE TABLE $table (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    nome VARCHAR(100) DEFAULT '' NOT NULL,
    cognome VARCHAR(100) DEFAULT '' NOT NULL,
    telefono VARCHAR(40) DEFAULT '' NOT NULL,
    email VARCHAR(190) DEFAULT '' NOT NULL,
    data_prenotazione DATE NOT NULL,
    servizio ENUM('pranzo','cena') NOT NULL,
    fascia VARCHAR(20) DEFAULT '' NOT NULL,
    ospiti VARCHAR(10) DEFAULT '' NOT NULL,
    note TEXT NULL,
    allergie TEXT NULL,
    note_interne TEXT NULL,
    stato ENUM('nuova','confermata','rinunciata') NOT NULL DEFAULT 'nuova',
    privacy_ok TINYINT(1) NOT NULL DEFAULT 0,
    marketing_ok TINYINT(1) NOT NULL DEFAULT 0,
    source_url TEXT NULL,
    PRIMARY KEY (id),
    KEY data_idx (data_prenotazione),
    KEY created_idx (created_at),
    KEY servizio_idx (servizio),
    KEY stato_idx (stato)
  ) $charset_collate;";
  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  dbDelta($sql);
}
add_action('admin_init','prp_maybe_update_db');

/* =========================
 * Settings (Generali / Email)
 * ========================= */
function prp_register_settings(){
  register_setting('prp_settings_group', 'prp_settings', [
    'type'=>'array',
    'sanitize_callback'=>function($in){
      $d = prp_default_settings(); $o = [];
      // generali
      $o['title'] = sanitize_text_field($in['title'] ?? $d['title']);
      foreach(['lunch_start','lunch_end','dinner_start','dinner_end'] as $k){
        $o[$k] = preg_match('/^\d{2}:\d{2}$/', ($in[$k] ?? '')) ? $in[$k] : $d[$k];
      }
      $o['step_minutes'] = max(5, intval($in['step_minutes'] ?? $d['step_minutes']));
      $o['guests'] = implode(',', array_map('sanitize_text_field', array_filter(array_map('trim', explode(',', (string)($in['guests'] ?? $d['guests']))))));
      $o['show_notes'] = empty($in['show_notes']) ? 0 : 1;
      $o['notes_label'] = sanitize_text_field($in['notes_label'] ?? $d['notes_label']);
      $o['show_allergies'] = empty($in['show_allergies']) ? 0 : 1;
      $o['allergies_label'] = sanitize_text_field($in['allergies_label'] ?? $d['allergies_label']);
      $o['show_preview'] = empty($in['show_preview']) ? 0 : 1;
      $o['button_text'] = sanitize_text_field($in['button_text'] ?? $d['button_text']);
      $o['min_date_offset_days'] = max(0, intval($in['min_date_offset_days'] ?? $d['min_date_offset_days']));
      $o['max_date_offset_days'] = max(0, intval($in['max_date_offset_days'] ?? $d['max_date_offset_days']));
      $o['disable_weekdays'] = implode(',', array_map('intval', array_filter(array_map('trim', explode(',', (string)($in['disable_weekdays'] ?? $d['disable_weekdays']))))));
      $o['closed_dates'] = implode(',', array_map(function($x){ return preg_match('/^\d{4}-\d{2}-\d{2}$/',$x)?$x:''; }, array_filter(array_map('trim', explode(',', (string)($in['closed_dates'] ?? $d['closed_dates']))))));
      $o['primary_color'] = sanitize_hex_color($in['primary_color'] ?? $d['primary_color']);
      $o['bg_color'] = sanitize_hex_color($in['bg_color'] ?? $d['bg_color']);
      $o['text_color'] = sanitize_hex_color($in['text_color'] ?? $d['text_color']);
      $o['radius'] = max(0, intval($in['radius'] ?? $d['radius']));
      $o['font_family'] = sanitize_text_field($in['font_family'] ?? $d['font_family']);
      $o['dark_mode'] = empty($in['dark_mode']) ? 0 : 1;
      // privacy
      $o['privacy_url'] = esc_url_raw($in['privacy_url'] ?? $d['privacy_url']);
      $o['privacy_text'] = wp_kses_post($in['privacy_text'] ?? $d['privacy_text']);
      $o['marketing_text'] = wp_kses_post($in['marketing_text'] ?? $d['marketing_text']);
      // captcha/slot
      $o['recaptcha_enabled'] = empty($in['recaptcha_enabled']) ? 0 : 1;
      $o['recaptcha_site_key'] = sanitize_text_field($in['recaptcha_site_key'] ?? $d['recaptcha_site_key']);
      $o['recaptcha_secret_key'] = sanitize_text_field($in['recaptcha_secret_key'] ?? $d['recaptcha_secret_key']);
      $o['slot_limit_enabled'] = empty($in['slot_limit_enabled']) ? 0 : 1;
      $o['slot_limit_number'] = max(0, intval($in['slot_limit_number'] ?? $d['slot_limit_number']));
      // thank you
      $o['thankyou_message'] = wp_kses_post($in['thankyou_message'] ?? $d['thankyou_message']);
      $o['redirect_enabled'] = empty($in['redirect_enabled']) ? 0 : 1;
      $o['redirect_url'] = esc_url_raw($in['redirect_url'] ?? $d['redirect_url']);
      $o['redirect_delay'] = max(0, intval($in['redirect_delay'] ?? $d['redirect_delay']));
      // email — ricevuta iniziale
      $o['notify_admin_enabled'] = empty($in['notify_admin_enabled']) ? 0 : 1;
      $o['notify_admin_email'] = sanitize_email($in['notify_admin_email'] ?? $d['notify_admin_email']);
      $o['email_admin_subject'] = sanitize_text_field($in['email_admin_subject'] ?? $d['email_admin_subject']);
      $o['email_admin_body'] = wp_kses_post($in['email_admin_body'] ?? $d['email_admin_body']);
      $o['email_customer_subject'] = sanitize_text_field($in['email_customer_subject'] ?? $d['email_customer_subject']);
      $o['email_customer_body'] = wp_kses_post($in['email_customer_body'] ?? $d['email_customer_body']);
      // email — stati
      $o['email_status_confirm_subject'] = sanitize_text_field($in['email_status_confirm_subject'] ?? $d['email_status_confirm_subject']);
      $o['email_status_confirm_body'] = wp_kses_post($in['email_status_confirm_body'] ?? $d['email_status_confirm_body']);
      $o['email_status_cancel_subject'] = sanitize_text_field($in['email_status_cancel_subject'] ?? $d['email_status_cancel_subject']);
      $o['email_status_cancel_body'] = wp_kses_post($in['email_status_cancel_body'] ?? $d['email_status_cancel_body']);
      return $o;
    }
  ]);
}
add_action('admin_init','prp_register_settings');

function prp_settings_page(){
  $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'generali';
  $s = prp_get_settings();
  ?>
  <div class="wrap">
    <h1>Prenotazione Ristorante PRO — Impostazioni</h1>
    <h2 class="nav-tab-wrapper">
      <a href="<?php echo esc_url(admin_url('options-general.php?page=prp-settings&tab=generali')); ?>" class="nav-tab <?php echo $tab==='generali'?'nav-tab-active':''; ?>">Generali</a>
      <a href="<?php echo esc_url(admin_url('options-general.php?page=prp-settings&tab=email')); ?>" class="nav-tab <?php echo $tab==='email'?'nav-tab-active':''; ?>">Email</a>
    </h2>
    <form method="post" action="options.php">
      <?php settings_fields('prp_settings_group'); ?>
      <table class="form-table">
        <?php if ($tab==='email'): ?>
          <tr><th scope="row">Notifica admin</th><td><label><input type="checkbox" name="prp_settings[notify_admin_enabled]" value="1" <?php checked(1,$s['notify_admin_enabled']); ?>> Abilita invio email all'amministratore</label><br>
          <input type="email" class="regular-text" name="prp_settings[notify_admin_email]" value="<?php echo esc_attr($s['notify_admin_email']); ?>" placeholder="vuoto = admin_email"></td></tr>

          <tr><th>Oggetto (admin)</th><td><input type="text" class="regular-text" name="prp_settings[email_admin_subject]" value="<?php echo esc_attr($s['email_admin_subject']); ?>"></td></tr>
          <tr><th>Corpo (admin)</th><td><textarea name="prp_settings[email_admin_body]" class="large-text code" rows="8"><?php echo esc_textarea($s['email_admin_body']); ?></textarea></td></tr>

          <tr><th>Oggetto ricevuta (cliente)</th><td><input type="text" class="regular-text" name="prp_settings[email_customer_subject]" value="<?php echo esc_attr($s['email_customer_subject']); ?>"></td></tr>
          <tr><th>Corpo ricevuta (cliente)</th><td><textarea name="prp_settings[email_customer_body]" class="large-text code" rows="8"><?php echo esc_textarea($s['email_customer_body']); ?></textarea></td></tr>

          <tr><th>Oggetto conferma (cliente)</th><td><input type="text" class="regular-text" name="prp_settings[email_status_confirm_subject]" value="<?php echo esc_attr($s['email_status_confirm_subject']); ?>"></td></tr>
          <tr><th>Corpo conferma (cliente)</th><td><textarea name="prp_settings[email_status_confirm_body]" class="large-text code" rows="8"><?php echo esc_textarea($s['email_status_confirm_body']); ?></textarea></td></tr>

          <tr><th>Oggetto rinuncia (cliente)</th><td><input type="text" class="regular-text" name="prp_settings[email_status_cancel_subject]" value="<?php echo esc_attr($s['email_status_cancel_subject']); ?>"></td></tr>
          <tr><th>Corpo rinuncia (cliente)</th><td><textarea name="prp_settings[email_status_cancel_body]" class="large-text code" rows="8"><?php echo esc_textarea($s['email_status_cancel_body']); ?></textarea></td></tr>

          <tr><th>Segnaposto disponibili</th><td><code>{NOME} {COGNOME} {TELEFONO} {EMAIL} {DATA} {SERVIZIO} {FASCIA} {OSPITI} {NOTE} {ALLERGIE} {SOURCE}</code></td></tr>
        <?php else: ?>
          <tr><th>Titolo modulo</th><td><input type="text" class="regular-text" name="prp_settings[title]" value="<?php echo esc_attr($s['title']); ?>"></td></tr>
          <tr><th>Slot pranzo</th><td>Inizio <input type="text" name="prp_settings[lunch_start]" value="<?php echo esc_attr($s['lunch_start']); ?>" size="6"> — Fine <input type="text" name="prp_settings[lunch_end]" value="<?php echo esc_attr($s['lunch_end']); ?>" size="6"> — Step (min) <input type="number" name="prp_settings[step_minutes]" value="<?php echo esc_attr($s['step_minutes']); ?>" min="5" step="5" size="4"></td></tr>
          <tr><th>Slot cena</th><td>Inizio <input type="text" name="prp_settings[dinner_start]" value="<?php echo esc_attr($s['dinner_start']); ?>" size="6"> — Fine <input type="text" name="prp_settings[dinner_end]" value="<?php echo esc_attr($s['dinner_end']); ?>" size="6"></td></tr>
          <tr><th>Ospiti (CSV)</th><td><input type="text" class="regular-text" name="prp_settings[guests]" value="<?php echo esc_attr($s['guests']); ?>"></td></tr>
          <tr><th>Campi</th><td>
            <label><input type="checkbox" name="prp_settings[show_notes]" value="1" <?php checked(1,$s['show_notes']); ?>> Mostra campo Note</label><br>
            <label><input type="checkbox" name="prp_settings[show_allergies]" value="1" <?php checked(1,$s['show_allergies']); ?>> Mostra campo Allergie</label><br>
            <label><input type="checkbox" name="prp_settings[show_preview]" value="1" <?php checked(1,$s['show_preview']); ?>> Mostra anteprima riepilogo</label>
          </td></tr>
          <tr><th>Etichette</th><td>
            <input type="text" name="prp_settings[notes_label]" value="<?php echo esc_attr($s['notes_label']); ?>" class="regular-text"><br>
            <input type="text" name="prp_settings[allergies_label]" value="<?php echo esc_attr($s['allergies_label']); ?>" class="regular-text">
          </td></tr>
          <tr><th>Testo bottone</th><td><input type="text" name="prp_settings[button_text]" value="<?php echo esc_attr($s['button_text']); ?>" class="regular-text"></td></tr>
          <tr><th>Limiti data</th><td>Min <input type="number" name="prp_settings[min_date_offset_days]" value="<?php echo esc_attr($s['min_date_offset_days']); ?>" min="0" step="1"> giorni — Max <input type="number" name="prp_settings[max_date_offset_days]" value="<?php echo esc_attr($s['max_date_offset_days']); ?>" min="0" step="1"> giorni</td></tr>
          <tr><th>Giorni chiusi</th><td><input type="text" name="prp_settings[disable_weekdays]" value="<?php echo esc_attr($s['disable_weekdays']); ?>" class="regular-text" placeholder="0=Dom..6=Sab, es: 1,2"></td></tr>
          <tr><th>Date chiuse</th><td><input type="text" name="prp_settings[closed_dates]" value="<?php echo esc_attr($s['closed_dates']); ?>" class="regular-text" placeholder="YYYY-MM-DD,YYYY-MM-DD"></td></tr>
          <tr><th>Privacy</th><td>URL Privacy Policy <input type="url" name="prp_settings[privacy_url]" value="<?php echo esc_attr($s['privacy_url']); ?>" class="regular-text"><br>
            Testo privacy (obbligatoria):<br>
            <textarea name="prp_settings[privacy_text]" class="large-text" rows="3"><?php echo esc_textarea($s['privacy_text']); ?></textarea><br>
            Testo marketing (facoltativa):<br>
            <textarea name="prp_settings[marketing_text]" class="large-text" rows="3"><?php echo esc_textarea($s['marketing_text']); ?></textarea>
          </td></tr>
          <tr><th>Thank you</th><td>
            Messaggio:<br><textarea name="prp_settings[thankyou_message]" class="large-text" rows="4"><?php echo esc_textarea($s['thankyou_message']); ?></textarea><br>
            <label><input type="checkbox" name="prp_settings[redirect_enabled]" value="1" <?php checked(1,$s['redirect_enabled']); ?>> Redirect a URL</label><br>
            URL <input type="url" name="prp_settings[redirect_url]" value="<?php echo esc_attr($s['redirect_url']); ?>" class="regular-text"> — Ritardo (s) <input type="number" name="prp_settings[redirect_delay]" value="<?php echo esc_attr($s['redirect_delay']); ?>" min="0" step="1" size="4">
          </td></tr>
          <tr><th>reCAPTCHA v3 (opz.)</th><td>
            <label><input type="checkbox" name="prp_settings[recaptcha_enabled]" value="1" <?php checked(1,$s['recaptcha_enabled']); ?>> Abilita</label><br>
            Site Key <input type="text" name="prp_settings[recaptcha_site_key]" value="<?php echo esc_attr($s['recaptcha_site_key']); ?>"> — Secret Key <input type="text" name="prp_settings[recaptcha_secret_key]" value="<?php echo esc_attr($s['recaptcha_secret_key']); ?>">
          </td></tr>
          <tr><th>Limite per slot (opz.)</th><td>
            <label><input type="checkbox" name="prp_settings[slot_limit_enabled]" value="1" <?php checked(1,$s['slot_limit_enabled']); ?>> Abilita</label><br>
            Max prenotazioni/slot <input type="number" name="prp_settings[slot_limit_number]" value="<?php echo esc_attr($s['slot_limit_number']); ?>" min="0" step="1">
          </td></tr>
          <tr><th>Tema</th><td>
            Primario <input type="text" name="prp_settings[primary_color]" value="<?php echo esc_attr($s['primary_color']); ?>" size="8">
            Sfondo <input type="text" name="prp_settings[bg_color]" value="<?php echo esc_attr($s['bg_color']); ?>" size="8">
            Testo <input type="text" name="prp_settings[text_color]" value="<?php echo esc_attr($s['text_color']); ?>" size="8"><br>
            Raggio (px) <input type="number" name="prp_settings[radius]" value="<?php echo esc_attr($s['radius']); ?>" min="0" step="1">
            Font <input type="text" name="prp_settings[font_family]" value="<?php echo esc_attr($s['font_family']); ?>" class="regular-text">
            <label><input type="checkbox" name="prp_settings[dark_mode]" value="1" <?php checked(1,$s['dark_mode']); ?>> Tema scuro</label>
          </td></tr>
        <?php endif; ?>
      </table>
      <?php submit_button(); ?>
    </form>
  </div>
  <?php
}

function prp_admin_menu(){
  add_options_page('Prenotazione Ristorante PRO', 'Prenotazione Ristorante', 'manage_options', 'prp-settings', 'prp_settings_page');
  add_menu_page('Prenotazioni', 'Prenotazioni', 'manage_options', 'prp-requests', 'prp_render_requests_page', 'dashicons-clipboard', 26);
}
add_action('admin_menu','prp_admin_menu');

/* Link “Impostazioni” nella riga del plugin */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links){
  $url = admin_url('options-general.php?page=prp-settings');
  $links[] = '<a href="'.esc_url($url).'">'.esc_html__('Impostazioni','prp').'</a>';
  return $links;
});

/* =========================
 * Frontend assets & shortcode
 * ========================= */
function prp_enqueue(){
  $s = prp_get_settings();
  $css = ":root{--prp-primary:{$s['primary_color']};--prp-bg:{$s['bg_color']};--prp-text:{$s['text_color']};--prp-radius:{$s['radius']}px}
  .prp{font-family:{$s['font_family']};background:var(--prp-bg);color:var(--prp-text);padding:16px;border-radius:var(--prp-radius);max-width:680px;border:1px solid #e5e7eb}
  .prp input,.prp select,.prp textarea{width:100%;padding:10px;margin-top:6px;margin-bottom:14px;border:1px solid #d1d5db;border-radius:10px}
  .prp .btn{display:inline-flex;align-items:center;gap:8px;background:var(--prp-primary);color:#fff;padding:12px 16px;border-radius:10px;text-decoration:none;border:0;cursor:pointer}
  .prp-row{display:flex;gap:12px;flex-wrap:wrap}.prp-col{flex:1;min-width:220px}
  .prp-preview{background:#f7f7f7;border-radius:8px;padding:12px;font-size:14px;white-space:pre-wrap}
  .prp-inline{display:flex;align-items:center;gap:18px;margin:8px 0}.prp-inline label{display:flex;align-items:center;gap:6px;margin-right:10px}
  .prp-legal{font-size:13px;line-height:1.4;margin-top:6px}
  .prp-thanks{padding:16px;background:#ecfdf5;border:1px solid #10b981;border-radius:10px}
  .prp-badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:600;color:#fff}
  .prp-badge.nuova{background:#6b7280}
  .prp-badge.confermata{background:#10b981}
  .prp-badge.rinunciata{background:#ef4444}
  .prp-quick{display:none; padding:10px; border:1px solid #e5e7eb; border-radius:8px; background:#fff; margin-top:6px}
  ";
  if ($s['dark_mode']) $css .= ".prp{background:#111;color:#f2f2f2}.prp-preview{background:#222}.prp input,.prp select,.prp textarea{background:#000;color:#f2f2f2;border-color:#444}.prp-thanks{background:#064e3b;border-color:#065f46}.prp-quick{background:#0c0c0c;border-color:#333}";
  wp_register_style('prp-inline','');
  wp_enqueue_style('prp-inline');
  wp_add_inline_style('prp-inline',$css);

  if (!is_admin()){
    if (!empty($s['recaptcha_enabled']) && !empty($s['recaptcha_site_key'])){
      wp_enqueue_script('google-recaptcha', 'https://www.google.com/recaptcha/api.js?render='.esc_attr($s['recaptcha_site_key']), [], null, true);
    }
  }
}
add_action('wp_enqueue_scripts','prp_enqueue');

/* =========================
 * Shortcode (frontend form)
 * ========================= */
function prp_shortcode(){
  $s = prp_get_settings();
  $today = current_time('timestamp');
  $min = date('Y-m-d', $today + DAY_IN_SECONDS * intval($s['min_date_offset_days']));
  $max = date('Y-m-d', $today + DAY_IN_SECONDS * intval($s['max_date_offset_days']));
  $btn_text = $s['button_text'] ?: 'Invia richiesta';

  ob_start(); ?>
  <div class="prp" data-recaptcha="<?php echo esc_attr($s['recaptcha_site_key']); ?>">
    <h3><?php echo esc_html($s['title']); ?></h3>
    <div class="prp-row">
      <div class="prp-col"><label>Nome<input type="text" id="nome" required></label></div>
      <div class="prp-col"><label>Cognome<input type="text" id="cognome" required></label></div>
    </div>
    <div class="prp-row">
      <div class="prp-col"><label>Telefono<input type="tel" id="telefono" required></label></div>
      <div class="prp-col"><label>Email<input type="email" id="email" required></label></div>
    </div>
    <div class="prp-row">
      <div class="prp-col"><label>Data prenotazione<input type="date" id="data" min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr($max); ?>" required></label></div>
      <div class="prp-col">
        <label>Servizio</label>
        <div class="prp-inline">
          <label><input type="radio" name="servizio" value="pranzo" checked> Pranzo</label>
          <label><input type="radio" name="servizio" value="cena"> Cena</label>
        </div>
      </div>
    </div>
    <div class="prp-row">
      <div class="prp-col"><label>Fascia oraria<select id="fascia" required></select></label></div>
      <div class="prp-col"><label>Ospiti<select id="ospiti" required></select></label></div>
    </div>
    <?php if ($s['show_notes']) : ?>
      <label><?php echo esc_html($s['notes_label']); ?><textarea id="note" rows="3"></textarea></label>
    <?php endif; ?>
    <?php if ($s['show_allergies']) : ?>
      <label><?php echo esc_html($s['allergies_label']); ?><textarea id="allergie" rows="2"></textarea></label>
    <?php endif; ?>

    <div class="prp-legal">
      <label><input type="checkbox" id="privacy"> <span><?php echo wp_kses_post($s['privacy_text']); ?></span> <a href="<?php echo esc_url($s['privacy_url'] ?: '#'); ?>" target="_blank" rel="noopener">(Privacy Policy)</a></label><br>
      <label><input type="checkbox" id="marketing"> <span><?php echo wp_kses_post($s['marketing_text']); ?></span></label>
      <div style="display:flex;align-items:center;gap:8px;padding:10px;border:2px dashed var(--prp-primary);border-radius:10px;margin:10px 0;font-weight:600">
        <label style="margin:0"><input type="checkbox" id="confirm_all"> <strong>Conferma tutto</strong></label>
      </div>
    </div>

    <?php if ($s['show_preview']) : ?>
      <div class="prp-preview" id="preview"></div>
    <?php endif; ?>
    <button class="btn" id="send"><?php echo esc_html($btn_text); ?></button>
    <div id="thanks" class="prp-thanks" style="display:none"></div>
  </div>
  <script>
  (function(){
    const settings = {
      step: <?php echo intval($s['step_minutes']); ?>,
      lunch:{start:'<?php echo esc_js($s['lunch_start']); ?>', end:'<?php echo esc_js($s['lunch_end']); ?>'},
      dinner:{start:'<?php echo esc_js($s['dinner_start']); ?>', end:'<?php echo esc_js($s['dinner_end']); ?>'},
      guests:'<?php echo esc_js($s['guests']); ?>'.split(','),
      siteKey: '<?php echo esc_js($s['recaptcha_site_key']); ?>',
      captchaEnabled: <?php echo !empty($s['recaptcha_enabled']) ? 'true' : 'false'; ?>,
      thankyou: <?php echo wp_json_encode($s['thankyou_message']); ?>,
      redirectEnabled: <?php echo !empty($s['redirect_enabled']) ? 'true' : 'false'; ?>,
      redirectUrl: <?php echo wp_json_encode($s['redirect_url']); ?>,
      redirectDelay: <?php echo intval($s['redirect_delay']); ?>,
      disableWeekdays: '<?php echo esc_js($s['disable_weekdays']); ?>',
      closedDates: '<?php echo esc_js($s['closed_dates']); ?>'
    };
    function pad(n){ return (n<10?'0':'')+n; }
    function slots(start,end){
      const out=[]; let [h,m]=start.split(':').map(Number); const [eh,em]=end.split(':').map(Number);
      while(h<eh || (h===eh && m<=em)){ out.push(pad(h)+':'+pad(m)); m+=settings.step; if(m>=60){h++;m-=60;} }
      return out;
    }
    function updateSlots(){
      const servizio = document.querySelector('input[name=servizio]:checked').value;
      const sel = document.getElementById('fascia'); sel.innerHTML='';
      (servizio==='pranzo'?slots(settings.lunch.start,settings.lunch.end):slots(settings.dinner.start,settings.dinner.end)).forEach(s=>{
        const o=document.createElement('option'); o.value=s; o.textContent=s; sel.appendChild(o);
      });
    }
    function updateGuests(){
      const sel = document.getElementById('ospiti'); sel.innerHTML='';
      settings.guests.forEach(g=>{ const o=document.createElement('option'); o.value=g; o.textContent=g; sel.appendChild(o); });
    }
    function dateIT(v){ if(!v) return ''; const [y,m,d]=v.split('-'); return `${d}-${m}-${y}`; }
    function isDisabled(dateStr){
      const list = settings.disableWeekdays.split(',').map(s=>s.trim()).filter(Boolean).map(Number);
      if (!dateStr) return false;
      const d = new Date(dateStr+'T00:00:00');
      const dow = d.getDay(); // 0=Dom..6=Sab
      if (list.includes(dow)) return true;
      const closed = settings.closedDates.split(',').map(s=>s.trim()).filter(Boolean);
      return closed.includes(dateStr);
    }
    function collect(){
      const date_db = document.getElementById('data').value;
      return {
        nome:document.getElementById('nome').value.trim(),
        cognome:document.getElementById('cognome').value.trim(),
        telefono:document.getElementById('telefono').value.trim(),
        email:document.getElementById('email').value.trim(),
        data_db:date_db, data_it: dateIT(date_db),
        servizio:document.querySelector('input[name=servizio]:checked').value,
        fascia:document.getElementById('fascia').value,
        ospiti:document.getElementById('ospiti').value,
        note:(document.getElementById('note')||{value:''}).value.trim(),
        allergie:(document.getElementById('allergie')||{value:''}).value.trim(),
        privacy: document.getElementById('privacy').checked ? 1 : 0,
        marketing: document.getElementById('marketing').checked ? 1 : 0,
        token: ''
      };
    }
    function preview(){
      const p = document.getElementById('preview'); if(!p) return;
      const d = collect();
      const rows = [
        `Prenotazione per ${d.nome} ${d.cognome}`,
        `Data: ${d.data_it} • ${d.servizio.toUpperCase()} @ ${d.fascia}`,
        `Ospiti: ${d.ospiti}`
      ];
      if(d.note) rows.push('Note: '+d.note);
      if(d.allergie) rows.push('Allergie: '+d.allergie);
      p.textContent = rows.join('\\n');
    }
    function init(){
      updateSlots(); updateGuests(); preview();
      document.querySelectorAll('input[name=servizio]').forEach(r=>r.addEventListener('change', ()=>{updateSlots(); preview();}));
      ['nome','cognome','telefono','email','data','fascia','ospiti','note','allergie','privacy','marketing'].forEach(id=>{
        const el = document.getElementById(id); if(el) el.addEventListener('input', preview); if(el) el.addEventListener('change', preview);
      });
      document.getElementById('data').addEventListener('change', function(){
        if (isDisabled(this.value)){ alert('La data selezionata non è disponibile.'); this.value=''; }
      });
      const master = document.getElementById('confirm_all');
      if(master){ master.addEventListener('change', function(){ const p=document.getElementById('privacy'); const m=document.getElementById('marketing'); if(p) p.checked=this.checked; if(m) m.checked=this.checked; }); }
      document.getElementById('send').addEventListener('click', submitForm);
    }
    function submitForm(e){
      e.preventDefault();
      const d = collect();
      if(!d.data_db){ alert('Seleziona una data dal calendario'); return; }
      if(isDisabled(d.data_db)){ alert('La data selezionata è chiusa.'); return; }
      if(!d.privacy){ alert('Devi accettare la Privacy Policy per procedere.'); return; }
      const go = ()=>{
        const payload = new FormData();
        payload.append('action','prp_save');
        payload.append('payload', JSON.stringify(d));
        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>',{method:'POST', body: payload, credentials:'same-origin'})
          .then(res=>res.json()).then(json=>{
            if(!json || !json.success){ alert((json && json.data && json.data.msg) ? json.data.msg : 'Errore durante il salvataggio.'); return; }
            const wrap = document.querySelector('.prp'); const thanks = document.getElementById('thanks');
            thanks.innerHTML = settings.thankyou;
            thanks.style.display = 'block';
            wrap.querySelectorAll('input,select,textarea,button').forEach(el=>{ if(el.id!=='send') el.disabled = true; });
            if (settings.redirectEnabled && settings.redirectUrl){
              setTimeout(()=>{ window.location.href = settings.redirectUrl; }, Math.max(0, settings.redirectDelay)*1000);
            }
          })
          .catch(()=>alert('Errore durante il salvataggio.'));
      };
      if (settings.captchaEnabled && settings.siteKey && window.grecaptcha){
        grecaptcha.ready(function(){ grecaptcha.execute(settings.siteKey, {action:'prenotazione'}).then(function(token){ d.token = token; go(); }); });
      } else { go(); }
    }
    document.addEventListener('DOMContentLoaded', init);
  })();
  </script>
  <?php
  return ob_get_clean();
}
add_shortcode('prenotazione_ristorante_pro','prp_shortcode');

/* =========================
 * AJAX save + email iniziali
 * ========================= */
function prp_save(){
  if (!isset($_POST['payload'])) wp_send_json_error(['msg'=>'missing payload'], 400);
  $p = json_decode(stripslashes($_POST['payload']), true);
  if (!is_array($p)) wp_send_json_error(['msg'=>'bad payload'], 400);

  $nome = sanitize_text_field($p['nome'] ?? '');
  $cognome = sanitize_text_field($p['cognome'] ?? '');
  $telefono = sanitize_text_field($p['telefono'] ?? '');
  $email = sanitize_email($p['email'] ?? '');
  $data_db = sanitize_text_field($p['data_db'] ?? '');
  $servizio = ($p['servizio'] ?? '')==='cena' ? 'cena' : 'pranzo';
  $fascia = sanitize_text_field($p['fascia'] ?? '');
  $ospiti = sanitize_text_field($p['ospiti'] ?? '');
  $note = sanitize_textarea_field($p['note'] ?? '');
  $allergie = sanitize_textarea_field($p['allergie'] ?? '');
  $privacy_ok = !empty($p['privacy']) ? 1 : 0;
  $marketing_ok = !empty($p['marketing']) ? 1 : 0;
  $token = sanitize_text_field($p['token'] ?? '');
  $source = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '';

  if (!$data_db || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$data_db)){
    wp_send_json_error(['msg'=>'Data non valida'], 400);
  }
  if (!$privacy_ok){
    wp_send_json_error(['msg'=>'Devi accettare la Privacy Policy'], 400);
  }

  $s = prp_get_settings();

  // reCAPTCHA (se abilitato)
  if (!empty($s['recaptcha_enabled']) && !empty($s['recaptcha_secret_key'])){
    $resp = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
      'timeout' => 10,
      'body' => [
        'secret' => $s['recaptcha_secret_key'],
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
      ]
    ]);
    if (is_wp_error($resp)) wp_send_json_error(['msg'=>'Verifica reCAPTCHA non disponibile'], 400);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (empty($body['success']) || (isset($body['score']) && $body['score'] < 0.3)){
      wp_send_json_error(['msg'=>'Verifica anti-spam fallita'], 400);
    }
  }

  // Limite per slot (se abilitato)
  if (!empty($s['slot_limit_enabled']) && !empty($s['slot_limit_number'])){
    global $wpdb; $table = $wpdb->prefix.'prp_requests';
    $count = (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $table WHERE data_prenotazione=%s AND servizio=%s AND fascia=%s AND stato <> 'rinunciata'",
      $data_db, $servizio, $fascia
    ));
    if ($count >= intval($s['slot_limit_number'])){
      wp_send_json_error(['msg'=>'Lo slot selezionato è al completo. Scegli un altro orario.'], 400);
    }
  }

  global $wpdb; $table = $wpdb->prefix.'prp_requests';
  $wpdb->insert($table, [
    'nome'=>$nome, 'cognome'=>$cognome, 'telefono'=>$telefono, 'email'=>$email,
    'data_prenotazione'=>$data_db, 'servizio'=>$servizio, 'fascia'=>$fascia, 'ospiti'=>$ospiti,
    'note'=>$note, 'allergie'=>$allergie, 'privacy_ok'=>$privacy_ok, 'marketing_ok'=>$marketing_ok,
    'source_url'=>$source, 'stato'=>'nuova'
  ]);
  $row_id = $wpdb->insert_id;

  // Email admin (nuova) con pulsanti azione (2-step: anteprima → conferma) via token firmato stateless
  if (!empty($s['notify_admin_enabled'])){
    $to_admin = $s['notify_admin_email'] ?: get_option('admin_email');

    $row = [
      'id'=>$row_id,'nome'=>$nome,'cognome'=>$cognome,'telefono'=>$telefono,'email'=>$email,
      'data_prenotazione'=>$data_db,'servizio'=>$servizio,'fascia'=>$fascia,'ospiti'=>$ospiti,
      'note'=>$note,'allergie'=>$allergie,'source_url'=>$source
    ];

    $sub  = prp_replace_placeholders($s['email_admin_subject'], $row);
    $body = prp_replace_placeholders($s['email_admin_body'], $row);

    $confirm_url = add_query_arg(['action'=>'prp_public_status_preview','t'=> prp_build_signed_token($row_id,'conferma')], admin_url('admin-post.php'));
    $cancel_url  = add_query_arg(['action'=>'prp_public_status_preview','t'=> prp_build_signed_token($row_id,'rinuncia')], admin_url('admin-post.php'));

    $buttons = '
      <p style="margin:16px 0;">
        <a href="'.esc_url($confirm_url).'" style="background:#10b981;color:#fff;padding:10px 14px;border-radius:8px;text-decoration:none;display:inline-block;margin-right:8px;" rel="nofollow noopener">Conferma</a>
        <a href="'.esc_url($cancel_url).'"  style="background:#ef4444;color:#fff;padding:10px 14px;border-radius:8px;text-decoration:none;display:inline-block;" rel="nofollow noopener">Rifiuta</a>
      </p>
      <p style="color:#6b7280;font-size:12px;margin:8px 0 0;">
        Link validi per '.intval(prp_token_ttl()/HOUR_IN_SECONDS).' ore • ID #'.intval($row_id).'
      </p>
      <hr style="border:none;border-top:1px solid #e5e7eb;margin:16px 0;">
      <p style="font-size:12px; color:#6b7280; margin:0;">
        Se i pulsanti non si vedono, usa questi link:<br>
        Conferma: <a href="'.esc_url($confirm_url).'">'.esc_html($confirm_url).'</a><br>
        Rifiuta: <a href="'.esc_url($cancel_url).'">'.esc_html($cancel_url).'</a>
      </p>';

    $headers = ['Content-Type: text/html; charset=UTF-8'];
    wp_mail($to_admin, $sub, wpautop($body).$buttons, $headers);
  }

  // Email cliente (ricevuta)
  if ($email){
    $row = [
      'id'=>$row_id,'nome'=>$nome,'cognome'=>$cognome,'telefono'=>$telefono,'email'=>$email,
      'data_prenotazione'=>$data_db,'servizio'=>$servizio,'fascia'=>$fascia,'ospiti'=>$ospiti,
      'note'=>$note,'allergie'=>$allergie,'source_url'=>$source
    ];
    $sub = prp_replace_placeholders($s['email_customer_subject'], $row);
    $body = prp_replace_placeholders($s['email_customer_body'], $row);
    wp_mail($email, $sub, $body);
  }

  wp_send_json_success(['id'=>$row_id]);
}
add_action('wp_ajax_prp_save','prp_save');
add_action('wp_ajax_nopriv_prp_save','prp_save');

/* =========================
 * Utils
 * ========================= */
function prp_build_slots($start, $end, $step){
  $out = [];
  list($sh,$sm) = array_map('intval', explode(':',$start));
  list($eh,$em) = array_map('intval', explode(':',$end));
  $h=$sh; $m=$sm;
  while($h<$eh || ($h==$eh && $m<=$em)){
    $out[] = sprintf('%02d:%02d',$h,$m);
    $m += $step; if ($m>=60){ $h++; $m -= 60; }
  }
  return $out;
}

/* =========================
 * Token pubblici firmati (stateless, con anti-replay)
 * ========================= */
function prp_token_ttl(){ return apply_filters('prp_public_link_ttl_hours', 72) * HOUR_IN_SECONDS; }
function prp_b64url_encode($s){ return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function prp_b64url_decode($s){ return base64_decode(strtr($s, '-_', '+/')); }
function prp_build_signed_token($id, $action, $ts = null){
  $id = (int)$id; $action = sanitize_key($action); $ts = $ts ?: time();
  $data = $id.'|'.$action.'|'.$ts;
  $sig  = hash_hmac('sha256', $data, wp_salt('auth'));
  return prp_b64url_encode($data.'|'.$sig);
}
function prp_verify_signed_token($token, $expect_action = null){
  $raw = prp_b64url_decode((string)$token);
  if (!$raw || substr_count($raw, '|') < 3) return new WP_Error('bad', 'Token non valido');
  list($id, $action, $ts, $sig) = explode('|', $raw, 4);
  if ($expect_action && $action !== $expect_action) return new WP_Error('bad', 'Azione non valida');
  $calc = hash_hmac('sha256', $id.'|'.$action.'|'.$ts, wp_salt('auth'));
  if (!hash_equals($calc, $sig)) return new WP_Error('bad', 'Firma non valida');
  if (time() - (int)$ts > prp_token_ttl()) return new WP_Error('exp', 'Link scaduto');
  // Anti-replay
  $used_key = 'prp_used_'.md5($sig);
  if (get_transient($used_key)) return new WP_Error('used', 'Link già utilizzato');
  return ['id'=>(int)$id, 'action'=>$action, 'ts'=>(int)$ts, 'used_key'=>$used_key];
}

/* =========================
 * Public: anteprima e azione (2-step)
 * ========================= */
function prp_handle_public_status_preview(){
  nocache_headers(); header('X-Robots-Tag: noindex, nofollow', true);
  $t = isset($_GET['t']) ? sanitize_text_field($_GET['t']) : '';
  $parsed = prp_verify_signed_token($t);
  if (is_wp_error($parsed)) wp_die($parsed->get_error_message(), 400);

  $id = $parsed['id']; $do = $parsed['action'];
  global $wpdb; $table=$wpdb->prefix.'prp_requests';
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
  if (!$row) wp_die('Prenotazione non trovata.', 404);

  $verb  = ($do==='conferma') ? 'Conferma' : 'Rifiuta';
  $color = ($do==='conferma') ? '#10b981' : '#ef4444';
  $nonce = wp_create_nonce('prp_public_'.$t);

  $summary = sprintf(
    'Cliente: %s %s<br>Data: %s • %s @ %s<br>Ospiti: %s',
    esc_html($row['nome']), esc_html($row['cognome']),
    esc_html(prp_db_to_it($row['data_prenotazione'])),
    esc_html(strtoupper($row['servizio'])),
    esc_html($row['fascia']),
    esc_html($row['ospiti'])
  );

  $html = '
  <div style="font:16px/1.5 system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; padding:24px; max-width:760px;">
    <h2 style="margin:0 0 8px;">'.$verb.' prenotazione</h2>
    <p>'.$summary.'</p>
    <form method="post" action="'.esc_url(admin_url('admin-post.php')).'">
      <input type="hidden" name="action" value="prp_public_status_do">
      <input type="hidden" name="t" value="'.esc_attr($t).'">
      <input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'">
      <button type="submit" style="background:'.$color.'; color:#fff; border:0; border-radius:8px; padding:10px 14px; cursor:pointer">'.$verb.'</button>
      <a href="'.esc_url(site_url()).'" style="margin-left:10px;">Annulla</a>
    </form>
    <p style="color:#6b7280;font-size:12px;margin-top:12px">Il link scade entro '.intval(prp_token_ttl()/HOUR_IN_SECONDS).' ore. ID #'.intval($id).'</p>
  </div>';
  wp_die($html, 200);
}
add_action('admin_post_nopriv_prp_public_status_preview', 'prp_handle_public_status_preview');
add_action('admin_post_prp_public_status_preview',        'prp_handle_public_status_preview');

function prp_handle_public_status_do(){
  nocache_headers(); header('X-Robots-Tag: noindex, nofollow', true);
  if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_die('Metodo non consentito.', 405);

  $t = isset($_POST['t']) ? sanitize_text_field($_POST['t']) : '';
  if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'prp_public_'.$t)) wp_die('Nonce non valido.', 400);

  $parsed = prp_verify_signed_token($t);
  if (is_wp_error($parsed)) wp_die($parsed->get_error_message(), 400);

  // Segna usato (anti-replay)
  set_transient($parsed['used_key'], 1, prp_token_ttl());

  $id = $parsed['id']; $do = $parsed['action'];
  global $wpdb; $table=$wpdb->prefix.'prp_requests';
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
  if (!$row) wp_die('Prenotazione non trovata.', 404);

  $s = prp_get_settings();
  if ($do==='conferma'){
    $wpdb->update($table, ['stato'=>'confermata'], ['id'=>$id]);
    $row['stato'] = 'confermata';
    if (!empty($row['email'])){
      $sub = prp_replace_placeholders($s['email_status_confirm_subject'], $row);
      $body= prp_replace_placeholders($s['email_status_confirm_body'], $row);
      wp_mail($row['email'], $sub, $body);
    }
    $msg = 'Prenotazione confermata con successo.';
  } else {
    $wpdb->update($table, ['stato'=>'rinunciata'], ['id'=>$id]);
    $row['stato'] = 'rinunciata';
    if (!empty($row['email'])){
      $sub = prp_replace_placeholders($s['email_status_cancel_subject'], $row);
      $body= prp_replace_placeholders($s['email_status_cancel_body'], $row);
      wp_mail($row['email'], $sub, $body);
    }
    $msg = 'Prenotazione segnata come rifiutata.';
  }

  wp_die('<div style="font:16px/1.5 system-ui; padding:24px;"><h2 style="margin:0 0 12px;">'.$msg.'</h2><p>ID prenotazione: #'.intval($id).'</p><p><a href="'.esc_url(site_url()).'">Torna al sito</a></p></div>', 200);
}
add_action('admin_post_nopriv_prp_public_status_do', 'prp_handle_public_status_do');
add_action('admin_post_prp_public_status_do',        'prp_handle_public_status_do');

/* =========================
 * Admin — elenco + filtri + sort + bulk delete + CSV + status + quick-note
 * ========================= */
function prp_render_requests_page(){
  if (!current_user_can('manage_options')) return;
  global $wpdb; $table=$wpdb->prefix.'prp_requests';

  // Bulk delete (via POST con ids_json)
  if (!empty($_POST['prp_bulk_delete']) && isset($_POST['ids_json']) && check_admin_referer('prp_bulk_delete')){
    $ids = json_decode(stripslashes((string)$_POST['ids_json']), true);
    if (is_array($ids) && $ids){
      $ids = array_map('intval', $ids);
      $in = implode(',', array_fill(0,count($ids),'%d'));
      $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id IN ($in)", $ids));
      echo '<div class="updated notice"><p>Eliminate '.count($ids).' prenotazioni.</p></div>';
    }
  }

  // filtri
  $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
  $date_to   = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
  $servizio  = isset($_GET['servizio']) ? sanitize_key($_GET['servizio']) : '';
  $stato     = isset($_GET['stato']) ? sanitize_key($_GET['stato']) : '';
  $s         = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
  $orderby   = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'created_at';
  $order     = (isset($_GET['order']) && strtolower($_GET['order'])==='asc') ? 'ASC' : 'DESC';

  $allowed_orderby = ['created_at','data_prenotazione','nome','cognome','telefono','email','servizio','fascia','ospiti','stato'];
  if (!in_array($orderby, $allowed_orderby, true)) $orderby = 'created_at';

  $where = 'WHERE 1=1'; $params = [];
  $date_from_db = $date_from ? (preg_match('/^\d{2}-\d{2}-\d{4}$/',$date_from) ? prp_it_to_db($date_from) : $date_from) : '';
  $date_to_db   = $date_to   ? (preg_match('/^\d{2}-\d{2}-\d{4}$/',$date_to)   ? prp_it_to_db($date_to)   : $date_to)   : '';
  if ($date_from_db){ $where .= ' AND data_prenotazione >= %s'; $params[] = $date_from_db; }
  if ($date_to_db){   $where .= ' AND data_prenotazione <= %s'; $params[] = $date_to_db; }
  if ($servizio){  $where .= ' AND servizio = %s'; $params[] = $servizio; }
  if ($stato){     $where .= ' AND stato = %s'; $params[] = $stato; }
  if ($s){         $like = '%' . $wpdb->esc_like($s) . '%';
                   $where .= " AND (nome LIKE %s OR cognome LIKE %s OR telefono LIKE %s OR email LIKE %s)";
                   array_push($params,$like,$like,$like,$like);
  }

  // Export CSV
  if (isset($_GET['export']) && $_GET['export']==='csv'){
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table $where ORDER BY $orderby $order", $params), ARRAY_A);
    foreach($rows as &$r){
      $r['created_at'] = prp_dt_to_it($r['created_at']);
      $r['data_prenotazione'] = prp_db_to_it($r['data_prenotazione']);
    }
    unset($r);
    prp_export_csv($rows);
    exit;
  }

  // Query righe
  $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table $where ORDER BY $orderby $order", $params), ARRAY_A);

  $sconf = prp_get_settings();
  $lunch_slots = prp_build_slots($sconf['lunch_start'],$sconf['lunch_end'],intval($sconf['step_minutes']));
  $dinner_slots = prp_build_slots($sconf['dinner_start'],$sconf['dinner_end'],intval($sconf['step_minutes']));

  $extra = [
    'date_from'=>$date_from,'date_to'=>$date_to,'servizio'=>$servizio,'stato'=>$stato,'s'=>$s,
    'page'=>'prp-requests'
  ];
  $sort_link = function($label, $key) use($extra,$orderby,$order){
    $next = ($orderby===$key && $order==='ASC') ? 'DESC' : 'ASC';
    $url = add_query_arg(array_merge($extra, ['orderby'=>$key,'order'=>$next]));
    $arrow = ($orderby===$key) ? ($order==='ASC' ? '▲' : '▼') : '';
    return '<a href="'.esc_url($url).'">'.esc_html($label).' '.$arrow.'</a>';
  };

  $settings_url = admin_url('options-general.php?page=prp-settings');
  ?>
  <div class="wrap">
    <h1 style="display:flex;align-items:center;gap:10px">
      Prenotazioni
      <a class="button button-primary" href="<?php echo esc_url($settings_url); ?>">Impostazioni</a>
    </h1>

    <form method="get" style="margin:10px 0 10px;">
      <input type="hidden" name="page" value="prp-requests">
      <label>Dal <input type="text" name="date_from" value="<?php echo esc_attr($date_from); ?>" placeholder="gg-mm-aaaa" pattern="\d{2}-\d{2}-\d{4}"></label>
      <label>Al <input type="text" name="date_to" value="<?php echo esc_attr($date_to); ?>" placeholder="gg-mm-aaaa" pattern="\d{2}-\d{2}-\d{4}"></label>
      <label>Servizio
        <select name="servizio">
          <option value="">Tutti</option>
          <option value="pranzo" <?php selected('pranzo',$servizio); ?>>Pranzo</option>
          <option value="cena" <?php selected('cena',$servizio); ?>>Cena</option>
        </select>
      </label>
      <label>Stato
        <select name="stato">
          <option value="">Tutti</option>
          <option value="nuova" <?php selected('nuova',$stato); ?>>Nuova</option>
          <option value="confermata" <?php selected('confermata',$stato); ?>>Confermata</option>
          <option value="rinunciata" <?php selected('rinunciata',$stato); ?>>Rinunciata</option>
        </select>
      </label>
      <label>Cerca <input type="search" name="s" value="<?php echo esc_attr($s); ?>" placeholder="nome, cognome, telefono o email"></label>
      <button class="button">Filtra</button>
      <a class="button" href="<?php echo esc_url(add_query_arg(array_merge($extra,['export'=>'csv']))); ?>">Esporta CSV</a>
    </form>

    <!-- Form bulk delete separato; gli ID spuntati vengono raccolti via JS -->
    <form id="prp-bulk-form" method="post" style="margin:8px 0 12px;">
      <?php wp_nonce_field('prp_bulk_delete'); ?>
      <input type="hidden" name="ids_json" id="prp-ids-json" value="[]">
      <button class="button button-danger" name="prp_bulk_delete" value="1" onclick="return confirm('Eliminare le prenotazioni selezionate?');">Elimina selezionate</button>
    </form>

    <table class="widefat striped">
      <thead>
        <tr>
          <th style="width:28px"><input type="checkbox" onclick="document.querySelectorAll('.prp-chk').forEach(c=>c.checked=this.checked)"></th>
          <th><?php echo $sort_link('Arrivo','created_at'); ?></th>
          <th><?php echo $sort_link('Data prenotazione','data_prenotazione'); ?></th>
          <th><?php echo $sort_link('Nome','nome'); ?></th>
          <th><?php echo $sort_link('Cognome','cognome'); ?></th>
          <th><?php echo $sort_link('Telefono','telefono'); ?></th>
          <th><?php echo $sort_link('Email','email'); ?></th>
          <th><?php echo $sort_link('Servizio','servizio'); ?></th>
          <th><?php echo $sort_link('Fascia','fascia'); ?></th>
          <th><?php echo $sort_link('Ospiti','ospiti'); ?></th>
          <th>Note</th>
          <th>Allergie</th>
          <th><?php echo $sort_link('Stato prenotazione','stato'); ?></th>
          <th>Azioni</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="14">Nessuna prenotazione trovata.</td></tr>
        <?php else: foreach($rows as $r):
          $nonce = wp_create_nonce('prp_update_status_'.$r['id']);
          $badge_class = ($r['stato']==='confermata') ? 'confermata' : (($r['stato']==='rinunciata') ? 'rinunciata' : 'nuova');
          $row_id = (int)$r['id'];
        ?>
          <tr id="prp-row-<?php echo $row_id; ?>">
            <td><input type="checkbox" class="prp-chk" value="<?php echo $row_id; ?>"></td>
            <td><?php echo esc_html(prp_dt_to_it($r['created_at'])); ?></td>
            <td><?php echo esc_html(prp_db_to_it($r['data_prenotazione'])); ?></td>
            <td><?php echo esc_html($r['nome']); ?></td>
            <td><?php echo esc_html($r['cognome']); ?></td>
            <td><?php echo esc_html($r['telefono']); ?></td>
            <td><?php echo esc_html($r['email']); ?></td>
            <td><?php echo esc_html(strtoupper($r['servizio'])); ?></td>
            <td><?php echo esc_html($r['fascia']); ?></td>
            <td><?php echo esc_html($r['ospiti']); ?></td>
            <td><?php echo esc_html($r['note']); ?></td>
            <td><?php echo esc_html($r['allergie']); ?></td>
            <td><span class="prp-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($r['stato']); ?></span></td>
            <td style="min-width:300px">
              <!-- Form azioni PER-RIGA -->
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=prp_update_status')); ?>" style="display:flex; gap:6px; flex-wrap:wrap; align-items:center">
                <?php wp_nonce_field('prp_update_status_'.$row_id); ?>
                <input type="hidden" name="id" value="<?php echo $row_id; ?>">
                <select name="servizio" class="prp-servizio" data-row="<?php echo $row_id; ?>">
                  <option value="pranzo" <?php selected('pranzo',$r['servizio']); ?>>Pranzo</option>
                  <option value="cena" <?php selected('cena',$r['servizio']); ?>>Cena</option>
                </select>
                <select name="fascia" class="prp-fascia" id="prp-fascia-<?php echo $row_id; ?>">
                  <?php
                    $slots = ($r['servizio']==='cena') ? $dinner_slots : $lunch_slots;
                    foreach($slots as $slot){
                      printf('<option value="%s"%s>%s</option>', esc_attr($slot), selected($slot,$r['fascia'],false)?' selected':'', esc_html($slot));
                    }
                  ?>
                </select>
                <button class="button button-primary" name="do" value="conferma">Conferma</button>
                <button class="button" name="do" value="rinuncia" onclick="return confirm('Segnare come rinunciata?');">Rinuncia</button>
                <button class="button" type="button" data-toggle="#prp-quick-<?php echo $row_id; ?>">Note interne</button>
              </form>

              <!-- Quick-edit NOTE INTERNE (al click) -->
              <div class="prp-quick" id="prp-quick-<?php echo $row_id; ?>">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=prp_save_note')); ?>">
                  <?php wp_nonce_field('prp_save_note_'.$row_id); ?>
                  <input type="hidden" name="id" value="<?php echo $row_id; ?>">
                  <textarea name="note_interne" rows="3" style="width:100%"><?php echo esc_textarea($r['note_interne']); ?></textarea>
                  <div style="margin-top:6px; display:flex; gap:6px">
                    <button class="button button-small">Salva note</button>
                    <button class="button button-small" type="button" data-toggle="#prp-quick-<?php echo $row_id; ?>">Chiudi</button>
                  </div>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <script>
  (function(){
    // Bulk delete: prepara JSON IDs
    const bulkForm = document.getElementById('prp-bulk-form');
    if (bulkForm){
      bulkForm.addEventListener('submit', function(e){
        const ids = Array.from(document.querySelectorAll('.prp-chk:checked')).map(i=>parseInt(i.value,10)).filter(Boolean);
        if (!ids.length){ e.preventDefault(); alert('Seleziona almeno una prenotazione.'); return false; }
        document.getElementById('prp-ids-json').value = JSON.stringify(ids);
      });
    }
    // Toggle pannelli quick
    document.querySelectorAll('[data-toggle]').forEach(btn=>{
      btn.addEventListener('click', function(){
        const sel = this.getAttribute('data-toggle'); if (!sel) return;
        const box = document.querySelector(sel); if (!box) return;
        box.style.display = (box.style.display==='block') ? 'none' : 'block';
      });
    });
    // Cambio servizio => aggiorna slot nel SELECT della stessa riga
    const lunchSlots = <?php echo wp_json_encode($lunch_slots); ?>;
    const dinnerSlots = <?php echo wp_json_encode($dinner_slots); ?>;
    document.querySelectorAll('.prp-servizio').forEach(sel=>{
      sel.addEventListener('change', function(){
        const row = this.getAttribute('data-row');
        const fs = document.getElementById('prp-fascia-'+row);
        const slots = (this.value==='cena') ? dinnerSlots : lunchSlots;
        fs.innerHTML = '';
        slots.forEach(t=>{ const o=document.createElement('option'); o.value=t; o.textContent=t; fs.appendChild(o); });
      });
    });
  })();
  </script>
  <?php
}

/* =========================
 * Admin: salva note interne
 * ========================= */
add_action('admin_post_prp_save_note', function(){
  if (!current_user_can('manage_options')) wp_die('Unauthorized');
  $id = intval($_POST['id'] ?? 0);
  if (!$id) wp_die('Bad request');
  if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'],'prp_save_note_'.$id)) wp_die('Bad nonce');
  $note = isset($_POST['note_interne']) ? wp_kses_post($_POST['note_interne']) : '';
  global $wpdb; $table=$wpdb->prefix.'prp_requests';
  $wpdb->update($table, ['note_interne'=>$note], ['id'=>$id]);
  wp_redirect(admin_url('admin.php?page=prp-requests'));
  exit;
});

/* =========================
 * Status update (Conferma / Rinuncia) + email al cliente
 * ========================= */
add_action('admin_post_prp_update_status', function(){
  if (!current_user_can('manage_options')) wp_die('Unauthorized');

  $id = intval($_POST['id'] ?? 0);
  if (!$id) wp_die('Bad request');
  if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'],'prp_update_status_'.$id)) wp_die('Bad nonce');

  $do = sanitize_key($_POST['do'] ?? '');
  if (!in_array($do, ['conferma','rinuncia'], true)) wp_die('Azione non valida');

  global $wpdb; $table=$wpdb->prefix.'prp_requests';
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
  if (!$row) wp_die('Prenotazione non trovata');

  $s = prp_get_settings();

  if ($do==='conferma'){
    $servizio = ($_POST['servizio'] ?? $row['servizio'])==='cena' ? 'cena' : 'pranzo';
    $fascia   = sanitize_text_field($_POST['fascia'] ?? $row['fascia']);
    $wpdb->update($table, ['servizio'=>$servizio, 'fascia'=>$fascia, 'stato'=>'confermata'], ['id'=>$id]);
    $row['servizio'] = $servizio;
    $row['fascia']   = $fascia;
    $row['stato']    = 'confermata';

    if (!empty($row['email'])){
      $sub = prp_replace_placeholders($s['email_status_confirm_subject'], $row);
      $body= prp_replace_placeholders($s['email_status_confirm_body'], $row);
      wp_mail($row['email'], $sub, $body);
    }
  } else {
    $wpdb->update($table, ['stato'=>'rinunciata'], ['id'=>$id]);
    $row['stato'] = 'rinunciata';
    if (!empty($row['email'])){
      $sub = prp_replace_placeholders($s['email_status_cancel_subject'], $row);
      $body= prp_replace_placeholders($s['email_status_cancel_body'], $row);
      wp_mail($row['email'], $sub, $body);
    }
  }

  wp_redirect(admin_url('admin.php?page=prp-requests'));
  exit;
});

/* =========================
 * Export CSV
 * ========================= */
function prp_export_csv($rows){
  nocache_headers();
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="prenotazioni-ristorante.csv"');
  $out = fopen('php://output','w');
  if (!$rows) { fclose($out); return; }
  fputcsv($out, array_keys($rows[0]));
  foreach($rows as $r){ fputcsv($out, $r); }
  fclose($out);
  exit;
}
