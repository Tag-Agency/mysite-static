jQuery(document).ready(function($){
    const $metaBox = $('#tass_post_box');
    if (!$metaBox.length) return;

    const $titleField = $('input[name="_tass_title"]');
    const $descField = $('textarea[name="_tass_description"]');
    const $focusField = $('input[name="_tass_focus_kw"]');
    const $btnGenerate = $('#tass-generate');
    const $btnQuality = $('#tass-quality');
    const $btnApplyYoast = $('#tass-apply-yoast');
    const $btnYoastDiag = $('#tass-yoast-diag');
    const $serpTitle = $('#tass-serp-title');
    const $serpDesc = $('#tass-serp-desc');
    const $titleMeter = $('#tass-title-meter');
    const $descMeter = $('#tass-desc-meter');

    function updateMeters() {
        const titleLen = $titleField.val().length;
        const descLen = $descField.val().length;
        $titleMeter.text(titleLen + ' ch. (consigliati 30-65)');
        $descMeter.text(descLen + ' ch. (consigliati 100-150)');
        $titleMeter.css('color', (titleLen >= TASS.titleMin && titleLen <= TASS.titleMax) ? 'green' : 'red');
        $descMeter.css('color', (descLen >= TASS.descMin && descLen <= TASS.descMax) ? 'green' : 'red');
        $serpTitle.text($titleField.val() || 'Anteprima titolo');
        $serpDesc.text($descField.val() || 'Anteprima descrizione');
    }
    updateMeters();
    $metaBox.on('keyup', '.tass-watch', updateMeters);

    function startLoader(btn, text) {
        btn.addClass('disabled').data('original-text', btn.text()).text(text || '... in corso');
    }
    
    function stopLoader(btn, newText) {
        btn.removeClass('disabled').text(newText || btn.data('original-text'));
    }

    // GENERA META
    $btnGenerate.on('click', function(e) {
        e.preventDefault();
        const post_id = $(this).data('post');
        const focus_kw = $focusField.val();
        if (!post_id) return;
        
        startLoader($(this), 'Generazione...');
        
        $.post(TASS.ajax, {
            action: 'tass_generate_meta',
            post_id: post_id,
            focus_kw: focus_kw,
            nonce: TASS.nonce
        })
        .done(function(resp) {
            if (resp.success) {
                $titleField.val(resp.data.title);
                $descField.val(resp.data.description);
                $focusField.val(focus_kw);
                updateMeters();
                stopLoader($btnGenerate, 'Generato!');
                setTimeout(() => stopLoader($btnGenerate), 2000);
            } else {
                alert('Errore: ' + (resp.data.error || 'Generazione fallita. Controlla la console o le impostazioni.'));
                stopLoader($btnGenerate, 'Errore');
            }
        })
        .fail(function() {
            alert('Errore di connessione o del server.');
            stopLoader($btnGenerate, 'Errore');
        });
    });

    // CONTROLLO QUALITÀ - VERSIONE MIGLIORATA
    $btnQuality.on('click', function(e) {
        e.preventDefault();
        const post_id = $(this).data('post');
        if (!post_id) return;
        
        startLoader($(this), 'Analisi contenuto...');
        
        $.ajax({
            url: TASS.ajax,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'tass_quality_check',
                post_id: post_id,
                nonce: TASS.nonce
            },
            success: function(resp) {
                if (resp.success) {
                    showContentQualityReport(resp.data);
                    stopLoader($btnQuality, 'Analisi completata!');
                } else {
                    alert('Errore: ' + (resp.data.error || 'Analisi fallita'));
                    stopLoader($btnQuality, 'Errore');
                }
            },
            error: function() {
                alert('Errore di connessione');
                stopLoader($btnQuality, 'Errore');
            },
            complete: function() {
                setTimeout(() => {
                    $btnQuality.removeClass('disabled').text('Controllo qualità');
                }, 2000);
            }
        });
    });

    // APPLICA A YOAST - VERSIONE MIGLIORATA
    $btnApplyYoast.on('click', function(e) {
        e.preventDefault();
        const post_id = $(this).data('post');
        const title = $titleField.val();
        const description = $descField.val();
        const focus_kw = $focusField.val();
        
        if (!post_id) {
            alert('Errore: ID post non valido');
            return;
        }

        // Validazione base
        if (!title && !description) {
            alert('Inserisci almeno un titolo o una descrizione da applicare a Yoast');
            return;
        }

        startLoader($(this), 'Applicazione...');

        // Usa $.ajax invece di $.post per migliore gestione errori
        $.ajax({
            url: TASS.ajax,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'tass_apply_yoast',
                post_id: post_id,
                title: title,
                description: description,
                focus_kw: focus_kw,
                nonce: TASS.nonce
            },
            success: function(resp) {
                if (resp.success) {
                    let message = 'Metadati applicati a Yoast SEO con successo!';
                    
                    // Mostra dettagli aggiuntivi se presenti
                    if (resp.data.message) {
                        message = resp.data.message;
                    }
                    if (resp.data.warnings) {
                        message += '\n\nNote: ' + resp.data.warnings;
                    }
                    
                    alert(message);
                    stopLoader($btnApplyYoast, '✓ Applicato');
                    
                    // Ricarica la pagina dopo 2 secondi per vedere i cambiamenti in Yoast
                    setTimeout(() => {
                        if (TASS.debug) {
                            window.location.reload();
                        }
                    }, 2000);
                    
                } else {
                    let errorMsg = 'Errore durante l\'applicazione a Yoast: ';
                    errorMsg += resp.data.error || 'Errore sconosciuto';
                    alert(errorMsg);
                    stopLoader($btnApplyYoast, '❌ Errore');
                }
            },
            error: function(xhr, status, error) {
                let errorMsg = 'Errore di connessione: ';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.error) {
                    errorMsg += xhr.responseJSON.data.error;
                } else {
                    errorMsg += error || 'Impossibile connettersi al server';
                }
                alert(errorMsg);
                stopLoader($btnApplyYoast, '❌ Errore');
            }
        });
    });

    // DIAGNOSTICA YOAST
    $btnYoastDiag.on('click', function(e) {
        e.preventDefault();
        const post_id = $(this).data('post');
        if (!post_id || !TASS.debug) return;
        
        startLoader($(this), 'Diagnostica...');
        
        $.post(TASS.ajax, {
            action: 'tass_yoast_diag',
            post_id: post_id,
            nonce: TASS.nonce
        })
        .done(function(resp) {
            if (resp.success) {
                const diag = resp.data;
                let msg = '=== Diagnostica Yoast ===\n\n';
                msg += 'TSK Meta:\n';
                msg += '  - Title: ' + (diag.tsk_meta.title || 'N/A') + '\n';
                msg += '  - Description: ' + (diag.tsk_meta.description || 'N/A') + '\n\n';
                msg += 'Yoast Meta (diretta):\n';
                msg += '  - Title: ' + (diag.yoast_meta_direct.title || 'N/A') + '\n';
                msg += '  - Description: ' + (diag.yoast_meta_direct.description || 'N/A') + '\n';
                msg += '  - Focus KW: ' + (diag.yoast_meta_direct.focuskw || 'N/A') + '\n\n';
                msg += 'Yoast Indexable:\n';
                msg += '  - Title: ' + (diag.yoast_indexable.title || 'N/A') + '\n';
                msg += '  - Description: ' + (diag.yoast_indexable.description || 'N/A') + '\n\n';
                msg += 'Versione Yoast: ' + diag.yoast_version + '\n';
                msg += 'Yoast Attivo: ' + (diag.yoast_active ? 'Sì' : 'No') + '\n';
                alert(msg);
                stopLoader($btnYoastDiag, 'Fatto!');
                setTimeout(() => stopLoader($btnYoastDiag), 2000);
            } else {
                alert('Errore diagnostica: ' + (resp.data.error || 'Sconosciuto'));
                stopLoader($btnYoastDiag, 'Errore');
            }
        })
        .fail(function() {
            alert('Errore di connessione o del server.');
            stopLoader($btnYoastDiag, 'Errore');
        });
    });

    // FUNZIONE PER REPORT QUALITÀ CONTENUTO
    function showContentQualityReport(data) {
        const details = data.details;
        const checks = details.checks;
        
        let report = `📊 ANALISI SEO CONTENUTO - PUNTEGGIO: ${data.score}/100\n\n`;
        
        report += `🎯 METRICHE SEO BASE:\n`;
        report += `● Titolo: ${details.title_length} caratteri ${checks.title_ok ? '✅' : '❌'}\n`;
        report += `● Descrizione: ${details.description_length} caratteri ${checks.description_ok ? '✅' : '❌'}\n`;
        report += `● Keyword focus: ${details.has_focus_kw ? '✅ ' + details.focus_primary : '❌ Non impostata'}\n`;
        report += `● Densità keyword: ${details.focus_density_pct}% ${checks.density_ok ? '✅' : '❌'}\n\n`;
        
        report += `📝 ANALISI CONTENUTO:\n`;
        report += `● Parole: ${details.word_count} ${checks.length_ok ? '✅' : '❌'}\n`;
        report += `● Paragrafi: ${details.paragraph_count} ${checks.paragraphs_ok ? '✅' : '❌'}\n`;
        report += `● Liste: ${details.list_count} ${checks.lists_ok ? '✅' : '❌'}\n`;
        report += `● Leggibilità: ${details.flesch_estimate} ${checks.readability_ok ? '✅' : '❌'}\n\n`;
        
        report += `🏗️ STRUTTURA HTML:\n`;
        report += `● H1 presente: ${checks.has_h1 ? '✅' : '❌'}\n`;
        report += `● H2 presenti: ${details.h2_count} ${checks.h2_ok ? '✅' : '❌'}\n`;
        report += `● H3 presenti: ${details.h3_count}\n\n`;
        
        report += `🖼️ IMMAGINI:\n`;
        report += `● Totale immagini: ${details.image_count} ${checks.images_ok ? '✅' : '❌'}\n`;
        report += `● ALT completi: ${details.images_with_alt}/${details.image_count} ${checks.images_alt_ok ? '✅' : '❌'}\n`;
        report += `● Dimensioni specificate: ${details.images_with_dimensions}/${details.image_count}\n\n`;
        
        report += `🔗 LINK:\n`;
        report += `● Link interni: ${details.internal_links} ${checks.internal_links_ok ? '✅' : '❌'}\n`;
        report += `● Link esterni: ${details.external_links} ${checks.external_links_ok ? '✅' : '❌'}\n`;
        report += `● Link totali: ${details.total_links} ${checks.total_links_ok ? '✅' : '❌'}\n\n`;
        
        report += `🎯 POSIZIONAMENTO KEYWORD:\n`;
        report += `● Nel titolo: ${checks.kw_in_title ? '✅' : '❌'}\n`;
        report += `● Nella descrizione: ${checks.kw_in_description ? '✅' : '❌'}\n`;
        report += `● Nell'H1: ${checks.kw_in_h1 ? '✅' : '❌'}\n`;
        report += `● Nel primo paragrafo: ${checks.kw_in_first_paragraph ? '✅' : '❌'}\n`;
        report += `● Negli H2: ${checks.kw_in_h2 ? '✅' : '❌'}\n\n`;
        
        report += `🌐 SOCIAL & TECHNICAL:\n`;
        report += `● Meta Social: ${checks.social_ok ? '✅' : '❌'}\n`;
        report += `● Schema Markup: ${checks.schema_ok ? '✅' : '❌'}\n\n`;
        
        report += `📈 CONSIGLI MIGLIORAMENTO:\n`;
        if (!checks.title_ok) report += `● Ottimizza il titolo SEO (30-65 caratteri)\n`;
        if (!checks.description_ok) report += `● Migliora la meta description (100-150 caratteri)\n`;
        if (!checks.has_focus_kw) report += `● Imposta una keyword focus principale\n`;
        if (!checks.length_ok) report += `● Aumenta il contenuto (minimo 300 parole)\n`;
        if (!checks.has_h1) report += `● Aggiungi un tag H1 al contenuto\n`;
        if (!checks.h2_ok) report += `● Aggiungi almeno 2 tag H2 per strutturare il contenuto\n`;
        if (!checks.paragraphs_ok) report += `● Suddividi il contenuto in più paragrafi\n`;
        if (!checks.images_ok) report += `● Aggiungi immagini rilevanti\n`;
        if (!checks.images_alt_ok) report += `● Completa i testi ALT di tutte le immagini\n`;
        if (!checks.internal_links_ok) report += `● Aggiungi link interni verso altri contenuti\n`;
        if (!checks.external_links_ok) report += `● Inserisci link esterni a fonti autorevoli\n`;
        if (!checks.kw_in_title) report += `● Inserisci la keyword focus nel titolo\n`;
        if (!checks.kw_in_h1) report += `● Includi la keyword focus nell'H1\n`;
        if (!checks.social_ok) report += `● Configura i meta tag per i social media\n`;
        
        // Creare un modal per visualizzare il report
        showModalReport('Analisi SEO Contenuto', report, data.score);
    }
    
    function showModalReport(title, content, score) {
        // Rimuovi modal esistenti
        $('.tass-quality-modal').remove();
        
        const scoreColor = score >= 80 ? '#4CAF50' : score >= 60 ? '#FF9800' : '#F44336';
        const scoreEmoji = score >= 80 ? '🎉' : score >= 60 ? '⚠️' : '❌';
        
        const modalHTML = `
            <div class="tass-quality-modal" style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 99999;
            ">
                <div style="
                    background: white;
                    padding: 25px;
                    border-radius: 10px;
                    max-width: 700px;
                    max-height: 80vh;
                    overflow-y: auto;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
                    position: relative;
                ">
                    <button class="close-modal" style="
                        position: absolute;
                        top: 15px;
                        right: 15px;
                        background: none;
                        border: none;
                        font-size: 20px;
                        cursor: pointer;
                        color: #666;
                    ">×</button>
                    
                    <h2 style="margin-top: 0; color: #333; border-bottom: 2px solid ${scoreColor}; padding-bottom: 10px;">
                        ${title} 
                        <span style="font-size: 24px; margin-left: 10px;">${scoreEmoji}</span>
                    </h2>
                    
                    <div style="
                        background: ${scoreColor};
                        color: white;
                        padding: 15px;
                        border-radius: 8px;
                        text-align: center;
                        margin: 15px 0;
                        font-size: 18px;
                        font-weight: bold;
                    ">
                        PUNTEGGIO SEO: ${score}/100
                    </div>
                    
                    <pre style="
                        background: #f8f9fa;
                        padding: 20px;
                        border-radius: 8px;
                        border-left: 4px solid ${scoreColor};
                        white-space: pre-wrap;
                        font-family: 'Courier New', monospace;
                        font-size: 13px;
                        line-height: 1.4;
                        margin: 0;
                    ">${content}</pre>
                    
                    <div style="margin-top: 20px; text-align: center;">
                        <button class="button button-primary close-modal">Chiudi Report</button>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(modalHTML);
        
        // Chiudi modal
        $('.tass-quality-modal .close-modal').on('click', function() {
            $('.tass-quality-modal').remove();
        });
        
        // Chiudi cliccando fuori
        $('.tass-quality-modal').on('click', function(e) {
            if (e.target === this) {
                $(this).remove();
            }
        });
    }
});