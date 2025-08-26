# pix-off-line-plugin-wp
# 🏦 PIX Offline - Plugin WordPress/WooCommerce

**Versão Atual: 30**  
**Compatibilidade:** WordPress 5.0+ | WooCommerce 5.0+ | PHP 7.4+  
**Autor:** TMS  
**Licença:** Proprietária  

## 📋 Índice

- [Visão Geral](#-visão-geral)
- [Funcionalidades](#-funcionalidades)
- [Arquivos do Plugin](#-arquivos-do-plugin)
- [Instalação](#-instalação)
- [Configuração](#-configuração)
- [Uso](#-uso)
- [Status de Transações](#-status-de-transações)
- [Integração OpenPix](#-integração-openpix)
- [Sistema de Cache](#-sistema-de-cache)
- [Debug e Logs](#-debug-e-logs)
- [Changelog](#-changelog)

---

## 🎯 Visão Geral

PIX Offline é um plugin completo para WordPress/WooCommerce que permite processar pagamentos PIX de forma offline. Suporta tanto **PIX Estático** quanto **PIX Dinâmico** (via OpenPix API), oferecendo flexibilidade total para diferentes necessidades de negócio.

### 🏷️ Principais Características

- **Dual Mode:** PIX Estático + PIX Dinâmico (OpenPix)
- **Integração CartFlows:** Suporte completo para funis de venda
- **Sistema de Cache:** Evita requisições desnecessárias à API
- **Interface Admin:** Painel completo para gerenciar transações
- **Debug Avançado:** Sistema de logs em tempo real
- **Ações em Massa:** Gerenciamento eficiente de múltiplas transações

---

## ⚡ Funcionalidades

### 💳 Métodos PIX Suportados

#### PIX Estático
- ✅ Chave PIX manual (CPF, CNPJ, email, telefone, aleatória)
- ✅ PIX Copia e Cola gerado automaticamente (padrão BACEN)
- ✅ QR Code via Google Charts API
- ✅ Identificador customizado por pedido

#### PIX Dinâmico (OpenPix)
- ✅ Integração completa com OpenPix API
- ✅ QR Code dinâmico gerado pela OpenPix
- ✅ PIX Copia e Cola oficial da OpenPix
- ✅ Sistema de cache com expiração automática
- ✅ Identificador único da OpenPix
- ✅ Rastreamento de status em tempo real

### 🔧 Interface e Experiência

- ✅ Modal responsivo com animações
- ✅ Textos completamente personalizáveis
- ✅ Cores customizáveis dos botões
- ✅ Cópia de PIX com um clique
- ✅ Mensagens de feedback personalizadas
- ✅ Debug detalhado opcional

### 📊 Gerenciamento Admin

- ✅ Tabela de transações com filtros
- ✅ Ações em massa (finalizar, estornar, recusar, excluir)
- ✅ Status detalhado de cada transação
- ✅ Links diretos para ordens WooCommerce
- ✅ Informações do cliente integradas
- ✅ Coluna identificador para PIX dinâmico

### 🔄 Fluxo de Status

```
checkout_iniciado → pix_gerado → pix_copiado → pendente → finalizado
                                      ↓
                              estornado_admin / recusado_admin
```

---

## 📁 Arquivos do Plugin

### Estrutura Principal

```
pix-offline/
├── pix-offline.php (v30)           # Arquivo principal
├── pix-offline-admin.php (v20)     # Interface administrativa  
├── pix-offline-transactions.php (v14) # Gerenciador de transações
├── pix-offline.js (v1.0.3)         # JavaScript frontend
├── pix-offline.css                 # Estilos (opcional)
└── README.md                       # Documentação
```

### 📝 Detalhamento dos Arquivos

#### `pix-offline.php` (v30)
**Arquivo principal do plugin**
- Core do sistema PIX
- Shortcode `[pix]` para exibição do botão
- Integração OpenPix API
- Sistema de cache PIX dinâmico
- Geração PIX Copia e Cola (padrão BACEN)
- Hooks AJAX para todas as funcionalidades

#### `pix-offline-admin.php` (v20)  
**Painel administrativo**
- Interface de configurações (Setup)
- Tabela de transações com nova coluna "Identificador"
- Ações em massa otimizadas
- Suporte ao status "pix_copiado"
- Campos condicionais para PIX dinâmico

#### `pix-offline-transactions.php` (v14)
**Gerenciador de dados**
- CRUD de transações PIX
- Atualização de status WooCommerce
- Sistema de cache e verificação de expiração
- Integração com CartFlows (ordens pai/filha)
- Preparação para webhooks OpenPix

#### `pix-offline.js` (v1.0.3)
**Interface frontend**
- Modal interativo responsivo
- Cache PIX dinâmico no cliente
- Rastreamento de eventos (cópia PIX)
- Debug HTTP em tempo real
- Tratamento de erros avançado

---

## 🚀 Instalação

### Pré-requisitos
- WordPress 5.0 ou superior
- WooCommerce 5.0 ou superior  
- PHP 7.4 ou superior
- Plugin CartFlows (se usar funis de venda)

### Passos de Instalação

1. **Upload dos Arquivos**
   ```bash
   wp-content/plugins/pix-offline/
   ```

2. **Ativação**
   ```
   WordPress Admin → Plugins → Ativar "PIX Offline"
   ```

3. **Configuração Inicial**
   ```
   WooCommerce → PIX Offline → Setup
   ```

---

## ⚙️ Configuração

### 🎨 Configurações de Aparência
```
Cor dos Botões: #32BCAD (padrão)
Cor dos Botões (Hover): #28a99a (padrão)
```

### 📝 Textos Personalizados
```
Botão Principal: "Pagar com PIX"
Botão Confirmação: "Já efetuei o pagamento"
Título do Popup: "Pagar com PIX"
Instrução: "Abra o app do seu banco."
Título Sucesso: "Obrigado!"
Mensagem Sucesso: "Seu pedido está sendo processado..."
```

### 🏦 PIX Estático
```
Chave PIX: Sua chave (CPF, CNPJ, email, telefone, aleatória)
☑️ PIX Copia e Cola: Gerar código padrão BACEN
```

### 🌐 PIX Dinâmico (OpenPIX)
```
☑️ Habilitar PIX Dinâmico
URL da API: https://api.openpix.com.br/api/v1/charge
AppID: Seu token de autorização
Mensagem de Erro: "Erro ao gerar PIX. Tente novamente."
```

### 🔍 Debug
```
☑️ Exibir Debug: Mostrar informações técnicas no popup
```

---

## 🎮 Uso

### Para o Cliente

1. **Finalizar Pedido**
   - Escolher método "Direct bank transfer"
   - Completar checkout normalmente

2. **Página Thank You**
   - Botão "Pagar com PIX agora" aparece automaticamente
   - Clique abre modal com QR Code e PIX Copia e Cola

3. **Realizar Pagamento**
   - Escanear QR Code OU copiar código PIX
   - Fazer pagamento no app do banco
   - Clicar "Já efetuei o pagamento"

### Para o Administrador

#### Gerenciar Transações
```
WooCommerce → PIX Offline → Transações
```

#### Ações Disponíveis
- **Finalizar:** Confirmar pagamento recebido
- **Estornar:** Processar reembolso 
- **Recusar:** Rejeitar pagamento
- **Excluir:** Remover transação
- **Ações em Massa:** Processar múltiplas transações

#### Filtros e Informações
- Status de cada transação
- Valor total (ordem pai + filhas)
- Dados do cliente
- Identificador OpenPix (se aplicável)
- Histórico de datas

---

## 📊 Status de Transações

### 🔄 Fluxo Completo

| Status | Descrição | Ação Cliente | Ação Admin |
|--------|-----------|--------------|------------|
| `checkout_iniciado` | Pedido criado | Aguardar | Aguardar |
| `pix_gerado` | PIX disponível | Pagar | Aguardar |
| `pix_copiado` | Cliente copiou código | Efetuar pagamento | Aguardar confirmação |
| `pendente` | Cliente confirmou | Aguardar aprovação | **Finalizar** ou **Recusar** |
| `finalizado` | ✅ Aprovado | Concluído | Opcional: **Estornar** |
| `estornado_admin` | ↩️ Estornado | Reembolsado | Opcional: **Reativar** |
| `recusado_admin` | ❌ Recusado | Cancelado | Final |

### 🎨 Indicadores Visuais
- **Cinza:** Checkout iniciado
- **Amarelo:** PIX gerado  
- **Azul:** PIX copiado
- **Azul Escuro:** Pendente
- **Verde:** Finalizado
- **Vermelho:** Estornado/Recusado

---

## 🌐 Integração OpenPix

### 🔧 Configuração API

#### Ambientes Disponíveis
```bash
# Produção
https://api.openpix.com.br/api/v1/charge

# Sandbox (Testes)  
https://api.woovi-sandbox.com/api/v1/charge
```

#### Autenticação
```bash
Headers:
  Authorization: SEU_APPID_AQUI
  Content-Type: application/json
  Accept: application/json
```

#### Payload Enviado
```json
{
  "correlationID": "419",
  "value": 2500
}
```

#### Resposta Esperada
```json
{
  "charge": {
    "correlationID": "419",
    "identifier": "2f238ac320d7481e9d8ee170f32567cb",
    "value": 2500,
    "brCode": "00020101021226...",
    "qrCodeImage": "https://api.openpix.com.br/...",
    "expiresIn": 3600
  }
}
```

### ⚡ Benefícios PIX Dinâmico
- QR Code oficial da OpenPix
- Identificador único rastreável  
- Expiração automática
- Integração com webhook (futuro)
- Cache inteligente para performance

---

## 💾 Sistema de Cache

### 🎯 Como Funciona

O cache PIX dinâmico evita requisições desnecessárias à API OpenPix:

```php
// Dados armazenados no cache
$cache_data = array(
    'brCode' => '00020101021226...',
    'qrCodeImage' => 'https://api.openpix.com.br/...',
    'identifier' => '2f238ac320d7481e9d8ee170f32567cb',
    'expiresIn' => 3600,
    'created_at' => timestamp
);
```

### ⏰ Validação de Expiração
```php
// Verificar se cache é válido
$is_valid = (current_time - created_at) < expiresIn;
```

### 🔄 Comportamento
- **Cache válido:** Exibe dados salvos instantaneamente
- **Cache expirado:** Faz nova requisição à API
- **Cache inexistente:** Primeira requisição à API

---

## 🐛 Debug e Logs

### 🖥️ Debug Frontend (Modal)

Quando habilitado no admin, mostra informações em tempo real:

```
DEBUG - Status Requisição HTTP:
[16:24:32] 🚀 PIX DINÂMICO detectado! Iniciando processo...
[16:24:32] 🔄 INICIANDO requisição PIX dinâmico...
[16:24:32] ✅ Nonce dinâmico verificado: 71b56a***
[16:24:32] 📤 Payload preparado: {"correlationID":"468","value":1000}
[16:24:32] ⏳ Enviando para OpenPix API...
[16:24:32] 🌐 Conectando com servidor WordPress...
[16:24:34] ✅ Resposta WordPress recebida: {"success":true,"hasData":true}
[16:24:34] 🎉 PIX dinâmico gerado com SUCESSO!
```

### 📋 Debug Backend (Error Log)

Logs detalhados no `wp-content/debug.log`:

```
PIX Debug: Iniciando chamada OpenPix API
PIX Debug: URL = https://api.openpix.com.br/api/v1/charge
PIX Debug: AppID = Q2xpZ***W89
PIX Debug: Payload = {"correlationID":"468","value":1000}  
PIX Debug: HTTP Status = 200
PIX Debug: Response Body = {"charge":{"brCode":"..."}}
```

### 🔍 Tipos de Logs
- **Info:** 🔵 Processos normais
- **Success:** 🟢 Operações bem-sucedidas  
- **Warning:** 🟠 Avisos importantes
- **Error:** 🔴 Falhas e problemas

---

## 📈 Changelog

### v30 (Versão Atual)
**Arquivo Principal Atualizado**
- ✅ Implementado cache PIX dinâmico completo
- ✅ Sistema de rastreamento de cópia PIX
- ✅ Verificação de validade do cache antes de requisições
- ✅ Armazenamento do identifier OpenPix
- ✅ Nova ação AJAX `pix_copy_pix_code`
- ✅ Melhorias na interface do modal (sem scroll)

### v20 (Admin)
**Painel Administrativo Aprimorado**
- ✅ Nova coluna "Identificador" na tabela
- ✅ Suporte ao status "pix_copiado"  
- ✅ Exibição do identifier OpenPix
- ✅ Melhorias na interface da tabela
- ✅ Labels de status atualizados

### v14 (Transações)
**Gerenciador de Dados Otimizado**
- ✅ Suporte ao status "pix_copiado"
- ✅ Campo identifier na estrutura de dados
- ✅ Query atualizada com identifier
- ✅ Mensagens específicas por tipo de status
- ✅ Sistema de cache integrado

### v1.0.3 (JavaScript)
**Frontend Interativo**
- ✅ Cache PIX dinâmico no cliente
- ✅ Evento de rastreamento de cópia
- ✅ Código PIX sem scroll (altura automática)
- ✅ Textos atualizados ("PIX Copia e Cola", "Copiar PIX")
- ✅ Remoção do botão "Abrir Link de Pagamento"
- ✅ Display do identifier entre QR e código

### Versões Anteriores
- **v29:** Debug HTTP implementado
- **v28:** PIX dinâmico básico com OpenPix
- **v19:** Interface admin com PIX dinâmico
- **v13:** Sistema de transações robusto
- **v12:** Integração CartFlows completa

---

## 🔒 Segurança

### 🛡️ Medidas Implementadas
- **Nonces WordPress:** Validação em todas requisições AJAX
- **Sanitização:** Todos inputs sanitizados antes do processamento  
- **AppID Mascarado:** Token nunca exibido completo nos logs
- **HTTPS Obrigatório:** Requisições OpenPix sempre criptografadas
- **Timeouts:** Limite de 15-20 segundos nas requisições
- **Validação de Orders:** Verificação se ordem existe e pertence ao usuário

### 🔐 Recomendações
- Manter AppID OpenPix confidencial
- Usar HTTPS em produção
- Monitorar logs de erro regularmente
- Backup regular das configurações
- Testar em ambiente staging primeiro

---

## 🆘 Suporte e Troubleshooting

### ❓ Problemas Comuns

#### PIX Dinâmico não funciona
```
1. Verificar se AppID está correto
2. Confirmar URL da API (produção vs sandbox)  
3. Checar logs de erro no WordPress
4. Testar conectividade com OpenPix
```

#### Modal não abre
```
1. Verificar se jQuery está carregado
2. Confirmar se arquivos JS/CSS foram incluídos
3. Verificar conflitos com outros plugins
4. Testar em tema padrão WordPress
```

#### Transações não aparecem no admin
```
1. Verificar se método de pagamento é "bacs"
2. Confirmar se hooks estão funcionando
3. Checar tabela wp_wc_orders_meta
4. Verificar permissões de usuário
```

### 📞 Contato para Suporte
- **Desenvolvedor:** TMS
- **Plugin:** PIX Offline


**🎉 PIX Offline - Solução completa para pagamentos PIX offline no WooCommerce!**
