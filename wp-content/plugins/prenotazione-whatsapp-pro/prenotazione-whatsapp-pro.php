function prp_render_requests_page(){
  if (!current_user_can('manage_options')) return;
  global $wpdb; 
  $table = $wpdb->prefix.'prp_requests';

  // ====== POST HANDLERS (stessa pagina) ======
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Tutte le azioni passano da qui con un unico nonce di pagina
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'prp_table_actions')) {
      echo '<div class="error notice"><p>Nonce non valido. Ricarica la pagina.</p></div>';
    } else {
      // 1) Eliminazione massiva
      if (!empty($_POST['bulk_delete']) && !empty($_POST['ids']) && is_array($_POST['ids'])) {
        $ids = array_map('intval', $_POST['ids']);
        if ($ids) {
          $in = implode(',', array_fill(0, count($ids), '%d'));
          $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id IN ($in)", $ids));
          echo '<div class="updated notice"><p>Eliminate '.count($ids).' prenotazioni.</p></div>';
        }
      }

      // 2) Salva note interne (click)
      if (!empty($_POST['save_note']) && is_array($_POST['save_note'])) {
        $id = (int) key($_POST['save_note']);           // ID riga dalla chiave del bottone
        $nonce_row = $_POST['nonce'][$id] ?? '';
        if ($id && wp_verify_nonce($nonce_row, 'prp_update_status_'.$id)) {
          $note = isset($_POST['note_interne'][$id]) ? wp_kses_post($_POST['note_interne'][$id]) : '';
          $wpdb->update($table, ['note_interne'=>$note], ['id'=>$id]);
          echo '<div class="updated notice"><p>Note interne aggiornate (#'.$id.').</p></div>';
        } else {
          echo '<div class="error notice"><p>Impossibile salvare le note (nonce riga non valido).</p></div>';
        }
      }

      // 3) Azioni per riga: Conferma / Rinuncia
      if (!empty($_POST['prp_action']) && is_array($_POST['prp_action'])) {
        $id = (int) key($_POST['prp_action']);          // ID riga dalla chiave del bottone
        $do = sanitize_key(current($_POST['prp_action'])); // 'conferma' o 'rinuncia'
        $nonce_row = $_POST['nonce'][$id] ?? '';

        if (!$id || !in_array($do, ['conferma','rinuncia'], true)) {
          echo '<div class="error notice"><p>Azione non valida.</p></div>';
        } elseif (!wp_verify_nonce($nonce_row, 'prp_update_status_'.$id)) {
          echo '<div class="error notice"><p>Nonce riga non valido.</p></div>';
        } else {
          // Carica riga
          $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
          if (!$row) {
            echo '<div class="error notice"><p>Prenotazione non trovata.</p></div>';
          } else {
            $s = prp_get_settings();

            if ($do === 'conferma') {
              // Preleva i valori selezionati SOLO per questa riga (array indicizzati)
              $servizio = (($_POST['servizio'][$id] ?? $row['servizio']) === 'cena') ? 'cena' : 'pranzo';
              $fascia   = sanitize_text_field($_POST['fascia'][$id] ?? $row['fascia']);

              $wpdb->update($table, ['servizio'=>$servizio, 'fascia'=>$fascia, 'stato'=>'confermata'], ['id'=>$id]);
              $row['servizio'] = $servizio;
              $row['fascia']   = $fascia;
              $row['stato']    = 'confermata';

              if (!empty($row['email'])) {
                $sub = prp_replace_placeholders($s['email_status_confirm_subject'], $row);
                $body= prp_replace_placeholders($s['email_status_confirm_body'], $row);
                wp_mail($row['email'], $sub, $body);
              }
              echo '<div class="updated notice"><p>Prenotazione #'.$id.' confermata.</p></div>';

            } else { // rinuncia
              $wpdb->update($table, ['stato'=>'rinunciata'], ['id'=>$id]);
              $row['stato'] = 'rinunciata';
              if (!empty($row['email'])) {
                $sub = prp_replace_placeholders($s['email_status_cancel_subject'], $row);
                $body= prp_replace_placeholders($s['email_status_cancel_body'], $row);
                wp_mail($row['email'], $sub, $body);
              }
              echo '<div class="updated notice"><p>Prenotazione #'.$id.' segnata come rinunciata.</p></div>';
            }
          }
        }
      }
    }
  }

  // ====== FILTRI ======
  $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
  $date_to   = isset($_GET['date_to'])   ? sanitize_text_field($_GET['date_to'])   : '';
  $servizio  = isset($_GET['servizio'])  ? sanitize_key($_GET['servizio'])  : '';
  $stato     = isset($_GET['stato'])     ? sanitize_key($_GET['stato'])     : '';
  $s         = isset($_GET['s'])         ? sanitize_text_field($_GET['s'])  : '';
  $orderby   = isset($_GET['orderby'])   ? sanitize_key($_GET['orderby'])   : 'created_at';
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

  $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table $where ORDER BY $orderby $order", $params), ARRAY_A);

  $sconf = prp_get_settings();
  $lunch_slots  = prp_build_slots($sconf['lunch_start'],$sconf['lunch_end'],intval($sconf['step_minutes']));
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

  // Stili minimi (niente hover: il box si apre su click)
  echo '<style>
    .prp-badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:600;color:#fff}
    .prp-badge.nuova{background:#6b7280}
    .prp-badge.confermata{background:#10b981}
    .prp-badge.rinunciata{background:#ef4444}
    .prp-qe{display:none;margin-top:6px;padding:8px;background:#f6f7f7;border:1px solid #e2e8f0;border-radius:6px}
    .prp-qe textarea{width:100%;height:60px}
    .prp-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
    .prp-note-toggle{margin-left:6px}
  </style>';

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

    <!-- UNICA FORM per: conferma/rinuncia, salva note, elimina selezionate -->
    <form method="post">
      <?php wp_nonce_field('prp_table_actions'); ?>

      <div style="margin:8px 0;">
        <button class="button button-danger" name="bulk_delete" value="1" onclick="return confirm('Eliminare le prenotazioni selezionate?');">Elimina selezionate</button>
      </div>

      <table class="widefat striped wp-list-table">
        <thead>
          <tr>
            <th style="width:28px"><input type="checkbox" onclick="document.querySelectorAll('.prp-chk').forEach(c=>c.checked=this.checked)"></th>
            <th><?php echo $sort_link('Arrivo','created_at'); ?></th>
            <th><?php echo $sort_link('Data prenotazione','data_prenotazione'); ?></th>
            <th><?php echo $sort_link('Cliente','nome'); ?></th>
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
          <?php else: 
            $sconf = prp_get_settings();
            $lunch_slots  = prp_build_slots($sconf['lunch_start'],$sconf['lunch_end'],intval($sconf['step_minutes']));
            $dinner_slots = prp_build_slots($sconf['dinner_start'],$sconf['dinner_end'],intval($sconf['step_minutes']));

            foreach($rows as $r):
              $id = (int)$r['id'];
              $nonce_row = wp_create_nonce('prp_update_status_'.$id);
              $badge_class = ($r['stato']==='confermata') ? 'confermata' : (($r['stato']==='rinunciata') ? 'rinunciata' : 'nuova');
          ?>
            <tr>
              <td><input type="checkbox" class="prp-chk" name="ids[]" value="<?php echo $id; ?>"></td>
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

              <td>
                <!-- campi indicizzati per riga -->
                <input type="hidden" name="nonce[<?php echo $id; ?>]" value="<?php echo esc_attr($nonce_row); ?>">

                <div class="prp-actions">
                  <select name="servizio[<?php echo $id; ?>]">
                    <option value="pranzo" <?php selected('pranzo',$r['servizio']); ?>>Pranzo</option>
                    <option value="cena"   <?php selected('cena',  $r['servizio']); ?>>Cena</option>
                  </select>
                  <select name="fascia[<?php echo $id; ?>]">
                    <?php
                      $slots = ($r['servizio']==='cena') ? $dinner_slots : $lunch_slots;
                      foreach($slots as $slot){
                        printf('<option value="%s"%s>%s</option>', esc_attr($slot), selected($slot,$r['fascia'],false)?' selected':'', esc_html($slot));
                      }
                    ?>
                  </select>

                  <button class="button button-primary" name="prp_action[<?php echo $id; ?>]" value="conferma">Conferma</button>
                  <button class="button" name="prp_action[<?php echo $id; ?>]" value="rinuncia" onclick="return confirm('Segnare come rinunciata?');">Rinuncia</button>

                  <a href="#" class="button button-secondary prp-note-toggle" data-id="<?php echo $id; ?>">Note interne</a>
                </div>

                <!-- Box note interne (visibile al click) -->
                <div class="prp-qe" id="qe-<?php echo $id; ?>">
                  <label>Note interne<br>
                    <textarea name="note_interne[<?php echo $id; ?>]"><?php echo esc_textarea($r['note_interne']); ?></textarea>
                  </label>
                  <p style="margin-top:6px">
                    <button class="button button-small" name="save_note[<?php echo $id; ?>]" value="1">Salva note</button>
                  </p>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>

      <div style="margin:8px 0;">
        <button class="button button-danger" name="bulk_delete" value="1" onclick="return confirm('Eliminare le prenotazioni selezionate?');">Elimina selezionate</button>
      </div>
    </form>
  </div>

  <script>
  // Toggle al click del box "Note interne"
  document.addEventListener('click', function(e){
    if (e.target && e.target.classList.contains('prp-note-toggle')) {
      e.preventDefault();
      const id = e.target.getAttribute('data-id');
      const box = document.getElementById('qe-'+id);
      if (box) { box.style.display = (box.style.display === 'block') ? 'none' : 'block'; }
    }
  });
  </script>
  <?php
}
