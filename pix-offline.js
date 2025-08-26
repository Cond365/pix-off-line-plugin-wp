/**
 * PIX Offline - JavaScript Frontend
 * Version: 001
 * 
 * === NOTAS DA VERSÃO DESTE ARQUIVO 001 ===
 * - Redesign completo do popup "PIX copiado" integrado ao tema principal
 * - Removido ícone ✅ e aplicadas cores do tema (#32BCAD)
 * - Popup não fecha automaticamente (removido setTimeout)
 * - Popup não fecha com ESC ou clique fora (obrigatório clicar OK)
 * - Melhorias na animação e visual do popup
 * - Mantidas todas funcionalidades existentes de PIX dinâmico/estático
 * - Compatibilidade total com sistema de webhooks
 */

jQuery(document).ready(function($) {
    
    // Abrir modal PIX (com recálculo de transação + lógica PIX dinâmico/estático)
    $('#pix-payment-btn').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var orderId = $btn.data('order-id');
        var totalAmount = $btn.data('total');
        var useDynamic = ($btn.data('use-dynamic') == '1') || ($btn.data('use-dynamic') === 1);
        var processingText = $btn.data('processing-text') || 'Carregando...';
        var originalText = $btn.text();
        
        // Debug da detecção do tipo PIX
        const dynamicValue = $btn.data('use-dynamic');
        updateHttpDebug('🔍 DEBUG: data-use-dynamic = ' + dynamicValue + ' (tipo: ' + typeof dynamicValue + ')', 'info');
        updateHttpDebug('🔍 DEBUG: useDynamic detectado como = ' + useDynamic, useDynamic ? 'success' : 'warning');
        
        // Desabilitar botão e mostrar loading
        $btn.prop('disabled', true).text(processingText);
        
        // Fazer chamada AJAX para recalcular transação e atualizar status para "pix_gerado"
        $.ajax({
            url: pix_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'pix_recalculate_transaction',
                order_id: orderId,
                nonce: pix_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Atualizar valor total no modal se retornado
                    if (response.data.total_amount) {
                        $btn.data('total', response.data.total_amount);
                        $('#pix-total-amount').text(response.data.total_amount_formatted);
                        totalAmount = response.data.total_amount; // Atualizar variável local
                    }
                    
                    // Abrir modal após recálculo bem-sucedido
                    $('#pix-modal').fadeIn(300);
                    
                    // Decidir qual tipo de PIX usar
                    if (useDynamic) {
                        // Debug inicial
                        updateHttpDebug('🚀 PIX DINÂMICO detectado! Iniciando processo...', 'info');
                        // PIX DINÂMICO - Fazer chamada para OpenPix API
                        generateDynamicPix(orderId, totalAmount);
                    } else {
                        // Debug inicial
                        updateHttpDebug('🔌 PIX ESTÁTICO detectado! Usando conteúdo pré-gerado...', 'info');
                        // PIX ESTÁTICO - Usar conteúdo já gerado
                        showStaticPixContent();
                    }
                    
                } else {
                    alert('Erro ao carregar informações PIX. Tente novamente.');
                }
            },
            error: function() {
                alert('Erro de conexão. Tente novamente.');
            },
            complete: function() {
                // Reabilitar botão
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });
    
    // Gerar PIX dinâmico via OpenPix API com cache
    function generateDynamicPix(orderId, totalAmount) {
        // Mostrar loading
        $('#pix-loading').show();
        $('#pix-error').hide();
        $('#pix-content').hide();
        
        // Debug HTTP - Inicialização
        updateHttpDebug('📄 INICIANDO requisição PIX dinâmico...', 'info');
        
        // Verificar se temos o nonce necessário (aviso, mas não bloqueia)
        if (!pix_ajax.dynamic_nonce) {
            updateHttpDebug('⚠️ AVISO: dynamic_nonce não encontrado! Usando nonce padrão.', 'warning');
        } else {
            updateHttpDebug('✅ Nonce dinâmico verificado: ' + pix_ajax.dynamic_nonce.substring(0, 6) + '***', 'info');
        }
        
        // Preparar dados para debug
        const payload = {
            correlationID: orderId.toString(),
            value: Math.round(totalAmount * 100)
        };
        
        updateHttpDebug('📤 Payload preparado: ' + JSON.stringify(payload), 'info');
        updateHttpDebug('⏳ Enviando para OpenPix API (ou verificando cache)...', 'info');
        
        // Fazer requisição AJAX para gerar PIX dinâmico
        $.ajax({
            url: pix_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'pix_generate_dynamic',
                order_id: orderId,
                total_amount: totalAmount,
                nonce: pix_ajax.dynamic_nonce || pix_ajax.nonce  // Fallback para nonce padrão
            },
            timeout: 20000, // 20 segundos
            beforeSend: function() {
                updateHttpDebug('🌐 Conectando com servidor WordPress...', 'info');
            },
            success: function(response) {
                updateHttpDebug('✅ Resposta WordPress recebida: ' + JSON.stringify({success: response.success, hasData: !!response.data, cached: response.data ? response.data.cached : false}), 'success');
                
                if (response.success && response.data) {
                    if (response.data.cached) {
                        updateHttpDebug('💾 PIX dinâmico obtido do CACHE (sem nova requisição API)!', 'success');
                    } else {
                        updateHttpDebug('🎉 PIX dinâmico gerado com SUCESSO via API!', 'success');
                    }
                    updateHttpDebug('📋 Dados recebidos: brCode=' + (response.data.brCode ? 'SIM' : 'NÃO') + ', qrCodeImage=' + (response.data.qrCodeImage ? 'SIM' : 'NÃO') + ', identifier=' + (response.data.identifier ? 'SIM' : 'NÃO'), 'success');
                    
                    // Sucesso - exibir PIX dinâmico
                    displayDynamicPix(response.data);
                } else {
                    // Erro na resposta
                    const errorMsg = response.data ? response.data.message : 'Erro desconhecido ao gerar PIX';
                    updateHttpDebug('❌ ERRO WordPress: ' + errorMsg, 'error');
                    showPixError(errorMsg);
                }
            },
            error: function(xhr, status, error) {
                // Erro na requisição
                let errorMsg = 'Erro ao gerar PIX dinâmico. Tente novamente.';
                let debugMsg = '❌ ERRO AJAX: ';
                
                if (status === 'timeout') {
                    errorMsg = 'Tempo limite esgotado. Tente novamente.';
                    debugMsg += 'TIMEOUT após 20 segundos';
                } else if (xhr.status) {
                    debugMsg += 'HTTP ' + xhr.status + ' - ' + error;
                    if (xhr.responseText) {
                        try {
                            const errorData = JSON.parse(xhr.responseText);
                            if (errorData.data && errorData.data.message) {
                                errorMsg = errorData.data.message;
                                debugMsg += ' | Mensagem: ' + errorData.data.message;
                            }
                        } catch (e) {
                            debugMsg += ' | Resposta: ' + xhr.responseText.substring(0, 100) + '...';
                        }
                    }
                } else {
                    debugMsg += 'Conexão falhou - ' + status + ' - ' + error;
                }
                
                updateHttpDebug(debugMsg, 'error');
                showPixError(errorMsg);
            },
            complete: function(xhr, status) {
                $('#pix-loading').hide();
                updateHttpDebug('🏁 Requisição finalizada. Status: ' + status.toUpperCase(), status === 'success' ? 'success' : 'error');
            }
        });
    }
    
    // Função para atualizar debug HTTP em tempo real
    function updateHttpDebug(message, type) {
        const debugContainer = $('#pix-http-debug');
        if (debugContainer.length === 0) return; // Debug não habilitado
        
        const timestamp = new Date().toLocaleTimeString('pt-BR');
        const colors = {
            'info': '#0073aa',
            'success': '#28a745', 
            'error': '#dc3545',
            'warning': '#fd7e14'
        };
        
        const color = colors[type] || '#666';
        const newLine = `<div style="color: ${color}; margin: 2px 0;">[${timestamp}] ${message}</div>`;
        
        debugContainer.append(newLine);
        
        // Auto-scroll para baixo
        debugContainer.scrollTop(debugContainer[0].scrollHeight);
        
        // Limitar a 50 linhas para não sobrecarregar
        const lines = debugContainer.find('div');
        if (lines.length > 50) {
            lines.first().remove();
        }
    }
    
    // Exibir conteúdo do PIX dinâmico
    function displayDynamicPix(data) {
        const { brCode, qrCodeImage, identifier } = data;
        
        // Construir HTML do PIX dinâmico
        let pixHtml = '';
        
        // QR Code da OpenPix
        if (qrCodeImage) {
            pixHtml += `
                <div class="pix-qr" style="text-align:center; margin:10px 0;">
                    <img src="${qrCodeImage}" alt="QR Code PIX Dinâmico" width="240" height="240" style="border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);" />
                </div>
            `;
        }
        
        // Exibir identifier entre QR e código PIX
        if (identifier) {
            pixHtml += `
                <div class="pix-identifier">
                    <strong>Identificador</strong>
                    <code>${escapeHtml(identifier)}</code>
                </div>
            `;
        }
        
        // PIX Copia e Cola (sem scroll)
        if (brCode) {
            pixHtml += `
                <div class="pix-copy-paste" style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; margin: 10px 0;">
                    <p><strong>PIX Copia e Cola:</strong></p>
                    <div id="pix-dynamic-code" style="background: #fff; border: 1px solid #ccc; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px; word-break: break-all;">
                        ${escapeHtml(brCode)}
                    </div>
                    <button onclick="copyPixCode('${brCode.replace(/'/g, "\\\'").replace(/"/g, '\\"')}', ${data.order_id || $('#pix-payment-btn').data('order-id')})" style="margin-top: 10px; padding: 8px 15px; background: #007cba; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">
                        Copiar PIX
                    </button>
                </div>
            `;
        }
        
        // Inserir no modal e mostrar
        $('#pix-dynamic-content').html(pixHtml).show();
        $('#pix-static-content').hide();
        $('#pix-error').hide();
        $('#pix-content').show();
        
        // Debug final
        updateHttpDebug('🎨 Interface PIX dinâmico renderizada com sucesso!', 'success');
    }
    
    // Exibir PIX estático (conteúdo já gerado no PHP)
    function showStaticPixContent() {
        $('#pix-static-content').show();
        $('#pix-dynamic-content').hide();
        $('#pix-error').hide();
        $('#pix-loading').hide();
        $('#pix-content').show();
        
        // Debug para PIX estático
        updateHttpDebug('✅ Conteúdo PIX estático exibido com sucesso!', 'success');
    }
    
    // Exibir erro no modal
    function showPixError(message) {
        $('#pix-error-text').text(message);
        $('#pix-error').show();
        $('#pix-content').hide();
        
        // Debug de erro
        updateHttpDebug('🚨 Exibindo mensagem de erro para o usuário: ' + message, 'error');
    }
    
    // Função para escapar HTML para segurança
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Fechar modal PIX (atualizado para limpar conteúdo dinâmico)
    $('.pix-close').on('click', function() {
        $('#pix-modal').fadeOut(300);
        resetModalContent();
    });
    
    // Fechar modal ao clicar fora (atualizado)
    $(window).on('click', function(e) {
        if ($(e.target).hasClass('pix-modal')) {
            $('.pix-modal').fadeOut(300);
            resetModalContent();
        }
    });
    
    // Resetar conteúdo do modal
    function resetModalContent() {
        // Limpar conteúdo dinâmico para próxima abertura
        $('#pix-dynamic-content').html('');
        $('#pix-error').hide();
        $('#pix-loading').hide();
        $('#pix-content').show();
        
        // Resetar debug HTTP
        const debugContainer = $('#pix-http-debug');
        if (debugContainer.length > 0) {
            debugContainer.html('<span style="color: #666;">Aguardando ação do usuário...</span>');
        }
    }
    
    // Confirmar pagamento PIX (funciona para ambos os tipos)
    $('#pix-confirm-btn').on('click', function() {
        var $btn = $(this);
        var $mainBtn = $('#pix-payment-btn');
        var orderId = $mainBtn.data('order-id');
        var totalAmount = $mainBtn.data('total');
        var processingText = $mainBtn.data('processing-text') || 'Processando pagamento...';
        var originalConfirmText = $btn.text();
        
        // Desabilitar botão durante o processamento
        $btn.prop('disabled', true).text(processingText);
        
        // Fazer requisição AJAX
        $.ajax({
            url: pix_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'process_pix_payment',
                order_id: orderId,
                total_amount: totalAmount,
                nonce: pix_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Fechar modal de pagamento
                    $('#pix-modal').fadeOut(300);
                    
                    // Esconder botão principal completamente
                    $('#pix-payment-container').fadeOut(300);
                    
                    // Mostrar modal de sucesso
                    setTimeout(function() {
                        $('#pix-success-modal').fadeIn(300);
                    }, 400);
                } else {
                    alert('Erro ao processar pagamento. Tente novamente.');
                }
            },
            error: function() {
                alert('Erro de conexão. Tente novamente.');
            },
            complete: function() {
                $btn.prop('disabled', false).text(originalConfirmText);
            }
        });
    });
    
    // Fechar modal de sucesso
    $('#pix-success-close').on('click', function() {
        $('#pix-success-modal').fadeOut(300);
    });
    
    // ATUALIZADO: Escape key para fechar modais (sem modificação - mantém funcionalidade)
    $(document).on('keyup', function(e) {
        if (e.keyCode === 27) { // Escape key
            $('.pix-modal').fadeOut(300);
            resetModalContent();
        }
    });
    
    // Prevenção de duplo clique (mantido original)
    $('#pix-payment-btn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true);
        setTimeout(function() {
            if (!$btn.prop('disabled')) { // Se não foi desabilitado por outro motivo
                $btn.prop('disabled', false);
            }
        }, 2000); // Aumentado para 2 segundos para dar tempo do AJAX
    });
    
});

// ===== FUNÇÕES GLOBAIS (fora do document.ready) =====

// Copiar PIX e rastrear evento
function copyPixCode(pixCode, orderId) {
    if (navigator.clipboard && window.isSecureContext) {
        // Método moderno (HTTPS)
        navigator.clipboard.writeText(pixCode).then(function() {
            pixShowCenterAlert('Código PIX copiado!');
            trackPixCopyEvent(orderId);
        }).catch(function(err) {
            console.error('Erro ao copiar:', err);
            fallbackCopyPix(pixCode, orderId);
        });
    } else {
        // Fallback para browsers mais antigos ou HTTP
        fallbackCopyPix(pixCode, orderId);
    }
}

// Função global para copiar PIX dinâmico (mantida para compatibilidade)
function copyDynamicPix(pixCode) {
    const orderId = jQuery('#pix-payment-btn').data('order-id');
    copyPixCode(pixCode, orderId);
}

// Fallback para copiar texto
function fallbackCopyPix(pixCode, orderId) {
    const textArea = document.createElement('textarea');
    textArea.value = pixCode;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    textArea.style.top = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    try {
        const successful = document.execCommand('copy');
        if (successful) {
            pixShowCenterAlert('Código PIX copiado!');
            trackPixCopyEvent(orderId);
        } else {
            pixShowCenterAlert('Erro ao copiar. Selecione o código manualmente.');
        }
    } catch (err) {
        console.error('Erro ao copiar:', err);
        pixShowCenterAlert('Erro ao copiar. Selecione o código manualmente.');
    }
    
    document.body.removeChild(textArea);
}

// Rastrear evento de cópia do PIX
function trackPixCopyEvent(orderId) {
    if (typeof pix_ajax !== 'undefined' && pix_ajax.ajax_url) {
        jQuery.ajax({
            url: pix_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'pix_copy_pix_code',
                order_id: orderId,
                nonce: pix_ajax.copy_nonce || pix_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    console.log('PIX copy event tracked successfully');
                }
            },
            error: function() {
                console.log('Failed to track PIX copy event');
            }
        });
    }
}

// FUNÇÃO ATUALIZADA: Alert customizado integrado ao tema (v1.0.4)
function pixShowCenterAlert(message) {
    var overlay = document.getElementById('pix-alert-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'pix-alert-overlay';
        overlay.style.cssText = `
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.6);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 100000;
        `;
        overlay.innerHTML = `
            <div id="pix-alert-box" role="dialog" aria-modal="true" aria-live="assertive" style="
                background: white;
                padding: 35px 30px;
                border-radius: 12px;
                min-width: 320px;
                max-width: 90%;
                text-align: center;
                box-shadow: 0 15px 35px rgba(0,0,0,0.25);
                border-top: 4px solid #32BCAD;
                animation: pixAlertSlide 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55) forwards;
            ">
                <div id="pix-alert-title" style="
                    font-size: 18px; 
                    font-weight: 600; 
                    color: #32BCAD; 
                    margin-bottom: 15px;
                    letter-spacing: 0.5px;
                ">PIX Copiado!</div>
                <div id="pix-alert-msg" style="
                    margin-bottom: 25px; 
                    font-size: 15px; 
                    color: #555;
                    line-height: 1.4;
                "></div>
                <button id="pix-alert-ok" type="button" style="
                    padding: 12px 30px;
                    background: #32BCAD;
                    color: white;
                    border: none;
                    border-radius: 8px;
                    cursor: pointer;
                    font-weight: 600;
                    font-size: 14px;
                    transition: all 0.2s ease;
                    min-width: 100px;
                " onmouseover="this.style.backgroundColor='#28a99a'; this.style.transform='translateY(-1px)'" onmouseout="this.style.backgroundColor='#32BCAD'; this.style.transform='translateY(0)'">
                    OK
                </button>
            </div>
            <style>
                @keyframes pixAlertSlide {
                    0% { 
                        opacity: 0; 
                        transform: translateY(-30px) scale(0.9); 
                    }
                    100% { 
                        opacity: 1; 
                        transform: translateY(0) scale(1); 
                    }
                }
            </style>
        `;
        document.body.appendChild(overlay);
        
        // IMPORTANTE: Evento apenas no botão OK (não fecha com ESC ou clique fora)
        document.getElementById('pix-alert-ok').addEventListener('click', function() {
            overlay.style.animation = 'pixAlertFade 0.2s ease-out forwards';
            setTimeout(function() {
                if (overlay.parentNode) {
                    overlay.parentNode.removeChild(overlay);
                }
            }, 200);
        });
        
        // Adicionar CSS da animação de saída
        const fadeStyle = document.createElement('style');
        fadeStyle.textContent = `
            @keyframes pixAlertFade {
                from { opacity: 1; transform: scale(1); }
                to { opacity: 0; transform: scale(0.95); }
            }
        `;
        document.head.appendChild(fadeStyle);
    }
    
    // Definir mensagem
    document.getElementById('pix-alert-msg').textContent = message;
    overlay.style.display = 'flex';
    
    // REMOVIDO: setTimeout para auto-close (agora só fecha clicando OK)
    // Focus no botão OK para acessibilidade
    setTimeout(function() {
        const okButton = document.getElementById('pix-alert-ok');
        if (okButton) {
            okButton.focus();
        }
    }, 100);
}