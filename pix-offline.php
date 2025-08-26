<?php
/**
 * Plugin Name: PIX Offline
 * Description: Plugin para gerar botão PIX e processar pagamentos offline no WooCommerce
 * Version: 001
 * Author: TMS
 * Text Domain: pix-offline
 * 
 * === NOTAS DA VERSÃO DESTE ARQUIVO 001 ===
 * - Melhorado endpoint de webhook OpenPix com segurança aprimorada
 * - Removido código de webhook duplicado (delegado para PixOfflineTransactions)
 * - Adicionados logs detalhados para debug de webhook
 * - Compatibilidade total com sistema dual de status
 * - Manutenção de todas funcionalidades existentes do PIX dinâmico e estático
 * - Preparação para futuras integrações webhook avançadas
 */

// Evita acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Incluir arquivos
require_once plugin_dir_path(__FILE__) . 'pix-offline-admin.php';
require_once plugin_dir_path(__FILE__) . 'pix-offline-transactions.php';

class PixOfflinePlugin {
    
    private $transactions;
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_process_pix_payment', array($this, 'process_pix_payment'));
        add_action('wp_ajax_nopriv_process_pix_payment', array($this, 'process_pix_payment'));
        add_action('wp_ajax_pix_recalculate_transaction', array($this, 'pix_recalculate_transaction'));
        add_action('wp_ajax_nopriv_pix_recalculate_transaction', array($this, 'pix_recalculate_transaction'));
        add_action('wp_ajax_pix_track_checkout_initiated', array($this, 'ajax_track_checkout_initiated'));
        add_action('wp_ajax_nopriv_pix_track_checkout_initiated', array($this, 'ajax_track_checkout_initiated'));
        
        // PIX dinâmico - OpenPix API
        add_action('wp_ajax_pix_generate_dynamic', array($this, 'ajax_generate_dynamic_pix'));
        add_action('wp_ajax_nopriv_pix_generate_dynamic', array($this, 'ajax_generate_dynamic_pix'));
        
        // Rastrear cópia do PIX
        add_action('wp_ajax_pix_copy_pix_code', array($this, 'ajax_pix_copy_code'));
        add_action('wp_ajax_nopriv_pix_copy_pix_code', array($this, 'ajax_pix_copy_code'));
        
        // Inicializar sistema de transações (sempre)
        $this->transactions = new PixOfflineTransactions();
    }
    
    public function init() {
        // Registrar o shortcode
        add_shortcode('pix', array($this, 'pix_shortcode'));
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script('jquery');
        wp_enqueue_script('pix-offline-js', plugin_dir_url(__FILE__) . 'pix-offline.js', array('jquery'), '1.0.4', true);
        wp_enqueue_style('pix-offline-css', plugin_dir_url(__FILE__) . 'pix-offline.css', array(), '1.0.1');
        
        // Localizar script para AJAX
        wp_localize_script('pix-offline-js', 'pix_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pix_payment_nonce'),
            'checkout_nonce' => wp_create_nonce('pix_checkout_nonce'),
            'dynamic_nonce' => wp_create_nonce('pix_dynamic_nonce'),
            'copy_nonce' => wp_create_nonce('pix_copy_nonce')
        ));
        
        // Adicionar CSS personalizado
        $this->add_custom_styles();
    }
    
    private function add_custom_styles() {
        $options = get_option('pix_offline_options', array());
        $button_color = isset($options['button_color']) ? $options['button_color'] : '#32BCAD';
        $button_hover_color = isset($options['button_hover_color']) ? $options['button_hover_color'] : '#28a99a';
        
        $custom_css = "
        <style>
        .pix-btn, .pix-confirm-btn {
            background-color: {$button_color} !important;
        }
        .pix-btn:hover, .pix-confirm-btn:hover {
            background-color: {$button_hover_color} !important;
        }
        .pix-error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
            margin: 10px 0;
        }
        .pix-loading {
            text-align: center;
            padding: 20px;
        }
        .pix-identifier {
            background: #e7f3ff;
            border: 1px solid #b3d7ff;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
            text-align: center;
            font-size: 13px;
        }
        .pix-identifier strong {
            display: block;
            margin-bottom: 5px;
            color: #0073aa;
        }
        .pix-identifier code {
            font-family: monospace;
            background: #fff;
            padding: 5px;
            border-radius: 3px;
            word-break: break-all;
        }
        </style>";
        
        echo $custom_css;
    }
    
    public function ajax_track_checkout_initiated() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'pix_checkout_nonce')) {
            wp_die('Erro de segurança');
        }
        
        // Por enquanto só confirma recebimento
        // A transação será criada quando o pedido for processado
        wp_send_json_success(array('message' => 'Checkout iniciado registrado'));
    }
    
    // Rastrear cópia do código PIX
    public function ajax_pix_copy_code() {
        // Verificar nonce (aceita tanto copy quanto padrão)
        $nonce_valid = false;
        if (isset($_POST['nonce'])) {
            if (wp_verify_nonce($_POST['nonce'], 'pix_copy_nonce')) {
                $nonce_valid = true;
            } elseif (wp_verify_nonce($_POST['nonce'], 'pix_payment_nonce')) {
                $nonce_valid = true;
            }
        }
        
        if (!$nonce_valid) {
            wp_die('Erro de segurança');
        }
        
        $order_id = intval($_POST['order_id']);
        
        // Verificar se a ordem existe
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Ordem não encontrada'));
        }
        
        // Atualizar status da transação para "pix_copiado"
        $this->transactions->update_transaction_status($order_id, 'pix_copiado');
        
        // Adicionar nota à ordem
        $order->add_order_note('Cliente copiou o código PIX.');
        
        wp_send_json_success(array(
            'message' => 'PIX copiado registrado com sucesso!'
        ));
    }
    
    // Gerar PIX dinâmico com cache
    public function ajax_generate_dynamic_pix() {
        // Verificar nonce (aceita tanto dynamic quanto padrão)
        $nonce_valid = false;
        if (isset($_POST['nonce'])) {
            // Tentar primeiro o nonce dinâmico, depois o padrão
            if (wp_verify_nonce($_POST['nonce'], 'pix_dynamic_nonce')) {
                $nonce_valid = true;
            } elseif (wp_verify_nonce($_POST['nonce'], 'pix_payment_nonce')) {
                $nonce_valid = true;
            }
        }
        
        if (!$nonce_valid) {
            wp_die('Erro de segurança');
        }
        
        $order_id = intval($_POST['order_id']);
        $total_amount = floatval($_POST['total_amount']);
        
        // Verificar se a ordem existe
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Ordem não encontrada'));
        }
        
        // Verificar cache válido antes de fazer nova requisição
        $cached_pix = $this->get_cached_pix_data($order_id);
        if ($cached_pix && $this->is_pix_cache_valid($cached_pix)) {
            // Cache válido - usar dados salvos
            wp_send_json_success(array(
                'brCode' => $cached_pix['brCode'],
                'qrCodeImage' => $cached_pix['qrCodeImage'],
                'identifier' => $cached_pix['identifier'],
                'cached' => true,
                'message' => 'PIX dinâmico obtido do cache'
            ));
            return;
        }
        
        // Pegar configurações do PIX dinâmico
        $options = get_option('pix_offline_options', array());
        $dynamic_enabled = isset($options['enable_pix_dynamic']) ? $options['enable_pix_dynamic'] : '0';
        $api_url = isset($options['openpix_api_url']) ? $options['openpix_api_url'] : '';
        $app_id = isset($options['openpix_app_id']) ? $options['openpix_app_id'] : '';
        $error_message = isset($options['openpix_error_message']) ? $options['openpix_error_message'] : 'Erro ao gerar PIX. Tente novamente.';
        
        if ($dynamic_enabled !== '1' || empty($api_url) || empty($app_id)) {
            wp_send_json_error(array('message' => 'PIX dinâmico não configurado corretamente'));
        }
        
        // Preparar payload para OpenPix API
        $payload = array(
            'correlationID' => strval($order_id),
            'value' => intval($total_amount * 100) // Converter para centavos
        );
        
        // Logs de debug detalhados
        error_log("PIX Debug: Iniciando chamada OpenPix API");
        error_log("PIX Debug: URL = " . $api_url);
        error_log("PIX Debug: AppID = " . substr($app_id, 0, 6) . '***' . substr($app_id, -3));
        error_log("PIX Debug: Payload = " . json_encode($payload));
        
        // Fazer requisição para OpenPix API
        $response = wp_remote_post($api_url, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => $app_id,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($payload)
        ));
        
        // Verificar se houve erro na requisição
        if (is_wp_error($response)) {
            $error_details = $response->get_error_message();
            error_log("PIX Debug: wp_remote_post ERROR = " . $error_details);
            wp_send_json_error(array('message' => $error_message . ' (Erro de conexão)'));
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response);
        
        error_log("PIX Debug: HTTP Status = " . $status_code);
        error_log("PIX Debug: Response Headers = " . json_encode($response_headers));
        error_log("PIX Debug: Response Body = " . $body);
        
        // Verificar status HTTP
        if ($status_code !== 200 && $status_code !== 201) {
            error_log("PIX Debug: Status HTTP inválido = " . $status_code);
            wp_send_json_error(array('message' => $error_message . " (HTTP {$status_code})"));
        }
        
        // Decodificar resposta JSON
        $data = json_decode($body, true);
        $json_error = json_last_error();
        
        error_log("PIX Debug: JSON decode error = " . $json_error);
        error_log("PIX Debug: Decoded data = " . print_r($data, true));
        
        if ($json_error !== JSON_ERROR_NONE || !isset($data['charge'])) {
            error_log("PIX Debug: JSON inválido ou charge missing");
            wp_send_json_error(array('message' => $error_message . ' (Resposta inválida)'));
        }
        
        $charge = $data['charge'];
        error_log("PIX Debug: Charge data = " . print_r($charge, true));
        
        // Verificar se temos os campos necessários
        if (!isset($charge['brCode']) || !isset($charge['qrCodeImage'])) {
            error_log("PIX Debug: Campos obrigatórios missing - brCode=" . (isset($charge['brCode']) ? 'YES' : 'NO') . ", qrCodeImage=" . (isset($charge['qrCodeImage']) ? 'YES' : 'NO'));
            wp_send_json_error(array('message' => $error_message . ' (Dados incompletos)'));
        }
        
        error_log("PIX Debug: Sucesso! brCode e qrCodeImage presentes");
        
        // Salvar dados no cache
        $cache_data = array(
            'brCode' => $charge['brCode'],
            'qrCodeImage' => $charge['qrCodeImage'],
            'identifier' => isset($charge['identifier']) ? $charge['identifier'] : $charge['correlationID'],
            'expiresIn' => isset($charge['expiresIn']) ? $charge['expiresIn'] : 3600, // Default 1 hora
            'created_at' => current_time('timestamp')
        );
        
        $this->save_pix_cache_data($order_id, $cache_data);
        
        // Atualizar status da transação para "pix_gerado" e salvar identifier
        $existing_transaction = $this->get_transaction_status($order_id);
        if ($existing_transaction) {
            $this->transactions->update_transaction_status($order_id, 'pix_gerado');
        } else {
            $this->transactions->create_transaction($order_id, 'pix_gerado');
        }
        
        // Salvar identifier na transação
        $this->save_transaction_identifier($order_id, $cache_data['identifier']);
        
        // Retornar dados do PIX dinâmico
        wp_send_json_success(array(
            'brCode' => $charge['brCode'],
            'qrCodeImage' => $charge['qrCodeImage'],
            'identifier' => $cache_data['identifier'],
            'cached' => false,
            'message' => 'PIX dinâmico gerado com sucesso'
        ));
    }
    
    // Obter dados do PIX em cache
    private function get_cached_pix_data($order_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_orders_meta';
        
        $cache_data = $wpdb->get_var($wpdb->prepare("
            SELECT meta_value 
            FROM {$table_name} 
            WHERE order_id = %s 
            AND meta_key = '_pix_cache_data'
        ", $order_id));
        
        return $cache_data ? maybe_unserialize($cache_data) : null;
    }
    
    // Verificar se cache PIX é válido
    private function is_pix_cache_valid($cache_data) {
        if (!isset($cache_data['created_at']) || !isset($cache_data['expiresIn'])) {
            return false;
        }
        
        $created_at = $cache_data['created_at'];
        $expires_in = $cache_data['expiresIn'];
        $current_time = current_time('timestamp');
        
        return ($current_time - $created_at) < $expires_in;
    }
    
    // Salvar dados PIX no cache
    private function save_pix_cache_data($order_id, $cache_data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_orders_meta';
        
        // Verificar se já existe
        $existing = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$table_name} 
            WHERE order_id = %d AND meta_key = '_pix_cache_data'
        ", $order_id));
        
        if ($existing > 0) {
            // Atualizar
            $wpdb->update(
                $table_name,
                array('meta_value' => serialize($cache_data)),
                array('order_id' => $order_id, 'meta_key' => '_pix_cache_data'),
                array('%s'),
                array('%d', '%s')
            );
        } else {
            // Inserir
            $wpdb->insert(
                $table_name,
                array(
                    'order_id' => $order_id,
                    'meta_key' => '_pix_cache_data',
                    'meta_value' => serialize($cache_data)
                ),
                array('%d', '%s', '%s')
            );
        }
    }
    
    // Salvar identifier da transação
    private function save_transaction_identifier($order_id, $identifier) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_orders_meta';
        
        // Verificar se já existe
        $existing = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$table_name} 
            WHERE order_id = %d AND meta_key = '_pix_identifier'
        ", $order_id));
        
        if ($existing > 0) {
            // Atualizar
            $wpdb->update(
                $table_name,
                array('meta_value' => $identifier),
                array('order_id' => $order_id, 'meta_key' => '_pix_identifier'),
                array('%s'),
                array('%d', '%s')
            );
        } else {
            // Inserir
            $wpdb->insert(
                $table_name,
                array(
                    'order_id' => $order_id,
                    'meta_key' => '_pix_identifier',
                    'meta_value' => $identifier
                ),
                array('%d', '%s', '%s')
            );
        }
    }
    
    public function pix_recalculate_transaction() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'pix_payment_nonce')) {
            wp_die('Erro de segurança');
        }
        
        $order_id = intval($_POST['order_id']);
        
        // Verificar se a ordem existe
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Ordem não encontrada'));
        }
        
        // Buscar ordens filhas usando query SQL reversa
        $child_orders = $this->get_child_orders_reverse($order_id);
        
        // Calcular total atualizado (pai + filhos)
        $total_amount = $order->get_total();
        foreach ($child_orders as $child_id) {
            $child_order = wc_get_order($child_id);
            if ($child_order) {
                $total_amount += $child_order->get_total();
            }
        }
        
        // Criar/atualizar transação com status "pix_gerado"
        $existing_transaction = $this->get_transaction_status($order_id);
        if ($existing_transaction) {
            // Atualizar transação existente
            $this->transactions->update_transaction_status($order_id, 'pix_gerado');
            $this->update_transaction_totals($order_id, $child_orders, $total_amount);
        } else {
            // Criar nova transação
            $this->transactions->create_transaction($order_id, 'pix_gerado');
        }
        
        wp_send_json_success(array(
            'total_amount' => $total_amount,
            'total_amount_formatted' => number_format($total_amount, 2, ',', '.'),
            'child_orders' => $child_orders,
            'message' => 'Transação recalculada com sucesso'
        ));
    }
    
    private function get_child_orders_reverse($parent_order_id) {
        global $wpdb;
        
        // Busca reversa: encontrar todas as ordens que têm este ID como pai
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT order_id 
            FROM {$wpdb->prefix}wc_orders_meta 
            WHERE meta_key = '_cartflows_offer_parent_id' 
            AND meta_value = %s
        ", $parent_order_id));
        
        $child_ids = array();
        foreach ($results as $result) {
            $child_ids[] = $result->order_id;
        }
        
        return array_unique($child_ids);
    }
    
    private function update_transaction_totals($order_id, $child_orders, $total_amount) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_orders_meta';
        $current_time = current_time('mysql');
        
        // Atualizar child orders
        $wpdb->update(
            $table_name,
            array('meta_value' => serialize($child_orders)),
            array('order_id' => $order_id, 'meta_key' => '_pix_child_orders'),
            array('%s'),
            array('%d', '%s')
        );
        
        // Atualizar total amount
        $wpdb->update(
            $table_name,
            array('meta_value' => $total_amount),
            array('order_id' => $order_id, 'meta_key' => '_pix_total_amount'),
            array('%s'),
            array('%d', '%s')
        );
        
        // Atualizar data de modificação
        $wpdb->update(
            $table_name,
            array('meta_value' => $current_time),
            array('order_id' => $order_id, 'meta_key' => '_pix_updated_date'),
            array('%s'),
            array('%d', '%s')
        );
    }
    
    public function pix_shortcode($atts) {
        // Verificar se é página do WooCommerce e se tem parâmetro wcf-order
        if (!isset($_GET['wcf-order'])) {
            return '<p>Erro: ID da ordem não encontrado na URL.</p>';
        }
        
        $order_id = intval($_GET['wcf-order']);
        
        // Verificar se a ordem existe
        $order = wc_get_order($order_id);
        if (!$order) {
            return '<p>Erro: Ordem não encontrada.</p>';
        }
        
        // Verificar se o método de pagamento é "Direct bank transfer"
        if ($order->get_payment_method() !== 'bacs') {
            return '<p>Este método de pagamento não está disponível para esta ordem.</p>';
        }
        
        // Verificar status da transação - esconder botão se já processado
        $transaction_status = $this->get_transaction_status($order_id);
        if (in_array($transaction_status, array('pendente', 'finalizado', 'estornado_admin', 'recusado_admin', 'reembolso', 'estorno_openpix', 'recusado_openpix', 'expirado_openpix'))) {
            return '<p>Pagamento já processado.</p>';
        }
        
        $calculation_result = $this->calculate_total_amount($order_id);
        $total_amount = $calculation_result['total'];
        $debug_info = $calculation_result['debug'];
        
        // Pegar configurações
        $options = get_option('pix_offline_options', array());
        $main_button_text = isset($options['main_button_text']) ? $options['main_button_text'] : 'Pagar com PIX';
        $confirm_button_text = isset($options['confirm_button_text']) ? $options['confirm_button_text'] : 'Já efetuei o pagamento';
        $processing_button_text = isset($options['processing_button_text']) ? $options['processing_button_text'] : 'Processando pagamento...';
        $popup_title = isset($options['popup_title']) ? $options['popup_title'] : 'Pagar com PIX';
        $popup_instruction = isset($options['popup_instruction']) ? $options['popup_instruction'] : 'Abra o app do seu banco.';
        $success_title = isset($options['success_title']) ? $options['success_title'] : 'Obrigado!';
        $success_message = isset($options['success_message']) ? $options['success_message'] : 'Seu pedido está sendo processado e você será notificado por email.';
        $pix_key = isset($options['pix_key']) ? $options['pix_key'] : '21.092.941/0001-72';
        $show_debug = isset($options['show_debug']) ? $options['show_debug'] : '0';
        $enable_pix_copy_paste = isset($options['enable_pix_copy_paste']) ? $options['enable_pix_copy_paste'] : '0';
        
        // Configurações PIX dinâmico
        $enable_pix_dynamic = isset($options['enable_pix_dynamic']) ? $options['enable_pix_dynamic'] : '0';
        $openpix_error_message = isset($options['openpix_error_message']) ? $options['openpix_error_message'] : 'Erro ao gerar PIX. Tente novamente.';
        
        // Determinar qual tipo de PIX usar
        $use_dynamic_pix = ($enable_pix_dynamic === '1');
        
        // Gerar conteúdo PIX (apenas para PIX estático)
        $pix_display_content = '';
        $pix_qr_html = '';
        
        if (!$use_dynamic_pix) {
            // PIX ESTÁTICO (código existente)
            if ($enable_pix_copy_paste === '1') {
                $pix_code = $this->generate_pix_copy_paste($pix_key, $total_amount, $order_id);
                if ($pix_code) {
                    
                    // --- QR embutido (data URI) ---
                    $pix_clean_code = preg_replace('/\s+/', '', $pix_code);

                    $qr_url_google = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl='
                        . rawurlencode($pix_clean_code) . '&choe=UTF-8';

                    $qr_url_alt = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data='
                        . rawurlencode($pix_clean_code);

                    $qr_data_uri = '';
                    foreach (array($qr_url_google, $qr_url_alt) as $qr_src_try) {
                        $resp = wp_remote_get($qr_src_try, array('timeout' => 8));
                        if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200) {
                            $body = wp_remote_retrieve_body($resp);
                            if (!empty($body)) {
                                $qr_data_uri = 'data:image/png;base64,' . base64_encode($body);
                                break;
                            }
                        }
                    }

                    if ($qr_data_uri) {
                        $pix_qr_html = '<div class="pix-qr" style="text-align:center; margin:10px 0;">
                            <img src="' . esc_attr($qr_data_uri) . '" alt="QR Code Pix" width="240" height="240" />
                        </div>';
                    }
                    // --- fim QR embutido ---

                    $pix_display_content = '
                        <div class="pix-copy-paste" style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; margin: 10px 0;">
                            <p><strong>PIX Copia e Cola:</strong></p>
                            <div style="background: #fff; border: 1px solid #ccc; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px; word-break: break-all;">
                                ' . esc_html($pix_code) . '
                            </div>
                            <button onclick="copyPixCode(\'' . esc_js($pix_code) . '\', ' . $order_id . ')" style="margin-top: 10px; padding: 8px 15px; background: #000000; color: white; border: none; border-radius: 4px; cursor: pointer;">
                                <b>Copiar PIX</b>
                            </button>
                        </div>';
                }
            } else {
                $pix_display_content = '<p><strong>Chave PIX:</strong> ' . esc_html($pix_key) . '</p>';
            }
        }
        
        ob_start();
        ?>
        <div id="pix-payment-container">
            <button id="pix-payment-btn" class="pix-btn" 
                    data-order-id="<?php echo esc_attr($order_id); ?>" 
                    data-total="<?php echo esc_attr($total_amount); ?>"
                    data-use-dynamic="<?php echo $use_dynamic_pix ? '1' : '0'; ?>"
                    data-processing-text="<?php echo esc_attr($processing_button_text); ?>">
                <?php echo esc_html($main_button_text); ?>
            </button>
        </div>
        
        <!-- Modal PIX -->
        <div id="pix-modal" class="pix-modal" style="display: none;">
            <div class="pix-modal-content">
                <span class="pix-close">&times;</span>
                <h2><?php echo esc_html($popup_title); ?></h2>
                
                <!-- Container para loading -->
                <div id="pix-loading" class="pix-loading" style="display: none;">
                    <p>Gerando PIX...</p>
                </div>
                
                <!-- Container para erro -->
                <div id="pix-error" class="pix-error-message" style="display: none;">
                    <p id="pix-error-text"><?php echo esc_html($openpix_error_message); ?></p>
                </div>
                
                <!-- Container para conteúdo PIX -->
                <div id="pix-content" class="pix-info">
                    <p><strong>Valor a Pagar:</strong> R$ <span id="pix-total-amount"><?php echo number_format($total_amount, 2, ',', '.'); ?></span></p>
                    
                    <!-- Conteúdo PIX dinâmico (será preenchido via AJAX) -->
                    <div id="pix-dynamic-content" style="display: none;"></div>
                    
                    <!-- Conteúdo PIX estático (se não for dinâmico) -->
                    <div id="pix-static-content" style="<?php echo $use_dynamic_pix ? 'display: none;' : ''; ?>">
                        <?php echo $pix_qr_html . $pix_display_content; ?>
                    </div>
                    
                    <p><em><?php echo esc_html($popup_instruction); ?></em></p>
                </div>
                
                <?php if ($show_debug === '1'): ?>
                <!-- Debug Info -->
                <div class="pix-debug" style="background: #f0f0f0; padding: 15px; margin: 15px 0; border-radius: 5px; font-size: 12px; max-height: 300px; overflow-y: auto;">
                    <strong>DEBUG - Cálculo das Ordens:</strong><br>
                    <?php echo $debug_info; ?>
                    
                    <br><br><strong>DEBUG - Configuração PIX:</strong><br>
                    <?php 
                    // Debug das configurações PIX
                    echo "Tipo de PIX: " . ($use_dynamic_pix ? '<span style="color: #0073aa; font-weight: bold;">DINÂMICO (OpenPIX)</span>' : '<span style="color: #d63384; font-weight: bold;">ESTÁTICO</span>') . '<br>';
                    
                    if ($use_dynamic_pix) {
                        $api_url = isset($options['openpix_api_url']) ? $options['openpix_api_url'] : '';
                        $app_id = isset($options['openpix_app_id']) ? $options['openpix_app_id'] : '';
                        
                        echo "URL da API: " . esc_html($api_url) . '<br>';
                        echo "AppID: " . (strlen($app_id) > 0 ? esc_html(substr($app_id, 0, 6)) . '***' . esc_html(substr($app_id, -3)) : '<span style="color: red;">NÃO CONFIGURADO</span>') . '<br>';
                        echo "Mensagem de Erro: " . esc_html($openpix_error_message) . '<br>';
                    }
                    ?>
                    
                    <br><strong>DEBUG - Status Requisição HTTP:</strong><br>
                    <div id="pix-http-debug" style="font-family: monospace; background: #fff; padding: 8px; border-radius: 3px; margin-top: 5px;">
                        <span style="color: #666;">Aguardando ação do usuário...</span>
                    </div>
                </div>
                <?php endif; ?>
                
                <button id="pix-confirm-btn" class="pix-confirm-btn"><?php echo esc_html($confirm_button_text); ?></button>
            </div>
        </div>
        
        <!-- Modal de Confirmação -->
        <div id="pix-success-modal" class="pix-modal" style="display: none;">
            <div class="pix-modal-content">
                <h2><?php echo esc_html($success_title); ?></h2>
                <p><?php echo esc_html($success_message); ?></p>
                <button id="pix-success-close" class="pix-confirm-btn">Fechar</button>
            </div>
        </div>
        
        <script>
        (function(){
          if (!window.__pixAlertHooked){
            window.__pixAlertHooked = true;
            var __origAlert = window.alert;
            window.alert = function(message){
              try{
                if (String(message).trim() === 'Código PIX copiado!'){
                  pixShowCenterAlert(message);
                  return;
                }
              }catch(e){}
              __origAlert(message);
            };
          }
          function pixShowCenterAlert(message){
            var overlay = document.getElementById('pix-alert-overlay');
            if(!overlay){
              overlay = document.createElement('div');
              overlay.id = 'pix-alert-overlay';
              overlay.innerHTML = '<div id="pix-alert-box" role="dialog" aria-modal="true" aria-live="assertive"><div id="pix-alert-msg"></div><button id="pix-alert-ok" type="button">OK</button></div>';
              document.body.appendChild(overlay);
              document.getElementById('pix-alert-ok').addEventListener('click', function(){ overlay.remove(); });
            }
            document.getElementById('pix-alert-msg').textContent = message;
            overlay.style.display = 'flex';
          }
        })();
        </script>

        <?php
        return ob_get_clean();
    }
    
    private function get_transaction_status($order_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_orders_meta';
        
        $status = $wpdb->get_var($wpdb->prepare("
            SELECT meta_value 
            FROM {$table_name} 
            WHERE order_id = %s 
            AND meta_key = '_pix_status'
        ", $order_id));
        
        return $status;
    }
    
    private function calculate_total_amount($parent_order_id) {
        global $wpdb;
        
        $debug_info = array();
        $total = 0;
        
        // 1. Buscar a ordem principal
        $parent_order = wc_get_order($parent_order_id);
        if (!$parent_order) {
            return array('total' => 0, 'debug' => 'Ordem principal não encontrada');
        }
        
        $total += $parent_order->get_total();
        $debug_info[] = "Ordem Principal #$parent_order_id: R$" . number_format($parent_order->get_total(), 2, ',', '.');
        
        // 2. Buscar TODOS os registros de ordens filhas (pode haver múltiplos registros)
        $child_orders_results = $wpdb->get_results($wpdb->prepare("
            SELECT meta_value 
            FROM {$wpdb->prefix}wc_orders_meta 
            WHERE order_id = %s 
            AND meta_key = '_cartflows_offer_child_orders'
        ", $parent_order_id));
        
        $all_child_ids = array();
        
        if (!empty($child_orders_results)) {
            $debug_info[] = "Encontrados " . count($child_orders_results) . " registros de _cartflows_offer_child_orders";
            
            foreach ($child_orders_results as $result) {
                $debug_info[] = "Meta encontrada: " . $result->meta_value;
                
                // Deserializar cada array
                $child_orders_array = maybe_unserialize($result->meta_value);
                
                if (is_array($child_orders_array) && !empty($child_orders_array)) {
                    $child_ids = array_keys($child_orders_array);
                    $all_child_ids = array_merge($all_child_ids, $child_ids);
                    $debug_info[] = "IDs encontrados neste registro: " . implode(', ', $child_ids);
                }
            }
            
            // Remover duplicatas
            $all_child_ids = array_unique($all_child_ids);
            $debug_info[] = "TODOS os IDs das ordens filhas: " . implode(', ', $all_child_ids);
            
            // 3. Buscar totais de todas as ordens filhas
            if (!empty($all_child_ids)) {
                $ids_string = implode(',', array_map('intval', $all_child_ids));
                
                $child_totals = $wpdb->get_results("
                    SELECT id, total_amount 
                    FROM {$wpdb->prefix}wc_orders 
                    WHERE id IN ($ids_string)
                ");
                
                foreach ($child_totals as $child) {
                    $total += $child->total_amount;
                    $debug_info[] = "Ordem Filha #" . $child->id . ": R$" . number_format($child->total_amount, 2, ',', '.');
                }
            }
        } else {
            $debug_info[] = "Nenhuma meta '_cartflows_offer_child_orders' encontrada para ordem #$parent_order_id";
        }
        
        $debug_info[] = "TOTAL FINAL: R$" . number_format($total, 2, ',', '.');
        
        return array(
            'total' => $total,
            'debug' => implode('<br>', $debug_info)
        );
    }
    
    private function generate_pix_copy_paste($pix_key, $amount, $order_id) {
        // Gerar identificador automático: ID + número do pedido
        $identifier = 'ID' . $order_id;
        
        // Função auxiliar para criar campo EMV
        $create_emv_field = function($id, $value) {
            $length = strlen($value);
            return $id . sprintf('%02d', $length) . $value;
        };
        
        // Montar payload PIX conforme padrão EMV
        $payload = '';
        
        // 00 - Payload Format Indicator
        $payload .= '000201';
        
        // 26 - Merchant Account Information (PIX)
        $pix_data = '';
        $pix_data .= '0014BR.GOV.BCB.PIX';
        $pix_data .= $create_emv_field('01', $pix_key);
        $payload .= $create_emv_field('26', $pix_data);
        
        // 52 - Merchant Category Code
        $payload .= '52040000';
        
        // 53 - Transaction Currency (BRL)
        $payload .= '5303986';
        
        // 54 - Transaction Amount
        if ($amount > 0) {
            $payload .= $create_emv_field('54', number_format($amount, 2, '.', ''));
        }
        
        // 58 - Country Code
        $payload .= '5802BR';
        
        // 59 - Merchant Name (Fixo "N")
        $payload .= '5901N';
        
        // 60 - Merchant City (Fixo "C")
        $payload .= '6001C';
        
        // 62 - Additional Data Field Template (identificador)
        $payload .= $create_emv_field('62', $create_emv_field('05', $identifier));
        
        // 63 - CRC16
        $payload .= '6304';
        $payload .= $this->calculate_crc16($payload);
        
        return $payload;
    }
    
    private function calculate_crc16($data) {
        $result = 0xFFFF;
        for ($i = 0; $i < strlen($data); $i++) {
            $result ^= (ord($data[$i]) << 8);
            for ($j = 0; $j < 8; $j++) {
                if ($result & 0x8000) {
                    $result = ($result << 1) ^ 0x1021;
                } else {
                    $result <<= 1;
                }
                $result &= 0xFFFF;
            }
        }
        return strtoupper(str_pad(dechex($result), 4, '0', STR_PAD_LEFT));
    }
    
    public function process_pix_payment() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'pix_payment_nonce')) {
            wp_die('Erro de segurança');
        }
        
        $order_id = intval($_POST['order_id']);
        $total_amount = floatval($_POST['total_amount']);
        
        $order = wc_get_order($order_id);
        if ($order) {
            // Adicionar nota à ordem
            $order->add_order_note('Cliente confirmou pagamento via PIX offline. Valor: R$ ' . number_format($total_amount, 2, ',', '.'));
            
            // Atualizar status da transação PIX para "pendente"
            $transactions = new PixOfflineTransactions();
            $transactions->update_transaction_status($order_id, 'pendente');
        }
        
        wp_send_json_success(array(
            'message' => 'Pagamento confirmado com sucesso!'
        ));
    }
}

// Inicializar o plugin
new PixOfflinePlugin();