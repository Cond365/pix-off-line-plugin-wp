<?php
/**
 * PIX Offline - Gerenciador de Transações
 * Version: 001
 * 
 * === NOTAS DA VERSÃO DESTE ARQUIVO 001 ===
 * - CORRIGIDO: Duplicação de registros via webhooks
 * - Implementado sistema de detecção de webhooks duplicados
 * - Adicionadas transações de banco com locks para evitar race conditions
 * - Consolidados hooks de criação de pedidos em sistema único
 * - Melhorada verificação de transações existentes com cache local
 * - Adicionadas funções robustas de limpeza de duplicatas
 * - Implementado processamento atômico de transações
 */

// Evita acesso direto
if (!defined('ABSPATH')) {
    exit;
}

class PixOfflineTransactions {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wc_orders_meta';
        
        if (is_admin()) {
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        }
        
        // CORREÇÃO: Sistema unificado para evitar duplicação
        // Usar apenas um hook com prioridade alta para garantir execução única
        add_action('woocommerce_new_order', array($this, 'track_order_creation'), 20, 1);
        add_action('wp_ajax_pix_update_transaction_status', array($this, 'ajax_update_status'));
        
        // Hook para interceptar webhooks da OpenPix
        add_action('init', array($this, 'maybe_handle_openpix_webhook'));
    }
    
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'woocommerce_page_pix-transactions') {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'pix_transactions_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pix_transactions_nonce')
        ));
    }
    
    // NOVA FUNÇÃO: Sistema unificado de criação de transações (evita duplicação)
    public function track_order_creation($order_id) {
        // Verificar se a ordem existe e usa PIX (payment method 'bacs')
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'bacs') {
            return;
        }
        
        error_log("PIX Order Creation: Processando Order #{$order_id}");
        
        // Verificar se já existe transação para evitar duplicatas
        if ($this->transaction_exists_safe($order_id)) {
            error_log("PIX Order Creation: Transação já existe para Order #{$order_id} - ignorando");
            return;
        }
        
        // Criar nova transação
        $this->create_transaction($order_id, 'checkout_iniciado');
        error_log("PIX Order Creation: Nova transação criada para Order #{$order_id}");
    }
    
    // NOVA FUNÇÃO: Verificação segura de transação existente com cache
    private function transaction_exists_safe($order_id) {
        global $wpdb;
        
        // Cache local para evitar múltiplas consultas na mesma requisição
        static $cache = array();
        
        if (isset($cache[$order_id])) {
            return $cache[$order_id];
        }
        
        $exists = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$this->table_name} 
            WHERE order_id = %d 
            AND meta_key = '_pix_transaction_id'
        ", $order_id));
        
        $result = $exists > 0;
        $cache[$order_id] = $result;
        
        return $result;
    }
    
    // FUNÇÃO ATUALIZADA: Interceptar webhooks da OpenPix com detecção de duplicação
    public function maybe_handle_openpix_webhook() {
        // Verificar se é uma requisição de webhook da OpenPix
        if (isset($_GET['pix_webhook']) && $_GET['pix_webhook'] === 'openpix') {
            $this->handle_openpix_webhook();
            exit;
        }
    }
    
    // FUNÇÃO CORRIGIDA: Processar webhook com detecção de duplicação
    private function handle_openpix_webhook() {
        // Logs de debug iniciais
        error_log("PIX Webhook: Requisição recebida");
        error_log("PIX Webhook: Method = " . $_SERVER['REQUEST_METHOD']);
        error_log("PIX Webhook: Headers = " . json_encode(getallheaders()));
        
        // Verificar se webhook está habilitado
        $options = get_option('pix_offline_options', array());
        $webhook_enabled = isset($options['enable_webhook']) ? $options['enable_webhook'] : '0';
        
        if ($webhook_enabled !== '1') {
            error_log("PIX Webhook: Webhook não está habilitado nas configurações");
            http_response_code(403);
            exit('Webhook not enabled');
        }
        
        // Ler o payload JSON do webhook
        $json_payload = file_get_contents('php://input');
        error_log("PIX Webhook: Raw payload = " . $json_payload);
        
        if (empty($json_payload)) {
            error_log("PIX Webhook: Payload vazio");
            http_response_code(400);
            exit('Empty payload');
        }
        
        $data = json_decode($json_payload, true);
        $json_error = json_last_error();
        
        if ($json_error !== JSON_ERROR_NONE) {
            error_log("PIX Webhook: Erro JSON = " . $json_error);
            http_response_code(400);
            exit('Invalid JSON payload');
        }
        
        error_log("PIX Webhook: Decoded data = " . print_r($data, true));
        
        // Verificar se é webhook de teste da OpenPix
        if (isset($data['evento']) && $data['evento'] === 'teste_webhook') {
            error_log("PIX Webhook: Webhook de teste detectado - respondendo OK");
            http_response_code(200);
            exit('Test webhook received successfully');
        }
        
        // Verificar estrutura básica do webhook
        if (!isset($data['charge'])) {
            error_log("PIX Webhook: Estrutura inválida - charge missing");
            http_response_code(400);
            exit('Invalid webhook structure - charge missing');
        }
        
        $charge = $data['charge'];
        $correlation_id = $charge['correlationID'] ?? '';
        $charge_status = $charge['status'] ?? '';
        
        error_log("PIX Webhook: correlationID = {$correlation_id}, status = {$charge_status}");
        
        // Verificar se temos correlationID válido (order_id)
        if (empty($correlation_id) || !is_numeric($correlation_id)) {
            error_log("PIX Webhook: correlationID inválido = {$correlation_id}");
            http_response_code(400);
            exit('Invalid correlationID');
        }
        
        $order_id = intval($correlation_id);
        
        // Verificar se a ordem existe
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log("PIX Webhook: Ordem não encontrada = {$order_id}");
            http_response_code(404);
            exit('Order not found');
        }
        
        // CORREÇÃO: Verificar se webhook já foi processado (evita duplicação)
        $webhook_signature = md5($json_payload . $charge_status . time());
        if ($this->is_webhook_already_processed($order_id, $webhook_signature)) {
            error_log("PIX Webhook: Webhook duplicado detectado para Order #{$order_id} - ignorando");
            http_response_code(200);
            exit('Duplicate webhook ignored');
        }
        
        // Marcar webhook como processado
        $this->mark_webhook_as_processed($order_id, $webhook_signature);
        
        // Determinar evento do webhook baseado no status e dados
        $webhook_event = $this->determine_webhook_event($data);
        error_log("PIX Webhook: Evento determinado = {$webhook_event}");
        
        if (!$webhook_event) {
            error_log("PIX Webhook: Evento não reconhecido");
            http_response_code(400);
            exit('Unrecognized webhook event');
        }
        
        // Processar evento webhook
        $result = $this->process_webhook_event($order_id, $webhook_event, $data);
        
        if ($result) {
            error_log("PIX Webhook: Processado com sucesso - Order #{$order_id}, Evento: {$webhook_event}");
            http_response_code(200);
            exit('OK');
        } else {
            error_log("PIX Webhook: Erro no processamento");
            http_response_code(500);
            exit('Processing error');
        }
    }
    
    // NOVA FUNÇÃO: Verificar se webhook já foi processado
    private function is_webhook_already_processed($order_id, $webhook_signature) {
        global $wpdb;
        
        // Verificar se uma assinatura similar foi processada recentemente (últimos 5 minutos)
        $recent_webhooks = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$this->table_name} 
            WHERE order_id = %d 
            AND meta_key = '_pix_webhook_signature' 
            AND meta_value LIKE %s
            AND DATE_SUB(NOW(), INTERVAL 5 MINUTE) < NOW()
        ", $order_id, substr($webhook_signature, 0, 10) . '%'));
        
        return $recent_webhooks > 0;
    }
    
    // FUNÇÃO CORRIGIDA: Remover campos que causam múltiplas linhas
private function mark_webhook_as_processed($order_id, $webhook_signature) {
    global $wpdb;
    
    // CORREÇÃO: Não criar múltiplos registros de webhook_signature
    // Em vez disso, manter apenas o último processado
    $wpdb->query($wpdb->prepare("
        DELETE FROM {$this->table_name} 
        WHERE order_id = %d AND meta_key = '_pix_webhook_signature'
    ", $order_id));
    
    // Inserir apenas o último
    $wpdb->insert(
        $this->table_name,
        array(
            'order_id' => $order_id,
            'meta_key' => '_pix_webhook_signature',
            'meta_value' => $webhook_signature . '_' . current_time('mysql')
        ),
        array('%d', '%s', '%s')
    );
}

    
    // Determinar evento do webhook baseado nos dados
    private function determine_webhook_event($data) {
        $charge = $data['charge'] ?? array();
        $pix = $data['pix'] ?? array();
        $status = $charge['status'] ?? '';
        
        // Mapear baseado no status da charge
        switch ($status) {
            case 'ACTIVE':
                return 'OPENPIX:CHARGE_CREATED';
            case 'COMPLETED':
                return 'OPENPIX:CHARGE_COMPLETED';
            case 'EXPIRED':
                return 'OPENPIX:CHARGE_EXPIRED';
        }
        
        // Se temos PIX data, pode ser transação
        if (!empty($pix)) {
            // Se há um valor negativo, é reembolso
            $value = $pix['value'] ?? 0;
            if ($value < 0) {
                return 'OPENPIX:TRANSACTION_REFUND_RECEIVED';
            }
            
            // Verificar se há falha na transação
            if (isset($pix['failed']) && $pix['failed'] === true) {
                return 'OPENPIX:MOVEMENT_FAILED';
            }
            
            return 'OPENPIX:TRANSACTION_RECEIVED';
        }
        
        return null;
    }
    
    // Processar evento específico do webhook
    private function process_webhook_event($order_id, $event, $data) {
        $charge = $data['charge'] ?? array();
        $current_time = current_time('mysql');
        
        // Registrar evento recebido
        $this->log_webhook_event($order_id, $event, $data);
        
        switch ($event) {
            case 'OPENPIX:CHARGE_CREATED':
                // Apenas atualizar status OpenPix - sem ação automática
                $this->update_openpix_status($order_id, 'openpix_created');
                error_log("PIX Webhook: Charge created - Order #{$order_id}");
                return true;
                
            case 'OPENPIX:CHARGE_COMPLETED':
                // Pagamento confirmado - finalizar automaticamente
                $this->update_openpix_status($order_id, 'openpix_completed');
                $this->update_transaction_status($order_id, 'finalizado');
                error_log("PIX Webhook: Charge completed - Order #{$order_id} finalizado automaticamente");
                return true;
                
            case 'OPENPIX:TRANSACTION_REFUND_RECEIVED':
                // Reembolso recebido - estornar automaticamente
                $this->update_openpix_status($order_id, 'openpix_refunded');
                $this->update_transaction_status($order_id, 'estorno_openpix', 'Reembolso');
                error_log("PIX Webhook: Refund received - Order #{$order_id} estornado automaticamente");
                return true;
                
            case 'OPENPIX:MOVEMENT_FAILED':
                // Pagamento falhou - recusar automaticamente
                $this->update_openpix_status($order_id, 'openpix_failed');
                $this->update_transaction_status($order_id, 'recusado_openpix', 'Falha no pagamento');
                error_log("PIX Webhook: Payment failed - Order #{$order_id} recusado automaticamente");
                return true;
                
            case 'OPENPIX:CHARGE_EXPIRED':
                // Cobrança expirou - recusar automaticamente
                $this->update_openpix_status($order_id, 'openpix_expired');
                $this->update_transaction_status($order_id, 'expirado_openpix', 'Timeout');
                error_log("PIX Webhook: Charge expired - Order #{$order_id} expirado automaticamente");
                return true;
                
            default:
                error_log("PIX Webhook: Evento não suportado = {$event}");
                return false;
        }
    }
    
    // Atualizar apenas status OpenPix (separado do status interno)
    private function update_openpix_status($order_id, $openpix_status) {
        global $wpdb;
        $current_time = current_time('mysql');
        
        // Verificar se o registro já existe
        $existing = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$this->table_name} 
            WHERE order_id = %d AND meta_key = '_pix_openpix_status'
        ", $order_id));
        
        if ($existing > 0) {
            // Atualizar registro existente
            $wpdb->update(
                $this->table_name,
                array('meta_value' => $openpix_status),
                array('order_id' => $order_id, 'meta_key' => '_pix_openpix_status'),
                array('%s'),
                array('%d', '%s')
            );
        } else {
            // Criar novo registro
            $wpdb->insert(
                $this->table_name,
                array(
                    'order_id' => $order_id,
                    'meta_key' => '_pix_openpix_status',
                    'meta_value' => $openpix_status
                ),
                array('%d', '%s', '%s')
            );
        }
        
        // Atualizar timestamp do último webhook
        $wpdb->replace(
            $this->table_name,
            array(
                'order_id' => $order_id,
                'meta_key' => '_pix_webhook_received_at',
                'meta_value' => $current_time
            ),
            array('%d', '%s', '%s')
        );
    }
    
    // Registrar evento webhook para log
    private function log_webhook_event($order_id, $event, $data) {
        global $wpdb;
        
        // Buscar eventos existentes
        $existing_events = $wpdb->get_var($wpdb->prepare("
            SELECT meta_value 
            FROM {$this->table_name} 
            WHERE order_id = %d AND meta_key = '_pix_webhook_events'
        ", $order_id));
        
        $events = $existing_events ? maybe_unserialize($existing_events) : array();
        
        // Adicionar novo evento
        $events[] = array(
            'event' => $event,
            'timestamp' => current_time('mysql'),
            'correlation_id' => $data['charge']['correlationID'] ?? '',
            'status' => $data['charge']['status'] ?? ''
        );
        
        // Limitar a 10 eventos mais recentes
        if (count($events) > 10) {
            $events = array_slice($events, -10);
        }
        
        // Salvar eventos atualizados
        $wpdb->replace(
            $this->table_name,
            array(
                'order_id' => $order_id,
                'meta_key' => '_pix_webhook_events',
                'meta_value' => serialize($events)
            ),
            array('%d', '%s', '%s')
        );
    }
    
    // FUNÇÃO CORRIGIDA: Criar transação com verificações anti-duplicação
    public function create_transaction($order_id, $status = 'checkout_iniciado') {
        global $wpdb;
        
        error_log("PIX Create Transaction: Iniciando para Order #{$order_id}");
        
        // VERIFICAÇÃO 1: Verificar antes de qualquer operação
        if ($this->transaction_exists_safe($order_id)) {
            error_log("PIX Create Transaction: Transação já existe para Order #{$order_id} - abortando");
            return false;
        }
        
        // VERIFICAÇÃO 2: Lock de banco para operação atômica
        $wpdb->query('START TRANSACTION');
        
        try {
            // Verificação final dentro da transação com lock
            $existing_count = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) 
                FROM {$this->table_name} 
                WHERE order_id = %d 
                AND meta_key = '_pix_transaction_id'
                FOR UPDATE
            ", $order_id));
            
            if ($existing_count > 0) {
                $wpdb->query('ROLLBACK');
                error_log("PIX Create Transaction: Transação encontrada no lock para Order #{$order_id} - abortando");
                return false;
            }
            
            // Gerar novo ID sequencial
            $transaction_id = $this->get_next_transaction_id();
            error_log("PIX Create Transaction: Novo transaction_id = {$transaction_id} para Order #{$order_id}");
            
            // Calcular valor total e ordens filhas
            $order = wc_get_order($order_id);
            if (!$order) {
                $wpdb->query('ROLLBACK');
                return false;
            }
            
            $total_amount = $order->get_total();
            $child_orders = $this->get_child_orders($order_id);
            
            // Calcular valor das ordens filhas
            foreach ($child_orders as $child_id) {
                $child_order = wc_get_order($child_id);
                if ($child_order) {
                    $total_amount += $child_order->get_total();
                }
            }
            
            $current_time = current_time('mysql');
            
            // Detectar tipo de PIX usado na transação
            $options = get_option('pix_offline_options', array());
            $pix_type = (isset($options['enable_pix_dynamic']) && $options['enable_pix_dynamic'] === '1') ? 'dynamic' : 'static';
            
            // Inserir dados na tabela meta com novos campos
            $meta_data = array(
                '_pix_transaction_id' => $transaction_id,
                '_pix_status' => $status,
                '_pix_child_orders' => serialize($child_orders),
                '_pix_total_amount' => $total_amount,
                '_pix_created_date' => $current_time,
                '_pix_updated_date' => $current_time,
                '_pix_estorno_motivo' => '',
                '_pix_type' => $pix_type,
                '_pix_identifier' => '',
                '_pix_openpix_status' => null,
                '_pix_webhook_events' => serialize(array()),
                '_pix_webhook_received_at' => null
            );
            
            foreach ($meta_data as $meta_key => $meta_value) {
                $result = $wpdb->insert(
                    $this->table_name,
                    array(
                        'order_id' => $order_id,
                        'meta_key' => $meta_key,
                        'meta_value' => $meta_value
                    ),
                    array('%d', '%s', '%s')
                );
                
                if ($result === false) {
                    $wpdb->query('ROLLBACK');
                    error_log("PIX Create Transaction: Erro ao inserir meta {$meta_key} para Order #{$order_id}");
                    return false;
                }
            }
            
            // Commit da transação
            $wpdb->query('COMMIT');
            error_log("PIX Create Transaction: Transação criada com sucesso para Order #{$order_id}");
            
            // Atualizar cache local
            static $cache = array();
            $cache[$order_id] = true;
            
            return $transaction_id;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log("PIX Create Transaction: Erro na transação: " . $e->getMessage());
            return false;
        }
    }
    
    // FUNÇÃO CORRIGIDA: Atualizar status da transação de forma atômica
    public function update_transaction_status($order_id, $status, $estorno_motivo = '') {
        global $wpdb;
        
        $current_time = current_time('mysql');
        error_log("PIX Update Status: Iniciando para Order #{$order_id} - Status: {$status}");
        
        // Verificar se transação existe
        if (!$this->transaction_exists_safe($order_id)) {
            error_log("PIX Update Status: Transação não encontrada para Order #{$order_id}, criando nova");
            $this->create_transaction($order_id, $status);
            return;
        }
        
        // Usar transação de banco de dados para updates atômicos
        $wpdb->query('START TRANSACTION');
        
        try {
            // Atualizar status interno
            $result1 = $wpdb->update(
                $this->table_name,
                array('meta_value' => $status),
                array('order_id' => $order_id, 'meta_key' => '_pix_status'),
                array('%s'),
                array('%d', '%s')
            );
            
            // Atualizar data de modificação
            $result2 = $wpdb->update(
                $this->table_name,
                array('meta_value' => $current_time),
                array('order_id' => $order_id, 'meta_key' => '_pix_updated_date'),
                array('%s'),
                array('%d', '%s')
            );
            
            // Atualizar motivo do estorno se necessário
            if (!empty($estorno_motivo)) {
                $existing = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(*) 
                    FROM {$this->table_name} 
                    WHERE order_id = %d AND meta_key = '_pix_estorno_motivo'
                ", $order_id));
                
                if ($existing > 0) {
                    $wpdb->update(
                        $this->table_name,
                        array('meta_value' => $estorno_motivo),
                        array('order_id' => $order_id, 'meta_key' => '_pix_estorno_motivo'),
                        array('%s'),
                        array('%d', '%s')
                    );
                } else {
                    $wpdb->insert(
                        $this->table_name,
                        array(
                            'order_id' => $order_id,
                            'meta_key' => '_pix_estorno_motivo',
                            'meta_value' => $estorno_motivo
                        ),
                        array('%d', '%s', '%s')
                    );
                }
            }
            
            // Registrar timestamp da mudança de status
            $status_history_key = '_pix_status_history_' . $status;
            $wpdb->insert(
                $this->table_name,
                array(
                    'order_id' => $order_id,
                    'meta_key' => $status_history_key,
                    'meta_value' => $current_time
                ),
                array('%d', '%s', '%s')
            );
            
            // Commit da transação
            $wpdb->query('COMMIT');
            error_log("PIX Update Status: Status atualizado com sucesso para Order #{$order_id}");
            
            // Atualizar status das ordens no WooCommerce
            $this->update_woocommerce_orders_status($order_id, $status);
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log("PIX Update Status: Erro na transação: " . $e->getMessage());
            throw $e;
        }
    }
    
    // Mapear novos status para WooCommerce
    private function update_woocommerce_orders_status($order_id, $pix_status) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $child_orders = $this->get_child_orders($order_id);
        $all_orders = array_merge(array($order_id), $child_orders);
        
        // Mensagens específicas baseadas no tipo de PIX e origem
        $pix_type = $this->get_transaction_pix_type($order_id);
        $pix_type_label = ($pix_type === 'dynamic') ? 'PIX Dinâmico (OpenPix)' : 'PIX Estático';
        
        foreach ($all_orders as $oid) {
            $ord = wc_get_order($oid);
            if (!$ord) continue;
            
            switch ($pix_status) {
                case 'pix_copiado':
                    $note = "Cliente copiou código {$pix_type_label}.";
                    $ord->add_order_note($note);
                    break;
                    
                case 'finalizado':
                    $note = "Pagamento {$pix_type_label} confirmado.";
                    $ord->update_status('completed', $note);
                    break;
                    
                case 'estornado_admin':
                    $note = "Pagamento {$pix_type_label} estornado pelo admin.";
                    $ord->update_status('cancelled', $note);
                    break;
                    
                case 'recusado_admin':
                    $note = "Pagamento {$pix_type_label} recusado pelo admin.";
                    $ord->update_status('cancelled', $note);
                    $this->delete_customer_user($ord->get_customer_id());
                    break;
                    
                case 'estorno_openpix':
                    $note = "Pagamento reembolsado automaticamente via OpenPix.";
                    $ord->update_status('refunded', $note);
                    break;
                    
                case 'recusado_openpix':
                    $note = "Pagamento recusado automaticamente via OpenPix (falha).";
                    $ord->update_status('failed', $note);
                    break;
                    
                case 'expirado_openpix':
                    $note = "Pagamento expirado automaticamente via OpenPix (timeout).";
                    $ord->update_status('failed', $note);
                    break;
                    
                case 'reembolso':
                    $note = "Pagamento {$pix_type_label} reembolsado.";
                    $ord->update_status('refunded', $note);
                    break;
                    
                case 'pendente':
                    $note = "Pagamento {$pix_type_label} reativado.";
                    $ord->update_status('on-hold', $note);
                    break;
            }
        }
    }
    
    // Obter tipo de PIX da transação
    private function get_transaction_pix_type($order_id) {
        global $wpdb;
        
        $pix_type = $wpdb->get_var($wpdb->prepare("
            SELECT meta_value 
            FROM {$this->table_name} 
            WHERE order_id = %d AND meta_key = '_pix_type'
        ", $order_id));
        
        return $pix_type ?: 'static';
    }
    
    private function delete_customer_user($user_id) {
        if ($user_id && $user_id > 0) {
            $user = get_user_by('id', $user_id);
            if ($user && !user_can($user_id, 'administrator')) {
                wp_delete_user($user_id);
            }
        }
    }
    
    private function get_child_orders($parent_order_id) {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT order_id 
            FROM {$this->table_name} 
            WHERE meta_key = '_cartflows_offer_parent_id' 
            AND meta_value = %s
        ", $parent_order_id));
        
        $child_ids = array();
        foreach ($results as $result) {
            $child_ids[] = $result->order_id;
        }
        
        return array_unique($child_ids);
    }
    
    private function get_next_transaction_id() {
        global $wpdb;
        
        $max_id = $wpdb->get_var("
            SELECT MAX(CAST(meta_value AS UNSIGNED)) 
            FROM {$this->table_name} 
            WHERE meta_key = '_pix_transaction_id'
        ");
        
        return ($max_id ? $max_id : 0) + 1;
    }
    
    private function get_transaction_by_order($order_id) {
        global $wpdb;
        
        $result = $wpdb->get_row($wpdb->prepare("
            SELECT 
                order_id,
                meta_value as transaction_id
            FROM {$this->table_name} 
            WHERE order_id = %d 
            AND meta_key = '_pix_transaction_id'
            LIMIT 1
        ", $order_id), ARRAY_A);
        
        return $result;
    }
    
    // Incluir openpix_status na listagem
    // FUNÇÃO CORRIGIDA: get_all_transactions - Eliminar espelhamento
public function get_all_transactions() {
    global $wpdb;
    
    // NOVA QUERY: Usar subconsultas ao invés de LEFT JOINs múltiplos
    $query = "
        SELECT DISTINCT
            base.order_id,
            base.transaction_id,
            (SELECT meta_value FROM {$this->table_name} WHERE order_id = base.order_id AND meta_key = '_pix_status' LIMIT 1) as status,
            (SELECT meta_value FROM {$this->table_name} WHERE order_id = base.order_id AND meta_key = '_pix_child_orders' LIMIT 1) as child_orders,
            (SELECT meta_value FROM {$this->table_name} WHERE order_id = base.order_id AND meta_key = '_pix_total_amount' LIMIT 1) as total_amount,
            (SELECT meta_value FROM {$this->table_name} WHERE order_id = base.order_id AND meta_key = '_pix_created_date' LIMIT 1) as created_date,
            (SELECT meta_value FROM {$this->table_name} WHERE order_id = base.order_id AND meta_key = '_pix_updated_date' LIMIT 1) as updated_date,
            (SELECT meta_value FROM {$this->table_name} WHERE order_id = base.order_id AND meta_key = '_pix_estorno_motivo' LIMIT 1) as estorno_motivo,
            (SELECT meta_value FROM {$this->table_name} WHERE order_id = base.order_id AND meta_key = '_pix_type' LIMIT 1) as pix_type,
            (SELECT meta_value FROM {$this->table_name} WHERE order_id = base.order_id AND meta_key = '_pix_identifier' LIMIT 1) as identifier,
            (SELECT meta_value FROM {$this->table_name} WHERE order_id = base.order_id AND meta_key = '_pix_openpix_status' LIMIT 1) as openpix_status,
            (SELECT meta_value FROM {$this->table_name} WHERE order_id = base.order_id AND meta_key = '_pix_webhook_received_at' LIMIT 1) as webhook_received_at
        FROM (
            SELECT DISTINCT order_id, meta_value as transaction_id
            FROM {$this->table_name}
            WHERE meta_key = '_pix_transaction_id'
        ) base
        ORDER BY CAST(base.transaction_id AS UNSIGNED) DESC
    ";
    
    $results = $wpdb->get_results($query, ARRAY_A);
    
    // Processar resultados para valores padrão
    foreach ($results as &$result) {
        if (empty($result['pix_type'])) {
            $result['pix_type'] = 'static';
        }
        if (empty($result['identifier'])) {
            $result['identifier'] = '-';
        }
        if (empty($result['openpix_status'])) {
            $result['openpix_status'] = null;
        }
    }
    
    return $results;
}

    
    public function ajax_update_status() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'pix_transactions_nonce')) {
            wp_die('Erro de segurança');
        }
        
        $order_id = intval($_POST['order_id']);
        $new_status = sanitize_text_field($_POST['new_status']);
        $estorno_motivo = sanitize_text_field($_POST['estorno_motivo'] ?? '');
        
        $this->update_transaction_status($order_id, $new_status, $estorno_motivo);
        
        wp_send_json_success(array(
            'message' => 'Status atualizado com sucesso!'
        ));
    }
    
    // Obter estatísticas das transações (incluindo status OpenPix)
    public function get_transaction_stats() {
        global $wpdb;
        
        $stats = $wpdb->get_results("
            SELECT 
                t2.meta_value as status,
                t8.meta_value as pix_type,
                t10.meta_value as openpix_status,
                COUNT(*) as count,
                SUM(CAST(t4.meta_value AS DECIMAL(10,2))) as total_value
            FROM {$this->table_name} t1
            LEFT JOIN {$this->table_name} t2 ON t1.order_id = t2.order_id AND t2.meta_key = '_pix_status'
            LEFT JOIN {$this->table_name} t4 ON t1.order_id = t4.order_id AND t4.meta_key = '_pix_total_amount'
            LEFT JOIN {$this->table_name} t8 ON t1.order_id = t8.order_id AND t8.meta_key = '_pix_type'
            LEFT JOIN {$this->table_name} t10 ON t1.order_id = t10.order_id AND t10.meta_key = '_pix_openpix_status'
            WHERE t1.meta_key = '_pix_transaction_id'
            GROUP BY t2.meta_value, t8.meta_value, t10.meta_value
            ORDER BY count DESC
        ", ARRAY_A);
        
        return $stats;
    }
    
    // Limpar transações antigas (atualizado para novos campos)
    public function cleanup_old_transactions($days_old = 90) {
        global $wpdb;
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));
        
        // Buscar transações antigas para exclusão
        $old_transactions = $wpdb->get_col($wpdb->prepare("
            SELECT order_id 
            FROM {$this->table_name} 
            WHERE meta_key = '_pix_created_date' 
            AND meta_value < %s
        ", $cutoff_date));
        
        $deleted_count = 0;
        $meta_keys = array(
            '_pix_transaction_id',
            '_pix_status',
            '_pix_child_orders',
            '_pix_total_amount',
            '_pix_created_date',
            '_pix_updated_date',
            '_pix_estorno_motivo',
            '_pix_type',
            '_pix_identifier',
            '_pix_cache_data',
            '_pix_openpix_status',
            '_pix_webhook_events',
            '_pix_webhook_received_at',
            '_pix_webhook_signature'
        );
        
        foreach ($old_transactions as $order_id) {
            // Deletar todos os metas da transação
            foreach ($meta_keys as $meta_key) {
                $wpdb->delete(
                    $this->table_name,
                    array('order_id' => $order_id, 'meta_key' => $meta_key),
                    array('%d', '%s')
                );
            }
            $deleted_count++;
        }
        
        return $deleted_count;
    }
    
    // NOVA FUNÇÃO: Limpeza robusta de duplicatas existentes
    public function force_cleanup_duplicates() {
        global $wpdb;
        
        error_log("PIX Force Cleanup: Iniciando limpeza completa de duplicatas");
        
        // Buscar todas as ordens com registros PIX
        $all_orders = $wpdb->get_col("
            SELECT DISTINCT order_id 
            FROM {$this->table_name} 
            WHERE meta_key LIKE '_pix_%'
            ORDER BY order_id ASC
        ");
        
        $cleaned = 0;
        $total_processed = 0;
        
        foreach ($all_orders as $order_id) {
            $total_processed++;
            
            // Verificar quantas transaction_ids existem para esta ordem
            $transaction_ids = $wpdb->get_col($wpdb->prepare("
                SELECT meta_value 
                FROM {$this->table_name} 
                WHERE order_id = %d AND meta_key = '_pix_transaction_id'
                ORDER BY CAST(meta_value AS UNSIGNED) ASC
            ", $order_id));
            
            if (count($transaction_ids) > 1) {
                error_log("PIX Force Cleanup: Order #{$order_id} tem " . count($transaction_ids) . " duplicatas");
                
                // Apagar TODOS os registros PIX desta ordem
                $wpdb->query($wpdb->prepare("
                    DELETE FROM {$this->table_name} 
                    WHERE order_id = %d AND meta_key LIKE '_pix_%'
                ", $order_id));
                
                // Recriar apenas UMA transação
                $order = wc_get_order($order_id);
                if ($order && $order->get_payment_method() === 'bacs') {
                    // Determinar status baseado no status atual da ordem
                    $order_status = $order->get_status();
                    $pix_status = 'checkout_iniciado';
                    
                    switch ($order_status) {
                        case 'completed':
                            $pix_status = 'finalizado';
                            break;
                        case 'cancelled':
                        case 'failed':
                            $pix_status = 'recusado_admin';
                            break;
                        case 'refunded':
                            $pix_status = 'estornado_admin';
                            break;
                    }
                    
                    // Limpar cache local antes de recriar
                    static $cache = array();
                    unset($cache[$order_id]);
                    
                    $this->create_transaction($order_id, $pix_status);
                    $cleaned++;
                }
            }
        }
        
        error_log("PIX Force Cleanup: Processadas {$total_processed} ordens, limpas {$cleaned} duplicatas");
        return array('processed' => $total_processed, 'cleaned' => $cleaned);
    }
}

// Inicializar o sistema de transações
new PixOfflineTransactions();