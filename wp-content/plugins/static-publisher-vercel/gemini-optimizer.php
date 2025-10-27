<?php
/**
 * Gemini AI HTML Optimizer
 * Ottimizza HTML, CSS e JS usando Google Gemini AI
 */

if (!defined('ABSPATH')) exit;

class Gemini_HTML_Optimizer {
    
    private $api_key;
    private $cache_enabled;
    private $cache_ttl = DAY_IN_SECONDS;
    
    public function __construct($api_key) {
        $this->api_key = $api_key;
    }
    
    public function enable_cache($enabled = true) {
        $this->cache_enabled = $enabled;
    }
    
    /**
     * Ottimizza HTML usando Gemini AI
     */
    public function optimize_html($html, $page_type = 'generic') {
        $cache_key = $this->cache_enabled ? 'gemini_opt_' . md5($html . $page_type) : false;
        
        // Controlla cache
        if ($cache_key && ($cached = get_transient($cache_key))) {
            return $cached;
        }
        
        $prompt = $this->build_optimization_prompt($html, $page_type);
        $optimized_html = $this->call_gemini_api($prompt);
        
        // Validazione risultato
        if (!$this->validate_optimized_html($optimized_html, $html)) {
            throw new Exception('Gemini optimization failed validation');
        }
        
        // Salva in cache
        if ($cache_key) {
            set_transient($cache_key, $optimized_html, $this->cache_ttl);
        }
        
        return $optimized_html;
    }
    
    /**
     * Costruisce il prompt per l'ottimizzazione
     */
    private function build_optimization_prompt($html, $page_type) {
        $page_specific_instructions = $this->get_page_specific_instructions($page_type);
        
        return "Sei un esperto ottimizzatore HTML per siti statici. Ottimizza questo HTML seguendo STRITTAMENTE queste regole:

1. **CRITICAL RULES - NON MODIFICARE:**
   - Mantieni TUTTI i link, form action, e attributi data intatti
   - Non rimuovere classi CSS o ID
   - Mantieni tutti gli script di analytics (Google Analytics, Tag Manager)
   - Preserva tutti i meta tag e structured data

2. **OTTIMIZZAZIONI RICHIESTE:**
   - Minifica HTML rimuovendo spazi superflui, commenti non essenziali
   - Ottimizza ordine CSS: sposta CSS critico sopra la fold nell'<head>
   - Aggiungi loading=\"lazy\" a immagini sotto la fold
   - Ottimizza attributi alt per SEO
   - Rimuovi CSS e JS inutilizzati
   - Inline CSS critico (solo sopra la fold)
   - Defer non-critical JS

3. **ISTRUZIONI SPECIFICHE per {$page_type}:**
   {$page_specific_instructions}

4. **FORMATO OUTPUT:**
   - Restituisci SOLO l'HTML ottimizzato senza commenti aggiuntivi
   - Mantieni la struttura originale del documento
   - Assicurati che tutto sia funzionale

HTML DA OTTIMIZZARE:
{$html}";
    }
    
    /**
     * Istruzioni specifiche per tipo di pagina
     */
    private function get_page_specific_instructions($page_type) {
        $instructions = [
            'homepage' => 'Focus su LCP (Largest Contentful Paint): 
                          - Priorità assoluta a immagini hero/above-the-fold
                          - Preload web font critici
                          - Minimizza render-blocking resources',
            
            'product' => 'Focus su e-commerce performance:
                         - Ottimizza immagini prodotto con dimensioni appropriate
                         - Mantieni tutti i button Add to Cart funzionali
                         - Preserva prezzi e disponibilità',
            
            'article' => 'Focus su contenuti testuali:
                         - Ottimizza gerarchia heading (H1-H6)
                         - Mantieni readability del testo
                         - Ottimizza immagini articolo',
            
            'contact' => 'Focus su form performance:
                         - Mantieni tutti gli attributi form intatti
                         - Assicura che la validazione client-side funzioni
                         - Ottimizza recaptcha se presente',
            
            'generic' => 'Balance tra performance e funzionalità:
                         - Applica ottimizzazioni standard
                         - Mantieni tutte le funzionalità intatte'
        ];
        
        return $instructions[$page_type] ?? $instructions['generic'];
    }
    
    /**
     * Chiama l'API Gemini
     */
    private function call_gemini_api($prompt) {
        $url = "https://generativelanguage.googleapis.com/v1/models/gemini-pro:generateContent?key={$this->api_key}";
        
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
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            throw new Exception('Gemini API error: ' . $response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            throw new Exception('Gemini API error: ' . $body['error']['message']);
        }
        
        if (!isset($body['candidates'][0]['content']['parts'][0]['text'])) {
            throw new Exception('Invalid response from Gemini API');
        }
        
        return trim($body['candidates'][0]['content']['parts'][0]['text']);
    }
    
    /**
     * Valida l'HTML ottimizzato
     */
    private function validate_optimized_html($optimized, $original) {
        // Controlli base di validità
        if (empty($optimized) || strlen($optimized) < 100) {
            return false;
        }
        
        // Verifica che elementi critici siano presenti
        $critical_elements = ['</head>', '</body>', '<html'];
        foreach ($critical_elements as $element) {
            if (strpos($optimized, $element) === false) {
                return false;
            }
        }
        
        // Verifica che non sia stato rimosso troppo contenuto
        $original_size = strlen($original);
        $optimized_size = strlen($optimized);
        
        if ($optimized_size < ($original_size * 0.3)) { // Meno del 30% originale
            return false;
        }
        
        return true;
    }
    
    /**
     * Analizza le performance pre/post ottimizzazione
     */
    public function analyze_improvements($original_html, $optimized_html) {
        $metrics = [
            'original_size' => strlen($original_html),
            'optimized_size' => strlen($optimized_html),
            'reduction_percent' => 0,
            'estimated_lcp_improvement' => 0,
            'css_optimizations' => 0,
            'js_optimizations' => 0,
            'image_optimizations' => 0
        ];
        
        // Calcola riduzione percentuale
        $metrics['reduction_percent'] = round(
            (1 - $metrics['optimized_size'] / $metrics['original_size']) * 100, 
            1
        );
        
        // Stima miglioramento LCP basato su riduzione e ottimizzazioni
        $lcp_improvement = $metrics['reduction_percent'] * 0.3; // Base 30% del size reduction
        $lcp_improvement += $this->count_lazy_images($optimized_html) * 0.5;
        $lcp_improvement += $this->count_inline_css($optimized_html) * 0.8;
        
        $metrics['estimated_lcp_improvement'] = min(round($lcp_improvement), 40); // Max 40%
        
        return $metrics;
    }
    
    private function count_lazy_images($html) {
        preg_match_all('/loading=["\']lazy["\']/i', $html, $matches);
        return count($matches[0]);
    }
    
    private function count_inline_css($html) {
        preg_match_all('/<style[^>]*>.*?<\/style>/is', $html, $matches);
        return count($matches[0]);
    }
}