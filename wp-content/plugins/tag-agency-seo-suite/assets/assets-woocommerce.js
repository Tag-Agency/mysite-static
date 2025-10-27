jQuery(document).ready(function($) {
    // Genera meta per prodotti
    $('.tass-generate-product').on('click', function(e) {
        e.preventDefault();
        const product_id = $(this).data('product');
        const focus_kw = $('#_tass_focus_kw').val();
        
        if (!product_id) return;
        
        const $btn = $(this);
        startLoader($btn, 'Generazione...');
        
        $.ajax({
            url: TASS.ajax,
            method: 'POST',
            data: {
                action: 'tass_generate_meta_product',
                product_id: product_id,
                focus_kw: focus_kw,
                nonce: TASS.nonce
            },
            success: function(resp) {
                if (resp.success) {
                    $('#_tass_title').val(resp.data.title);
                    $('#_tass_description').val(resp.data.description);
                    $('#_tass_keywords').val(resp.data.keywords);
                    stopLoader($btn, 'Generato!');
                } else {
                    alert('Errore: ' + (resp.data.error || 'Generazione fallita'));
                    stopLoader($btn, 'Errore');
                }
            },
            error: function() {
                alert('Errore di connessione');
                stopLoader($btn, 'Errore');
            },
            complete: function() {
                setTimeout(() => {
                    $btn.removeClass('disabled').text('Genera Meta con AI');
                }, 2000);
            }
        });
    });
    
    // Controllo qualità prodotti - IMPLEMENTATO
    $('.tass-quality-product').on('click', function(e) {
        e.preventDefault();
        const product_id = $(this).data('product');
        
        if (!product_id) return;
        
        const $btn = $(this);
        startLoader($btn, 'Analisi prodotto...');
        
        $.ajax({
            url: TASS.ajax,
            method: 'POST',
            data: {
                action: 'tass_quality_check_product',
                product_id: product_id,
                nonce: TASS.nonce
            },
            success: function(resp) {
                if (resp.success) {
                    showProductQualityReport(resp.data);
                    stopLoader($btn, 'Analisi completata!');
                } else {
                    alert('Errore: ' + (resp.data.error || 'Analisi fallita'));
                    stopLoader($btn, 'Errore');
                }
            },
            error: function() {
                alert('Errore di connessione');
                stopLoader($btn, 'Errore');
            },
            complete: function() {
                setTimeout(() => {
                    $btn.removeClass('disabled').text('Controllo Qualità');
                }, 2000);
            }
        });
    });
    
    // Applica a Yoast per prodotti
    $('.tass-apply-yoast-product').on('click', function(e) {
        e.preventDefault();
        const product_id = $(this).data('product');
        const title = $('#_tass_title').val();
        const description = $('#_tass_description').val();
        const focus_kw = $('#_tass_focus_kw').val();
        
        if (!product_id) return;
        
        const $btn = $(this);
        startLoader($btn, 'Applicazione...');
        
        $.ajax({
            url: TASS.ajax,
            method: 'POST',
            data: {
                action: 'tass_apply_yoast',
                post_id: product_id,
                title: title,
                description: description,
                focus_kw: focus_kw,
                nonce: TASS.nonce
            },
            success: function(resp) {
                if (resp.success) {
                    $btn.text('Applicato!');
                    alert('Meta applicati a Yoast SEO per prodotto');
                } else {
                    alert('Errore: ' + (resp.data.error || 'Applicazione fallita'));
                    stopLoader($btn, 'Errore');
                }
            },
            error: function() {
                alert('Errore di connessione');
                stopLoader($btn, 'Errore');
            },
            complete: function() {
                setTimeout(() => {
                    $btn.removeClass('disabled').text('Applica a Yoast');
                }, 2000);
            }
        });
    });
    
    // Funzioni helper
    function startLoader(btn, text) {
        btn.addClass('disabled').data('original-text', btn.text()).text(text);
    }
    
    function stopLoader(btn, newText) {
        btn.removeClass('disabled').text(newText || btn.data('original-text'));
    }
    
    function showProductQualityReport(data) {
        const details = data.details;
        const checks = details.checks;
        
        let report = `🏪 ANALISI SEO PRODOTTO - PUNTEGGIO: ${data.score}/100\n\n`;
        
        report += `📊 METRICHE SEO:\n`;
        report += `● Titolo: ${details.title_length} caratteri ${checks.title_ok ? '✅' : '❌'}\n`;
        report += `● Descrizione: ${details.description_length} caratteri ${checks.description_ok ? '✅' : '❌'}\n`;
        report += `● Densità keyword: ${details.focus_density_pct}% ${checks.density_ok ? '✅' : '❌'}\n`;
        report += `● Leggibilità: ${details.flesch_estimate} ${checks.readability_ok ? '✅' : '❌'}\n\n`;
        
        report += `🛍️ DATI PRODOTTO:\n`;
        report += `● Prezzo: ${details.has_price ? '€' + details.price : '❌ Non impostato'}\n`;
        report += `● SKU: ${details.has_sku ? details.sku : '❌ Non impostato'}\n`;
        report += `● Stock: ${details.in_stock ? '✅ Disponibile' : '❌ Non disponibile'}\n`;
        report += `● Categorie: ${details.has_categories ? '✅ Impostate' : '❌ Non impostate'}\n`;
        report += `● Descrizione breve: ${details.has_short_description ? details.short_description_length + ' caratteri' : '❌ Non impostata'}\n\n`;
        
        report += `🖼️ IMMAGINI:\n`;
        report += `● Immagine principale: ${details.has_main_image ? '✅ Presente' : '❌ Mancante'}\n`;
        report += `● Galleria: ${details.has_gallery ? '✅ ' + details.gallery_count + ' immagini' : '❌ Nessuna immagine'}\n`;
        report += `● Testi ALT: ${details.images_with_alt}/${details.total_images} ${checks.images_alt_ok ? '✅' : '❌'}\n\n`;
        
        report += `🎯 PROMOZIONI:\n`;
        report += `● Sconto attivo: ${details.has_discount ? '✅ ' + details.discount_percentage + '%' : '❌ Nessuno sconto'}\n\n`;
        
        report += `📈 CONSIGLI:\n`;
        if (!checks.title_ok) report += `● Ottimizza il titolo SEO (30-65 caratteri)\n`;
        if (!checks.description_ok) report += `● Migliora la meta description (100-150 caratteri)\n`;
        if (!checks.has_short_description) report += `● Aggiungi una descrizione breve\n`;
        if (!checks.has_main_image) report += `● Inserisci un'immagine principale\n`;
        if (!checks.has_gallery) report += `● Aggiungi immagini alla galleria\n`;
        if (!checks.images_alt_ok) report += `● Completa i testi ALT delle immagini\n`;
        if (!checks.has_sku) report += `● Inserisci un SKU univoco\n`;
        if (!checks.in_stock) report += `● Controlla la disponibilità del prodotto\n`;
        if (!checks.has_discount) report += `● Considera di aggiungere una promozione\n`;
        
        // Creare un modal per visualizzare il report
        showModalReport('Analisi SEO Prodotto', report, data.score);
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
                    max-width: 600px;
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