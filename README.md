# COD5 Validação de Data Retroativa

Plugin WordPress que impede que usuários não administradores publiquem ou atualizem posts, páginas ou CPTs com data/hora anterior ao momento atual.

## 📋 Configuração

### 🎯 Configurações Centralizadas

Todas as configurações estão centralizadas na classe `COD5_Config` no arquivo principal do plugin. Para personalizar o plugin em diferentes sites, altere apenas esta seção:

```php
class COD5_Config {
    // URL do webhook n8n
    const WEBHOOK_URL = 'https://seu-webhook.com/webhook/receber-tentativa';
    
    // Tempo de flexibilização em minutos (padrão: 60)
    const FLEXIBILIDADE_MINUTOS = 60;
    
    // Grupos de usuários que têm restrição
    const CAPABILITIES_RESTRITAS = array(
        'edit_posts',      // Editores
        'publish_posts',   // Autores
        'edit_pages',      // Editores de páginas
        'publish_pages',   // Publicadores de páginas
    );
    
    // Grupos de usuários que são liberados
    const CAPABILITIES_LIBERADAS = array(
        'administrator',   // Administradores
        'manage_options',  // Gerentes de opções
    );
    
    // Status de posts que são validados
    const STATUS_VALIDADOS = array(
        'publish',
        'future', 
        'pending'
    );
    
    // Timeout para requisições do webhook (em segundos)
    const WEBHOOK_TIMEOUT = 5;
    
    // Habilita logs detalhados no error_log
    const DEBUG_MODE = true;
    
    // Mensagem padrão de erro
    const MENSAGEM_PADRAO = 'Se quiser publicar com data e horário anterior, só acordando mais cedo.';
}
```

### 🔧 Personalizações por Site

#### 1. URL do Webhook
```php
const WEBHOOK_URL = 'https://seu-site.com/webhook/receber-tentativa';
```

#### 2. Tempo de Flexibilização
```php
// Permite posts até 30 minutos antes
const FLEXIBILIDADE_MINUTOS = 30;

// Permite posts até 2 horas antes
const FLEXIBILIDADE_MINUTOS = 120;
```

#### 3. Grupos de Usuários
```php
// Adicionar novos grupos restritos
const CAPABILITIES_RESTRITAS = array(
    'edit_posts',
    'publish_posts',
    'edit_pages',
    'publish_pages',
    'custom_capability',  // Nova capability
);

// Adicionar novos grupos liberados
const CAPABILITIES_LIBERADAS = array(
    'administrator',
    'manage_options',
    'super_editor',       // Nova capability
);
```

#### 4. Status de Posts
```php
// Adicionar novos status para validação
const STATUS_VALIDADOS = array(
    'publish',
    'future', 
    'pending',
    'custom_status',      // Novo status
);
```

#### 5. Mensagem de Erro
```php
const MENSAGEM_PADRAO = 'Sua mensagem personalizada aqui.';
```

## 🚀 Instalação

1. Faça upload do plugin para `/wp-content/plugins/`
2. Ative o plugin no painel administrativo
3. Configure as opções na classe `COD5_Config`
4. Teste com diferentes tipos de usuários

## 📊 Funcionalidades

### ✅ Validação de Data
- Bloqueia posts com data retroativa (configurável)
- Permite flexibilidade de tempo (configurável)
- Valida apenas status específicos (configurável)

### 👥 Controle de Usuários
- Grupos restritos configuráveis
- Grupos liberados configuráveis
- Suporte a capabilities customizadas

### 📝 Logs e Monitoramento
- Log no banco de dados
- Webhook para notificações
- Logs de debug (configurável)

### 🔌 Extensibilidade
- Filtros WordPress para customização
- Hooks para integração
- Classes organizadas para manutenção

## 🛠️ Manutenção

### Estrutura de Arquivos
```
cod5-validacao-data-retroativa/
├── cod5-validacao-data-retroativa.php  # Arquivo principal
├── admin.js                            # JavaScript do editor
└── README.md                           # Esta documentação
```

### Classes Principais

#### `COD5_Config`
- Centraliza todas as configurações
- Constantes para fácil alteração
- Documentação inline

#### `COD5_Utils`
- Funções utilitárias
- Lógica de validação
- Logs de debug

### Hooks WordPress
- `wp_insert_post_data` - Validação principal
- `rest_pre_insert_post` - Validação REST API
- `save_post` - Validação de fallback
- `admin_enqueue_scripts` - Carregamento de assets

## 🧪 Testes

### Cenários de Teste
1. **Autor criando com data atual** → ✅ Deve funcionar
2. **Autor criando com data 30 min atrás** → ✅ Deve funcionar (se flexibilidade ≥ 30)
3. **Autor criando com data 2 horas atrás** → ❌ Deve bloquear
4. **Administrador criando com qualquer data** → ✅ Deve funcionar
5. **Editor clássico vs Gutenberg** → ✅ Ambos devem funcionar

### Logs de Debug
Ative o modo debug para ver logs detalhados:
```php
const DEBUG_MODE = true;
```

Os logs aparecem no `error_log` do servidor com prefixo `COD5 DEBUG:`.

## 🔒 Segurança

- Sanitização de dados
- Verificação de capabilities
- Validação de status
- Prevenção de loops infinitos

## 📞 Suporte

Para dúvidas ou problemas:
1. Verifique os logs de debug
2. Teste com diferentes usuários
3. Confirme as configurações na classe `COD5_Config`
4. Verifique se o webhook está funcionando

## 📝 Changelog

### v1.0.0
- ✅ Validação de data retroativa
- ✅ Controle de usuários configurável
- ✅ Logs e webhook
- ✅ Suporte a editor clássico e Gutenberg
- ✅ Configurações centralizadas
- ✅ Documentação completa 