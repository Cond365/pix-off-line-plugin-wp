<?php
/**
 * PIX Offline - Painel Administrativo
 * Version: 001
 * 
 * === ALTERAÇÕES VERSÃO 002 ===
 * - SEGURANÇA CONTRA SQL INJECTION
 */

// Evita acesso direto
if (!defined('ABSPATH')) {
    exit;
}

class PixOfflineAdmin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_pix_update_transaction_status', array($this, 'ajax_update_status'));
        add_action('wp_ajax_pix_bulk_action', array($this, 'ajax_bulk_action'));
    }
    
    public function add_admin_menu() {
        // Verificar se o WooCommerce está ativo
        if (class_exists('WooCommerce')) {
            // Página principal de configurações
            add_submenu_page(
                'woocommerce',
                'PIX Offline Setup',
                'PIX Offline',
                'manage_woocommerce',
                'pix-offline-setup',
                array($this, 'admin_page')
            );
            
            // Não precisamos adicionar a página de transações aqui 
            // pois ela já está sendo adicionada pela classe PixOfflineTransactions
        } else {
            // Fallback para Configurações se WooCommerce não estiver ativo
            add_options_page(
                'PIX Offline Setup',
                'PIX Offline',
                'manage_options',
                'pix-offline-setup',
                array($this, 'admin_page')
            );
        }
    }
    
    public function register_settings() {
        register_setting('pix_offline_settings', 'pix_offline_options');
        
        // Seção Aparência
        add_settings_section(
            'pix_appearance_section',
            'Aparência dos Botões',
            array($this, 'appearance_section_callback'),
            'pix-offline-setup'
        );
        
        // Seção Textos
        add_settings_section(
            'pix_texts_section',
            'Textos Personalizados',
            array($this, 'texts_section_callback'),
            'pix-offline-setup'
        );
        
        // Seção PIX Estático
        add_settings_section(
            'pix_info_section',
            'Configurações PIX Estático',
            array($this, 'pix_info_section_callback'),
            'pix-offline-setup'
        );
        
        // Seção PIX Dinâmico
        add_settings_section(
            'pix_dynamic_section',
            'Configurações PIX Dinâmico (OpenPIX)',
            array($this, 'pix_dynamic_section_callback'),
            'pix-offline-setup'
        );
        
        // Seção Debug
        add_settings_section(
            'pix_debug_section',
            'Configurações de Debug',
            array($this, 'debug_section_callback'),
            'pix-offline-setup'
        );
        
        // Campos - Aparência
        add_settings_field(
            'button_color',
            'Cor dos Botões',
            array($this, 'button_color_field'),
            'pix-offline-setup',
            'pix_appearance_section'
        );
        
        add_settings_field(
            'button_hover_color',
            'Cor dos Botões (Hover)',
            array($this, 'button_hover_color_field'),
            'pix-offline-setup',
            'pix_appearance_section'
        );
        
        // Campos - Textos
        add_settings_field(
            'main_button_text',
            'Texto do Botão Principal',
            array($this, 'main_button_text_field'),
            'pix-offline-setup',
            'pix_texts_section'
        );
        
        add_settings_field(
            'confirm_button_text',
            'Texto do Botão de Confirmação',
            array($this, 'confirm_button_text_field'),
            'pix-offline-setup',
            'pix_texts_section'
        );
        
        add_settings_field(
            'popup_title',
            'Título do Popup',
            array($this, 'popup_title_field'),
            'pix-offline-setup',
            'pix_texts_section'
        );
        
        add_settings_field(
            'popup_instruction',
            'Instrução do Popup',
            array($this, 'popup_instruction_field'),
            'pix-offline-setup',
            'pix_texts_section'
        );
        
        add_settings_field(
            'success_title',
            'Título de Sucesso',
            array($this, 'success_title_field'),
            'pix-offline-setup',
            'pix_texts_section'
        );
        
        add_settings_field(
            'success_message',
            'Mensagem de Sucesso',
            array($this, 'success_message_field'),
            'pix-offline-setup',
            'pix_texts_section'
        );
        
        add_settings_field(
            'processing_button_text',
            'Texto Botão Processando',
            array($this, 'processing_button_text_field'),
            'pix-offline-setup',
            'pix_texts_section'
        );
        
        // Campos - PIX Estático
        add_settings_field(
            'pix_key',
            'Chave PIX',
            array($this, 'pix_key_field'),
            'pix-offline-setup',
            'pix_info_section'
        );
        
        add_settings_field(
            'enable_pix_copy_paste',
            'PIX Copia e Cola',
            array($this, 'enable_pix_copy_paste_field'),
            'pix-offline-setup',
            'pix_info_section'
        );
        
        // Campos - PIX Dinâmico
        add_settings_field(
            'enable_pix_dynamic',
            'Habilitar PIX Dinâmico',
            array($this, 'enable_pix_dynamic_field'),
            'pix-offline-setup',
            'pix_dynamic_section'
        );
        
        add_settings_field(
            'openpix_api_url',
            'URL da API OpenPix',
            array($this, 'openpix_api_url_field'),
            'pix-offline-setup',
            'pix_dynamic_section'
        );
        
        add_settings_field(
            'openpix_app_id',
            'AppID (Token de Autorização)',
            array($this, 'openpix_app_id_field'),
            'pix-offline-setup',
            'pix_dynamic_section'
        );
        
        add_settings_field(
            'openpix_error_message',
            'Mensagem de Erro da API',
            array($this, 'openpix_error_message_field'),
            'pix-offline-setup',
            'pix_dynamic_section'
        );
        
        // NOVOS CAMPOS WEBHOOK
        add_settings_field(
            'enable_webhook',
            'Habilitar Webhook OpenPix',
            array($this, 'enable_webhook_field'),
            'pix-offline-setup',
            'pix_dynamic_section'
        );
        
        add_settings_field(
            'webhook_url',
            'URL do Webhook',
            array($this, 'webhook_url_field'),
            'pix-offline-setup',
            'pix_dynamic_section'
        );
        
        // Campos - Debug
        add_settings_field(
            'show_debug',
            'Exibir Debug',
            array($this, 'show_debug_field'),
            'pix-offline-setup',
            'pix_debug_section'
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        // Verificar se estamos na página correta (tanto WooCommerce quanto Configurações)
        if ($hook !== 'woocommerce_page_pix-offline-setup' && $hook !== 'settings_page_pix-offline-setup') {
            return;
        }
        
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        
        wp_add_inline_script('wp-color-picker', '
            jQuery(document).ready(function($) {
                $(".color-picker").wpColorPicker();
                
                // Controle de campos condicionais do PIX Dinâmico
                function toggleDynamicFields() {
                    const isEnabled = $("#enable_pix_dynamic").prop("checked");
                    const dynamicFields = $("#openpix_api_url, #openpix_app_id, #openpix_error_message, #enable_webhook, #webhook_url").closest("tr");
                    
                    if (isEnabled) {
                        dynamicFields.show();
                        toggleWebhookFields();
                    } else {
                        dynamicFields.hide();
                    }
                }
                
                // NOVO: Controle de campos condicionais do Webhook
                function toggleWebhookFields() {
                    const isWebhookEnabled = $("#enable_webhook").prop("checked");
                    const webhookFields = $("#webhook_url").closest("tr");
                    
                    if (isWebhookEnabled) {
                        webhookFields.show();
                    } else {
                        webhookFields.hide();
                    }
                }
                
                // Executar ao carregar a página
                toggleDynamicFields();
                
                // Executar quando checkbox mudar
                $("#enable_pix_dynamic").change(toggleDynamicFields);
                $("#enable_webhook").change(toggleWebhookFields);
            });
        ');
    }
    
    public function admin_page() {
        // Determinar aba ativa
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'setup';
        ?>
        <div class="wrap">
            <h1>PIX Offline</h1>
            
            <!-- Abas de navegação -->
            <h2 class="nav-tab-wrapper">
                <a href="?page=pix-offline-setup&tab=setup" class="nav-tab <?php echo $active_tab == 'setup' ? 'nav-tab-active' : ''; ?>">
                    Setup
                </a>
                <a href="?page=pix-offline-setup&tab=transactions" class="nav-tab <?php echo $active_tab == 'transactions' ? 'nav-tab-active' : ''; ?>">
                    Transações
                </a>
            </h2>
            
            <!-- Conteúdo das abas -->
            <?php if ($active_tab == 'setup'): ?>
                <!-- Aba Setup (configurações atuais) -->
                <form method="post" action="options.php">
                    <?php
                    settings_fields('pix_offline_settings');
                    do_settings_sections('pix-offline-setup');
                    submit_button('Salvar Configurações');
                    ?>
                </form>
                
                <div class="pix-preview" style="margin-top: 30px; padding: 20px; background: #f9f9f9; border-radius: 8px;">
                    <h3>Preview do Botão:</h3>
                    <button id="pix-preview-btn" style="padding: 15px 30px; font-size: 16px; font-weight: bold; border: none; border-radius: 8px; cursor: pointer;">
                        <?php echo esc_html($this->get_option('main_button_text', 'Pagar com PIX')); ?>
                    </button>
                    
                    <!-- Indicador do tipo de PIX -->
                    <div style="margin-top: 15px; padding: 10px; background: #e7f3ff; border-left: 4px solid #0073aa; border-radius: 4px;">
                        <strong>Tipo de PIX Configurado:</strong>
                        <?php 
                        $dynamic_enabled = $this->get_option('enable_pix_dynamic', '0');
                        $webhook_enabled = $this->get_option('enable_webhook', '0');
                        if ($dynamic_enabled === '1') {
                            echo '<span style="color: #0073aa;">PIX Dinâmico (OpenPIX)</span>';
                            if ($webhook_enabled === '1') {
                                echo ' <span style="color: #28a745;">+ Webhook Ativo</span>';
                            }
                        } else {
                            echo '<span style="color: #d63384;">PIX Estático</span>';
                        }
                        ?>
                    </div>
                </div>
                
            <?php elseif ($active_tab == 'transactions'): ?>
                <!-- Aba Transações -->
                <?php $this->show_transactions_page(); ?>
            <?php endif; ?>
        </div>
        
        <style>
        .form-table th {
            width: 200px;
        }
        .pix-preview {
            border: 1px solid #ddd;
        }
        #pix-preview-btn {
            background-color: <?php echo esc_attr($this->get_option('button_color', '#32BCAD')); ?> !important;
            color: white !important;
        }
        #pix-preview-btn:hover {
            background-color: <?php echo esc_attr($this->get_option('button_hover_color', '#28a99a')); ?> !important;
        }
        
        /* Estilo para campos condicionais */
        .pix-conditional-field {
            background: #f8f9fa;
            border-left: 4px solid #0073aa;
            padding-left: 10px;
        }
        
        /* ESTILOS ATUALIZADOS - Status internos expandidos */
        .status-checkout_iniciado { background: #f0f0f0; padding: 4px 8px; border-radius: 4px; }
        .status-pix_gerado { background: #fff3cd; color: #856404; padding: 4px 8px; border-radius: 4px; }
        .status-pix_copiado { background: #cce5ff; color: #004085; padding: 4px 8px; border-radius: 4px; }
        .status-pendente { background: #d1ecf1; color: #0c5460; padding: 4px 8px; border-radius: 4px; }
        .status-finalizado { background: #d4edda; color: #155724; padding: 4px 8px; border-radius: 4px; }
        .status-estornado_admin { background: #f8d7da; color: #721c24; padding: 4px 8px; border-radius: 4px; }
        .status-recusado_admin { background: #f8d7da; color: #721c24; padding: 4px 8px; border-radius: 4px; }
        
        /* NOVOS STATUS INTERNOS */
        .status-reembolso { background: #ffeaa7; color: #6c5ce7; padding: 4px 8px; border-radius: 4px; }
        .status-estorno_openpix { background: #fab1a0; color: #e17055; padding: 4px 8px; border-radius: 4px; }
        .status-recusado_openpix { background: #fdcb6e; color: #e84393; padding: 4px 8px; border-radius: 4px; }
        .status-expirado_openpix { background: #fd79a8; color: #2d3436; padding: 4px 8px; border-radius: 4px; }
        
        /* NOVOS STATUS OPENPIX */
        .openpix-created { background: #74b9ff; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; }
        .openpix-completed { background: #00b894; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; }
        .openpix-refunded { background: #fdcb6e; color: #2d3436; padding: 4px 8px; border-radius: 4px; font-size: 11px; }
        .openpix-failed { background: #e84393; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; }
        .openpix-expired { background: #636e72; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; }
        
        /* Ações em massa */
        .pix-bulk-actions {
            margin: 20px 0;
            padding: 15px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .pix-bulk-actions select,
        .pix-bulk-actions button {
            margin-right: 10px;
        }
        
        /* Modais */
        .pix-modal-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .pix-modal-content {
            background: white;
            padding: 30px;
            border-radius: 8px;
            min-width: 400px;
            max-width: 90%;
        }
        
        .pix-modal-actions {
            margin-top: 20px;
            text-align: right;
        }
        
        .pix-modal-actions button {
            margin-left: 10px;
        }
        
        /* Cliente link */
        .pix-customer-link {
            color: #0073aa;
            text-decoration: none;
        }
        .pix-customer-link:hover {
            color: #005177;
            text-decoration: underline;
        }
        
        /* Coluna identificador */
        .pix-identifier-cell {
            font-family: monospace;
            font-size: 11px;
            max-width: 120px;
            word-break: break-all;
            line-height: 1.3;
        }
        
        /* NOVA: Coluna status OpenPix */
        .pix-openpix-status-cell {
            text-align: center;
            min-width: 100px;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            let selectedTransactions = [];
            
            // Selecionar todas as transações
            $('#pix-select-all').change(function() {
                $('.pix-transaction-checkbox').prop('checked', this.checked);
                updateSelectedTransactions();
            });
            
            // Selecionar transação individual
            $('.pix-transaction-checkbox').change(function() {
                updateSelectedTransactions();
                
                // Atualizar checkbox "selecionar todos"
                const totalCheckboxes = $('.pix-transaction-checkbox').length;
                const checkedCheckboxes = $('.pix-transaction-checkbox:checked').length;
                $('#pix-select-all').prop('checked', totalCheckboxes === checkedCheckboxes);
            });
            
            function updateSelectedTransactions() {
                selectedTransactions = [];
                $('.pix-transaction-checkbox:checked').each(function() {
                    selectedTransactions.push($(this).val());
                });
                
                // Habilitar/desabilitar botão de ação em massa
                $('#pix-bulk-apply').prop('disabled', selectedTransactions.length === 0);
                
                // Atualizar contador
                $('#pix-selected-count').text(selectedTransactions.length);
            }
            
            // Aplicar ação em massa
            $('#pix-bulk-apply').click(function() {
                const action = $('#pix-bulk-action').val();
                if (!action || selectedTransactions.length === 0) {
                    alert('Selecione uma ação e pelo menos uma transação.');
                    return;
                }
                
                if (action === 'delete') {
                    showBulkModal('Excluir Transações', 'Tem certeza que deseja EXCLUIR as ' + selectedTransactions.length + ' transações selecionadas? Esta ação não pode ser desfeita.', action);
                } else if (action === 'finalizar') {
                    showBulkModal('Finalizar Transações', 'Tem certeza que deseja FINALIZAR as ' + selectedTransactions.length + ' transações selecionadas?', action);
                } else if (action === 'estornar') {
                    showBulkModal('Estornar Transações', 'Selecione o motivo do estorno:', action, true);
                } else if (action === 'recusar') {
                    showBulkModal('Recusar Transações', 'Selecione o motivo da recusa:', action, true);
                }
            });
            
            function showBulkModal(title, message, action, showMotivo = false) {
                let motivoOptions = '';
                if (showMotivo) {
                    if (action === 'estornar') {
                        motivoOptions = `
                            <select id="bulk-motivo" style="width: 100%; margin-top: 10px;">
                                <option value="">Selecione o motivo</option>
                                <option value="Reembolso">Reembolso</option>
                                <option value="Duplicidade">Duplicidade</option>
                                <option value="Chargeback">Chargeback</option>
                            </select>
                        `;
                    } else if (action === 'recusar') {
                        motivoOptions = `
                            <select id="bulk-motivo" style="width: 100%; margin-top: 10px;">
                                <option value="">Selecione o motivo</option>
                                <option value="Teste">Teste</option>
                                <option value="Arrependimento do Cliente">Arrependimento do Cliente</option>
                                <option value="Fraude">Fraude</option>
                                <option value="Falha no pagamento">Falha no pagamento</option>
                                <option value="Timeout">Timeout</option>
                            </select>
                        `;
                    }
                }
                
                const modalHtml = `
                    <div id="bulk-modal" class="pix-modal-overlay">
                        <div class="pix-modal-content">
                            <h3>${title}</h3>
                            <p>${message}</p>
                            ${motivoOptions}
                            <div class="pix-modal-actions">
                                <button id="bulk-confirm" class="button button-primary">Confirmar</button>
                                <button id="bulk-cancel" class="button">Cancelar</button>
                            </div>
                        </div>
                    </div>
                `;
                
                $('body').append(modalHtml);
                
                // Confirmar ação
                $('#bulk-confirm').click(function() {
                    let motivo = '';
                    if (showMotivo) {
                        motivo = $('#bulk-motivo').val();
                        if (!motivo) {
                            alert('Selecione o motivo.');
                            return;
                        }
                    }
                    
                    processBulkAction(action, motivo);
                    $('#bulk-modal').remove();
                });
                
                // Cancelar
                $('#bulk-cancel').click(function() {
                    $('#bulk-modal').remove();
                });
            }
            
            function processBulkAction(action, motivo = '') {
                $.post(ajaxurl, {
                    action: 'pix_bulk_action',
                    bulk_action: action,
                    order_ids: selectedTransactions,
                    motivo: motivo,
                    nonce: '<?php echo wp_create_nonce('pix_bulk_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert('Erro ao processar ação em massa: ' + (response.data ? response.data.message : 'Erro desconhecido'));
                    }
                });
            }
            
            // Manipular cliques nos botões de ação individual
            let currentOrderId = null;
            
            $('.pix-action-btn').click(function() {
                const orderId = $(this).data('order-id');
                const action = $(this).data('action');
                
                if (action === 'estornar') {
                    currentOrderId = orderId;
                    $('#estorno-modal').show();
                } else if (action === 'recusar') {
                    currentOrderId = orderId;
                    $('#recusa-modal').show();
                } else {
                    updateTransactionStatus(orderId, action);
                }
            });
            
            // Confirmar estorno
            $('#confirmar-estorno').click(function() {
                const motivo = $('#estorno-motivo').val();
                if (!motivo) {
                    alert('Selecione o motivo do estorno');
                    return;
                }
                
                updateTransactionStatus(currentOrderId, 'estornar', motivo);
                $('#estorno-modal').hide();
                $('#estorno-motivo').val('');
            });
            
            // Confirmar recusa
            $('#confirmar-recusa').click(function() {
                const motivo = $('#recusa-motivo').val();
                if (!motivo) {
                    alert('Selecione o motivo da recusa');
                    return;
                }
                
                updateTransactionStatus(currentOrderId, 'recusar', motivo);
                $('#recusa-modal').hide();
                $('#recusa-motivo').val('');
            });
            
            // Cancelar modais
            $('#cancelar-estorno').click(function() {
                $('#estorno-modal').hide();
                $('#estorno-motivo').val('');
            });
            
            $('#cancelar-recusa').click(function() {
                $('#recusa-modal').hide();
                $('#recusa-motivo').val('');
            });
            
            function updateTransactionStatus(orderId, action, motivo = '') {
                const statusMap = {
                    'finalizar': 'finalizado',
                    'estornar': 'estornado_admin',
                    'reativar': 'pendente',
                    'recusar': 'recusado_admin'
                };
                
                $.post(ajaxurl, {
                    action: 'pix_update_transaction_status',
                    order_id: orderId,
                    new_status: statusMap[action],
                    estorno_motivo: motivo,
                    nonce: '<?php echo wp_create_nonce('pix_transactions_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Erro ao atualizar status');
                    }
                });
            }
        });
        </script>
        <?php
    }
    
    // Callbacks das seções
    public function appearance_section_callback() {
        echo '<p>Personalize a aparência dos botões PIX.</p>';
    }
    
    public function texts_section_callback() {
        echo '<p>Customize os textos exibidos no plugin.</p>';
    }
    
    public function pix_info_section_callback() {
        echo '<p>Configure as informações do PIX estático tradicional.</p>';
    }
    
    // Callback da seção PIX Dinâmico
    public function pix_dynamic_section_callback() {
        echo '<p>Configure o PIX dinâmico usando a API da OpenPix. <strong>Atenção:</strong> Se habilitado, o PIX dinâmico será usado no lugar do PIX estático.</p>';
        echo '<div style="background: #fff3cd; color: #856404; padding: 12px; border-radius: 4px; margin: 10px 0;"><strong>Importante:</strong> Os campos abaixo só ficam visíveis quando o PIX dinâmico estiver habilitado.</div>';
    }
    
    public function debug_section_callback() {
        echo '<p>Configurações de debug para desenvolvimento.</p>';
    }
    
    // Campos do formulário
    public function button_color_field() {
        $value = $this->get_option('button_color', '#32BCAD');
        echo '<input type="text" name="pix_offline_options[button_color]" value="' . esc_attr($value) . '" class="color-picker" />';
    }
    
    public function button_hover_color_field() {
        $value = $this->get_option('button_hover_color', '#28a99a');
        echo '<input type="text" name="pix_offline_options[button_hover_color]" value="' . esc_attr($value) . '" class="color-picker" />';
    }
    
    public function main_button_text_field() {
        $value = $this->get_option('main_button_text', 'Pagar com PIX');
        echo '<input type="text" name="pix_offline_options[main_button_text]" value="' . esc_attr($value) . '" class="regular-text" />';
    }
    
    public function confirm_button_text_field() {
        $value = $this->get_option('confirm_button_text', 'Já efetuei o pagamento');
        echo '<input type="text" name="pix_offline_options[confirm_button_text]" value="' . esc_attr($value) . '" class="regular-text" />';
    }
    
    public function popup_title_field() {
        $value = $this->get_option('popup_title', 'Pagar com PIX');
        echo '<input type="text" name="pix_offline_options[popup_title]" value="' . esc_attr($value) . '" class="regular-text" />';
    }
    
    public function popup_instruction_field() {
        $value = $this->get_option('popup_instruction', 'Abra o app do seu banco.');
        echo '<input type="text" name="pix_offline_options[popup_instruction]" value="' . esc_attr($value) . '" class="regular-text" />';
    }
    
    public function success_title_field() {
        $value = $this->get_option('success_title', 'Obrigado!');
        echo '<input type="text" name="pix_offline_options[success_title]" value="' . esc_attr($value) . '" class="regular-text" />';
    }
    
    public function success_message_field() {
        $value = $this->get_option('success_message', 'Seu pedido está sendo processado e você será notificado por email.');
        echo '<textarea name="pix_offline_options[success_message]" class="regular-text" rows="3">' . esc_textarea($value) . '</textarea>';
    }
    
    public function processing_button_text_field() {
        $value = $this->get_option('processing_button_text', 'Processando pagamento...');
        echo '<input type="text" name="pix_offline_options[processing_button_text]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Texto exibido no botão quando o pagamento está sendo processado.</p>';
    }
    
    public function pix_key_field() {
        $value = $this->get_option('pix_key', '21.092.941/0001-72');
        echo '<input type="text" name="pix_offline_options[pix_key]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Insira sua chave PIX (CPF, CNPJ, email, telefone ou chave aleatória).</p>';
    }
    
    public function enable_pix_copy_paste_field() {
        $value = $this->get_option('enable_pix_copy_paste', '0');
        echo '<label><input type="checkbox" name="pix_offline_options[enable_pix_copy_paste]" value="1" ' . checked($value, '1', false) . ' /> Gerar PIX Copia e Cola (padrão BACEN)</label>';
        echo '<p class="description">Se habilitado, exibe o código PIX Copia e Cola ao invés da chave estática. O identificador será gerado automaticamente como ID[número_do_pedido].</p>';
    }
    
    // Campos PIX Dinâmico
    public function enable_pix_dynamic_field() {
        $value = $this->get_option('enable_pix_dynamic', '0');
        echo '<label><input type="checkbox" id="enable_pix_dynamic" name="pix_offline_options[enable_pix_dynamic]" value="1" ' . checked($value, '1', false) . ' /> Usar PIX Dinâmico ao invés do PIX Estático</label>';
        echo '<p class="description"><strong>Importante:</strong> Se marcado, o PIX dinâmico será usado no lugar do PIX estático. Certifique-se de configurar todos os campos abaixo.</p>';
    }
    
    public function openpix_api_url_field() {
        $value = $this->get_option('openpix_api_url', 'https://api.openpix.com.br/api/v1/charge');
        echo '<input type="url" id="openpix_api_url" name="pix_offline_options[openpix_api_url]" value="' . esc_attr($value) . '" class="regular-text" placeholder="https://api.openpix.com.br/api/v1/charge" />';
        echo '<p class="description">URL da API OpenPix para gerar cobranças. Use <strong>https://api.openpix.com.br/api/v1/charge</strong> para produção ou <strong>https://api.woovi-sandbox.com/api/v1/charge</strong> para testes.</p>';
    }
    
    public function openpix_app_id_field() {
        $value = $this->get_option('openpix_app_id', '');
        echo '<input type="text" id="openpix_app_id" name="pix_offline_options[openpix_app_id]" value="' . esc_attr($value) . '" class="regular-text" placeholder="Seu AppID da OpenPix" />';
        echo '<p class="description">Token de autorização fornecido pela OpenPix. <strong>Mantenha este token seguro!</strong></p>';
    }
    
    public function openpix_error_message_field() {
        $value = $this->get_option('openpix_error_message', 'Erro ao gerar PIX. Tente novamente.');
        echo '<input type="text" id="openpix_error_message" name="pix_offline_options[openpix_error_message]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Mensagem exibida quando a API da OpenPix falha ou retorna erro.</p>';
    }
    
    
    
    // NOVOS CAMPOS WEBHOOK
    public function enable_webhook_field() {
        $value = $this->get_option('enable_webhook', '0');
        echo '<label><input type="checkbox" id="enable_webhook" name="pix_offline_options[enable_webhook]" value="1" ' . checked($value, '1', false) . ' /> Habilitar processamento automático via webhook</label>';
        echo '<p class="description"><strong>Webhook OpenPix:</strong> Permite processamento automático de pagamentos, estornos e falhas. Configure o webhook na OpenPix para apontar para sua URL.</p>';
    }
    
    public function webhook_url_field() {
        $site_url = site_url();
        $default_webhook = $site_url . '/?pix_webhook=openpix';
        $value = $this->get_option('webhook_url', $default_webhook);
        echo '<input type="url" id="webhook_url" name="pix_offline_options[webhook_url]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">URL que receberá os webhooks da OpenPix. <strong>Configure esta URL no painel da OpenPix.</strong><br>';
        echo 'URL padrão: <code>' . esc_html($default_webhook) . '</code></p>';
    }
    
    public function show_debug_field() {
        $value = $this->get_option('show_debug', '0');
        echo '<label><input type="checkbox" name="pix_offline_options[show_debug]" value="1" ' . checked($value, '1', false) . ' /> Exibir informações de debug no popup</label>';
    }
    
    // Função auxiliar para pegar opções
    public function get_option($key, $default = '') {
        $options = get_option('pix_offline_options', array());
        return isset($options[$key]) ? $options[$key] : $default;
    }
    
    // FUNÇÃO ATUALIZADA: Página de transações PIX com nova coluna Status OpenPix
    public function show_transactions_page() {
        // Instanciar classe de transações para pegar os dados
        $transactions_handler = new PixOfflineTransactions();
        $transactions = $transactions_handler->get_all_transactions();
        ?>
        
        <!-- Controles de ações em massa -->
        <div class="pix-bulk-actions">
            <label>
                <input type="checkbox" id="pix-select-all" />
                Selecionar todas
            </label>
            
            <select id="pix-bulk-action">
                <option value="">Ações em massa</option>
                <option value="finalizar">Finalizar</option>
                <option value="estornar">Estornar</option>
                <option value="recusar">Recusar</option>
                <option value="delete">Excluir</option>
            </select>
            
            <button id="pix-bulk-apply" class="button" disabled>
                Aplicar (<span id="pix-selected-count">0</span> selecionadas)
            </button>
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 40px;">
                        <input type="checkbox" id="pix-select-all-header" style="display: none;" />
                    </th>
                    <th>ID</th>
                    <th>Status Interno</th>
                    <th>Status OpenPix</th>
                    <th>Order - Parent</th>
                    <th>Order - Child</th>
                    <th>Valor</th>
                    <th>Cliente</th>
                    <th>Criado</th>
                    <th>Atualizado</th>
                    <th>Identificador</th>
                    <th>Motivo</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                <tr>
                    <td colspan="13">Nenhuma transação PIX encontrada.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($transactions as $transaction): ?>
                <?php
                    $order = wc_get_order($transaction['order_id']);
                    $child_orders = unserialize($transaction['child_orders'] ?? '');
                    $status_labels = array(
                        'checkout_iniciado' => 'Checkout Iniciado',
                        'pix_gerado' => 'PIX Gerado', 
                        'pix_copiado' => 'PIX Copiado',
                        'pendente' => 'Pendente',
                        'finalizado' => 'Finalizado',
                        'estornado_admin' => 'Estornado pelo Admin',
                        'recusado_admin' => 'Recusado pelo Admin',
                        'reembolso' => 'Reembolso',
                        'estorno_openpix' => 'Estorno OpenPix',
                        'recusado_openpix' => 'Recusado OpenPix',
                        'expirado_openpix' => 'Expirado OpenPix'
                    );
                    
                    $openpix_labels = array(
                        'openpix_created' => 'Criado',
                        'openpix_completed' => 'Pago',
                        'openpix_refunded' => 'Reembolsado',
                        'openpix_failed' => 'Falhado',
                        'openpix_expired' => 'Expirado'
                    );
                    
                    // Obter dados do cliente
                    $customer_email = $order ? $order->get_billing_email() : '-';
                    $customer_name = $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : '';
                    $customer_id = $order ? $order->get_customer_id() : 0;
                    
                    // Obter identificador e status OpenPix
                    $identifier = isset($transaction['identifier']) ? $transaction['identifier'] : '-';
                    $openpix_status = isset($transaction['openpix_status']) ? $transaction['openpix_status'] : null;
                ?>
                <tr>
                    <td>
                        <input type="checkbox" class="pix-transaction-checkbox" value="<?php echo $transaction['order_id']; ?>" />
                    </td>
                    <td><?php echo esc_html($transaction['transaction_id']); ?></td>
                    <td>
                        <span class="status-<?php echo esc_attr($transaction['status']); ?>">
                            <?php echo esc_html($status_labels[$transaction['status']] ?? $transaction['status']); ?>
                        </span>
                    </td>
                    <td class="pix-openpix-status-cell">
                        <?php if ($openpix_status): ?>
                            <span class="<?php echo esc_attr($openpix_status); ?>">
                                <?php echo esc_html($openpix_labels[$openpix_status] ?? $openpix_status); ?>
                            </span>
                        <?php else: ?>
                            <span style="color: #999;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo admin_url('post.php?post=' . $transaction['order_id'] . '&action=edit'); ?>" target="_blank">
                            #<?php echo $transaction['order_id']; ?>
                        </a>
                    </td>
                    <td>
                        <?php if (!empty($child_orders) && is_array($child_orders)): ?>
                            <?php foreach ($child_orders as $child_id): ?>
                                <a href="<?php echo admin_url('post.php?post=' . $child_id . '&action=edit'); ?>" target="_blank">
                                    #<?php echo $child_id; ?>
                                </a>
                                <?php if ($child_id !== end($child_orders)) echo ', '; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>R$ <?php echo number_format($transaction['total_amount'], 2, ',', '.'); ?></td>
                    <td>
                        <?php if ($customer_id > 0): ?>
                            <a href="<?php echo admin_url('user-edit.php?user_id=' . $customer_id); ?>" class="pix-customer-link" target="_blank">
                                <?php echo $customer_name ? esc_html($customer_name) : esc_html($customer_email); ?>
                            </a>
                        <?php else: ?>
                            <?php echo esc_html($customer_email); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('d/m/Y H:i', strtotime($transaction['created_date'])); ?></td>
                    <td><?php echo date('d/m/Y H:i', strtotime($transaction['updated_date'])); ?></td>
                    <td class="pix-identifier-cell"><?php echo esc_html($identifier); ?></td>
                    <td><?php echo esc_html($transaction['estorno_motivo'] ?? '-'); ?></td>
                    <td>
                        <?php 
                        $status = $transaction['status'];
                        $order_id = $transaction['order_id'];
                        ?>
                        <?php if (in_array($status, ['pendente', 'pix_copiado'])): ?>
                            <button class="button button-primary pix-action-btn" 
                                    data-order-id="<?php echo $order_id; ?>" 
                                    data-action="finalizar">
                                Finalizar
                            </button>
                            <button class="button button-secondary pix-action-btn" 
                                    data-order-id="<?php echo $order_id; ?>" 
                                    data-action="recusar" style="margin-left: 5px;">
                                Recusar
                            </button>
                        <?php elseif ($status === 'finalizado'): ?>
                            <button class="button button-secondary pix-action-btn" 
                                    data-order-id="<?php echo $order_id; ?>" 
                                    data-action="estornar">
                                Estornar
                            </button>
                        <?php elseif (in_array($status, ['estornado_admin', 'reembolso', 'estorno_openpix'])): ?>
                            <button class="button button-primary pix-action-btn" 
                                    data-order-id="<?php echo $order_id; ?>" 
                                    data-action="reativar">
                                Reativar
                            </button>
                        <?php elseif (in_array($status, ['recusado_admin', 'recusado_openpix', 'expirado_openpix'])): ?>
                            <span style="color: #d63384;">Recusado</span>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Modal de Estorno (individual) -->
        <div id="estorno-modal" class="pix-modal-overlay" style="display: none;">
            <div class="pix-modal-content">
                <h3>Motivo do Estorno</h3>
                <select id="estorno-motivo" style="width: 100%;">
                    <option value="">Selecione o motivo</option>
                    <option value="Reembolso">Reembolso</option>
                    <option value="Duplicidade">Duplicidade</option>
                    <option value="Chargeback">Chargeback</option>
                </select>
                <div class="pix-modal-actions">
                    <button id="confirmar-estorno" class="button button-primary">Confirmar</button>
                    <button id="cancelar-estorno" class="button">Cancelar</button>
                </div>
            </div>
        </div>
        
        <!-- Modal de Recusa (individual) -->
        <div id="recusa-modal" class="pix-modal-overlay" style="display: none;">
            <div class="pix-modal-content">
                <h3>Motivo da Recusa</h3>
                <select id="recusa-motivo" style="width: 100%;">
                    <option value="">Selecione o motivo</option>
                    <option value="Teste">Teste</option>
                    <option value="Arrependimento do Cliente">Arrependimento do Cliente</option>
                    <option value="Fraude">Fraude</option>
                    <option value="Falha no pagamento">Falha no pagamento</option>
                    <option value="Timeout">Timeout</option>
                </select>
                <div class="pix-modal-actions">
                    <button id="confirmar-recusa" class="button button-primary">Confirmar Recusa</button>
                    <button id="cancelar-recusa" class="button">Cancelar</button>
                </div>
            </div>
        </div>
        <?php
    }
    
    // Nova função para processar ações em massa
    public function ajax_bulk_action() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'pix_bulk_nonce')) {
            wp_die('Erro de segurança');
        }
        
        // Verificar capability
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions'));
        }
        
        $bulk_action = sanitize_text_field($_POST['bulk_action'] ?? '');
        $order_ids = array_map('absint', (array) ($_POST['order_ids'] ?? array()));
        $motivo = wp_kses_post($_POST['motivo'] ?? '');
        
        // Validar ação
        $allowed_actions = array('finalizar', 'estornar', 'recusar', 'delete');
        if (!in_array($bulk_action, $allowed_actions)) {
            wp_send_json_error(array('message' => 'Invalid action'));
        }
        
        // Filtrar IDs válidos
        $order_ids = array_filter($order_ids, function($id) {
            return $id > 0 && wc_get_order($id) !== false;
        });
        
        if (empty($order_ids)) {
            wp_send_json_error(array('message' => 'Nenhuma transação selecionada'));
        }
        
        $transactions_handler = new PixOfflineTransactions();
        $processed = 0;
        
        foreach ($order_ids as $order_id) {
            switch ($bulk_action) {
                case 'finalizar':
                    $transactions_handler->update_transaction_status($order_id, 'finalizado');
                    $processed++;
                    break;
                    
                case 'estornar':
                    $transactions_handler->update_transaction_status($order_id, 'estornado_admin', $motivo);
                    $processed++;
                    break;
                    
                case 'recusar':
                    $transactions_handler->update_transaction_status($order_id, 'recusado_admin', $motivo);
                    $processed++;
                    break;
                    
                case 'delete':
                    $this->delete_transaction($order_id);
                    $processed++;
                    break;
            }
        }
        
        $action_names = array(
            'finalizar' => 'finalizadas',
            'estornar' => 'estornadas',
            'recusar' => 'recusadas',
            'delete' => 'excluídas'
        );
        
        $action_name = $action_names[$bulk_action] ?? 'processadas';
        
        wp_send_json_success(array(
            'message' => "{$processed} transações {$action_name} com sucesso!"
        ));
    }
    
    // Função para excluir transação
    private function delete_transaction($order_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_orders_meta';
        
        // Lista de meta keys para excluir
        $meta_keys = array(
            '_pix_transaction_id',
            '_pix_status', 
            '_pix_child_orders',
            '_pix_total_amount',
            '_pix_created_date',
            '_pix_updated_date',
            '_pix_estorno_motivo',
            '_pix_identifier',
            '_pix_cache_data',
            '_pix_openpix_status',
            '_pix_webhook_events',
            '_pix_webhook_received_at'
        );
        
        foreach ($meta_keys as $meta_key) {
            $wpdb->delete(
                $table_name,
                array('order_id' => $order_id, 'meta_key' => $meta_key),
                array('%d', '%s')
            );
        }
    }
    
    // Função para atualizar status individual (mantida para compatibilidade)
    public function ajax_update_status() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'pix_transactions_nonce')) {
            wp_die('Erro de segurança');
        }
        
        $order_id = intval($_POST['order_id']);
        $new_status = sanitize_text_field($_POST['new_status']);
        $estorno_motivo = sanitize_text_field($_POST['estorno_motivo'] ?? '');
        
        $transactions_handler = new PixOfflineTransactions();
        $transactions_handler->update_transaction_status($order_id, $new_status, $estorno_motivo);
        
        wp_send_json_success(array(
            'message' => 'Status atualizado com sucesso!'
        ));
    }
}

// Inicializar apenas no admin
if (is_admin()) {
    new PixOfflineAdmin();
}
