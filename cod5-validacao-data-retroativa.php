<?php
/**
 * Plugin Name:       COD5 Validação de Data Retroativa
 * Description:       Impede que usuários não administradores publiquem ou atualizem posts, páginas ou CPTs com data/hora anterior ao momento atual. Inclui logs detalhados e filtros para extensibilidade.
 * Author:            Sua Empresa ou Nome
 * Version:           1.0.1
 * Text Domain:       cod5-plugin
 * Domain Path:       /languages
 * Requires at least: 5.0
 * Requires PHP:      7.2
 *
 * Estrutura da tabela criada:
 *   wp_cod5_log_datas_retroativas (
 *     id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *     user_id BIGINT UNSIGNED,
 *     user_login VARCHAR(60),
 *     post_id BIGINT UNSIGNED,
 *     post_type VARCHAR(20),
 *     tentativa_datahora DATETIME,
 *     post_datahora DATETIME,
 *     acao VARCHAR(20),
 *     INDEX (user_id),
 *     INDEX (post_id),
 *     INDEX (post_type)
 *   )
 *
 * Filtros disponíveis:
 *   - allow_past_date_for_non_admins: Permite desativar a regra de bloqueio de datas retroativas.
 *     Exemplo:
 *       add_filter('allow_past_date_for_non_admins', function($allow, $user_id, $user_login, $post_id, $post_type, $post_date) {
 *           if ($user_login === 'editor_especial') return true;
 *           return $allow;
 *       }, 10, 6);
 *   - custom_past_date_error_message: Personaliza a mensagem de erro.
 *     Exemplo:
 *       add_filter('custom_past_date_error_message', function($msg, $user_id, $user_login, $post_id, $post_type, $post_date) {
 *           return __('Você não pode publicar com data retroativa!', 'cod5-plugin');
 *       }, 10, 6);
 *
 * Logs:
 *   - Banco: Consulte a tabela wp_cod5_log_datas_retroativas para tentativas bloqueadas.
 *   - error_log: Verifique o log do servidor para entradas JSON detalhadas.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Segurança: impede acesso direto.
}

/**
 * Configurações centralizadas do plugin
 * 
 * Para personalizar o plugin em diferentes sites, altere apenas esta seção.
 */
class COD5_Config {
    /**
     * URL do webhook n8n
     * Altere para a URL do seu webhook em cada site
     */
    const WEBHOOK_URL = 'https://criadordigital-n8n-webhook.easypanel.codigo5.com.br/webhook/receber-tentativa';
    
    /**
     * Tempo de flexibilização em minutos
     * Permite posts até X minutos antes da hora atual
     */
    const FLEXIBILIDADE_MINUTOS = 60;
    
    /**
     * Grupos de usuários que têm restrição
     * Usuários com essas capabilities terão restrição de data retroativa
     */
    const CAPABILITIES_RESTRITAS = array(
        'edit_posts',      // Editores
        'publish_posts',   // Autores
        'edit_pages',      // Editores de páginas
        'publish_pages',   // Publicadores de páginas
    );
    
    /**
     * Grupos de usuários que são liberados
     * Usuários com essas capabilities podem publicar com qualquer data
     */
    const CAPABILITIES_LIBERADAS = array(
        'administrator',   // Administradores
        'manage_options',  // Gerentes de opções
    );
    
    /**
     * Status de posts que são validados
     * Apenas posts com esses status passam pela validação
     */
    const STATUS_VALIDADOS = array(
        'publish',
        'future', 
        'pending',
        'draft',
        'auto-draft'
    );
    
    /**
     * Timeout para requisições do webhook (em segundos)
     */
    const WEBHOOK_TIMEOUT = 5;
    
    /**
     * Habilita logs detalhados no error_log
     */
    const DEBUG_MODE = true;
    
    /**
     * Mensagem padrão de erro
     */
    const MENSAGEM_PADRAO = 'Se quiser publicar com data e horário anterior, só acordando mais cedo.';
}

/**
 * Funções utilitárias do plugin
 */
class COD5_Utils {
    /**
     * Verifica se o usuário tem restrição de data retroativa
     */
    public static function usuario_tem_restricao($user_id) {
        $user = get_user_by('ID', $user_id);
        if (!$user) return false;
        
        // Verifica se tem capabilities liberadas
        foreach (COD5_Config::CAPABILITIES_LIBERADAS as $capability) {
            if (user_can($user_id, $capability)) {
                return false; // Usuário é liberado
            }
        }
        
        // Verifica se tem capabilities restritas
        foreach (COD5_Config::CAPABILITIES_RESTRITAS as $capability) {
            if (user_can($user_id, $capability)) {
                return true; // Usuário tem restrição
            }
        }
        
        return false; // Usuário não tem restrição nem liberação
    }
    
    /**
     * Verifica se o status do post deve ser validado
     */
    public static function status_deve_ser_validado($post_status) {
        return in_array($post_status, COD5_Config::STATUS_VALIDADOS, true);
    }
    
    /**
     * Calcula se a data é retroativa considerando a flexibilidade
     */
    public static function data_eh_retroativa($post_date) {
        $post_date_obj = new DateTime($post_date);
        $now_date_obj = new DateTime(current_time('mysql'));
        
        // Remove os segundos para comparação
        $post_date_obj->setTime($post_date_obj->format('H'), $post_date_obj->format('i'), 0);
        $now_date_obj->setTime($now_date_obj->format('H'), $now_date_obj->format('i'), 0);
        
        // Calcula a diferença em minutos
        $diff_minutes = ($now_date_obj->getTimestamp() - $post_date_obj->getTimestamp()) / 60;
        
        // Retorna true se a diferença for maior que a flexibilidade permitida
        return $diff_minutes > COD5_Config::FLEXIBILIDADE_MINUTOS;
    }
    
    /**
     * Log de debug se o modo debug estiver ativo
     */
    public static function debug_log($message) {
        if (COD5_Config::DEBUG_MODE) {
            error_log('COD5 DEBUG: ' . $message);
        }
    }
}

/**
 * Cria a tabela de log ao ativar o plugin.
 */
function cod5_ativar_plugin() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'cod5_log_datas_retroativas';

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED,
        user_login VARCHAR(60),
        post_id BIGINT UNSIGNED,
        post_type VARCHAR(20),
        tentativa_datahora DATETIME,
        post_datahora DATETIME,
        acao VARCHAR(20),
        PRIMARY KEY (id),
        INDEX user_id (user_id),
        INDEX post_id (post_id),
        INDEX post_type (post_type)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );

    // Garante que a coluna 'acao' existe
    $columns = $wpdb->get_results( "SHOW COLUMNS FROM $table_name LIKE 'acao'" );
    if ( empty( $columns ) ) {
        $wpdb->query( "ALTER TABLE $table_name ADD COLUMN acao VARCHAR(20) AFTER post_datahora" );
    }
}
register_activation_hook( __FILE__, 'cod5_ativar_plugin' );

/**
 * Valida se o usuário pode publicar/atualizar com data retroativa.
 *
 * @param int    $user_id
 * @param string $user_login
 * @param int    $post_id
 * @param string $post_type
 * @param string $post_date (formato Y-m-d H:i:s)
 * @param string $post_status
 * @param string $acao Tipo de ação (cadastro ou atualização)
 * @return true|WP_Error
 */
function cod5_validar_data_retroativa( $user_id, $user_login, $post_id, $post_type, $post_date, $post_status, $acao = '' ) {
    // Sanitização
    $user_id    = absint( $user_id );
    $user_login = sanitize_user( $user_login );
    $post_id    = absint( $post_id );
    $post_type  = sanitize_key( $post_type );
    $post_status = sanitize_key( $post_status );
    $post_date  = sanitize_text_field( $post_date );
    $acao       = sanitize_text_field( $acao );

    COD5_Utils::debug_log("Validando: User ID {$user_id}, Post ID {$post_id}, Status {$post_status}, Data {$post_date}");

    // Se for uma atualização e a data não mudou, pula a validação
    if ( $acao === 'atualizacao' && $post_id > 0 ) {
        $original_post = get_post( $post_id );
        $original_date = $original_post ? $original_post->post_date : '';

        if ( $original_date ) {
            $original_dt = new DateTime( $original_date );
            $post_dt     = new DateTime( $post_date );

            // Ignora os segundos na comparação
            $original_dt->setTime( $original_dt->format('H'), $original_dt->format('i'), 0 );
            $post_dt->setTime( $post_dt->format('H'), $post_dt->format('i'), 0 );

            if ( $original_dt->format('Y-m-d H:i') === $post_dt->format('Y-m-d H:i') ) {
                COD5_Utils::debug_log('Data original e nova iguais. Pulando validação.');
                return true;
            }
        }
    }

    // Verifica se o status deve ser validado
    if ( ! COD5_Utils::status_deve_ser_validado( $post_status ) ) {
        COD5_Utils::debug_log("Status {$post_status} não requer validação");
        return true;
    }

    // Verifica se o usuário tem restrição
    if ( ! COD5_Utils::usuario_tem_restricao( $user_id ) ) {
        COD5_Utils::debug_log("Usuário {$user_id} não tem restrição");
        return true;
    }

    // Permitir via filtro
    $allow = apply_filters( 'allow_past_date_for_non_admins', false, $user_id, $user_login, $post_id, $post_type, $post_date );
    if ( $allow ) {
        COD5_Utils::debug_log("Usuário {$user_id} liberado via filtro");
        return true;
    }

    // Verifica se a data é retroativa
    if ( ! COD5_Utils::data_eh_retroativa( $post_date ) ) {
        COD5_Utils::debug_log("Data {$post_date} não é retroativa");
        return true;
    }

    COD5_Utils::debug_log("Data retroativa detectada: {$post_date}");

    // Mensagem de erro customizável
    $msg = sprintf(
        __( 'Você não tem permissão para publicar ou atualizar com data/hora retroativa (%s).', 'cod5-plugin' ),
        esc_html( $post_date )
    );
    $msg = apply_filters( 'custom_past_date_error_message', $msg, $user_id, $user_login, $post_id, $post_type, $post_date );

    // Log no banco
    cod5_log_data_retroativa( $user_id, $user_login, $post_id, $post_type, $post_date, $acao );

    // Retorno WP_Error
    return new WP_Error(
        'cod5_data_retroativa_bloqueada',
        $msg,
        array(
            'status'   => 400,
            'user_id'  => $user_id,
            'post_id'  => $post_id,
        )
    );
}

/**
 * Registra tentativa de publicação retroativa no banco.
 */
function cod5_log_data_retroativa( $user_id, $user_login, $post_id, $post_type, $post_date, $acao = '' ) {
    global $wpdb;
    $table = $wpdb->prefix . 'cod5_log_datas_retroativas';
    try {
        $wpdb->insert(
            $table,
            array(
                'user_id'            => absint( $user_id ),
                'user_login'         => sanitize_user( $user_login ),
                'post_id'            => absint( $post_id ),
                'post_type'          => sanitize_key( $post_type ),
                'tentativa_datahora' => current_time( 'mysql' ),
                'post_datahora'      => sanitize_text_field( $post_date ),
                'acao'               => sanitize_text_field( $acao ),
            ),
            array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
        );

        // Obtém informações do post
        $post_title = '';
        $post_id = absint($post_id);
        
        // Se for um post existente, pega os dados do banco
        if ($post_id > 0) {
            $post_obj = get_post($post_id);
            if ($post_obj) {
                $post_title = $post_obj->post_title;
            }
        } else {
            // Se for um novo post, tenta pegar do formulário
            if (isset($_POST['post_title'])) {
                $post_title = sanitize_text_field($_POST['post_title']);
            } elseif (isset($_POST['title'])) {
                $post_title = sanitize_text_field($_POST['title']);
            }
        }
        
        // URL do post no admin
        $post_url = $post_id > 0 ? admin_url("post.php?post={$post_id}&action=edit") : '';
        
        // Obtém o nome do site
        $site_name = get_bloginfo('name');

        // Prepara payload como query parameters
        $query_params = [
            'user_id'            => absint( $user_id ),
            'user_login'         => urlencode(sanitize_user( $user_login )),
            'post_id'            => $post_id,
            'post_title'         => urlencode($post_title),
            'post_url'           => urlencode($post_url),
            'site_name'          => urlencode($site_name),
            'post_type'          => sanitize_key( $post_type ),
            'tentativa_datahora' => urlencode(current_time( 'mysql' )),
            'post_datahora'      => urlencode(sanitize_text_field( $post_date )),
            'acao'               => urlencode(sanitize_text_field( $acao )),
            'mensagem'           => urlencode(sprintf(
                "🚨 Tentativa de publicação retroativa!\n\n" .
                "🌐 Site: %s\n" .
                "👤 Usuário: %s (ID: %d)\n" .
                "📝 Post: %s (ID: %d)\n" .
                "🔗 URL: %s\n" .
                "⏰ Horário atual: %s\n" .
                "📅 Horário tentado: %s\n" .
                "🔄 Ação: %s",
                $site_name,
                sanitize_user( $user_login ),
                absint( $user_id ),
                $post_title ?: '(Novo post)',
                $post_id,
                $post_url ?: '(URL não disponível)',
                current_time( 'mysql' ),
                sanitize_text_field( $post_date ),
                sanitize_text_field( $acao )
            ))
        ];

        // Adiciona os parâmetros à URL
        $webhook_url = add_query_arg($query_params, COD5_Config::WEBHOOK_URL);

        $args = [
            'timeout'          => COD5_Config::WEBHOOK_TIMEOUT,
            'connect_timeout'  => COD5_Config::WEBHOOK_TIMEOUT,
            'sslverify'        => true,
            'blocking'         => true,
        ];

        // Log de depuração antes do envio ao webhook
        COD5_Utils::debug_log('Enviando para webhook n8n... URL: ' . $webhook_url);
        
        $response = wp_remote_get( $webhook_url, $args );

        if ( is_wp_error( $response ) ) {
            COD5_Utils::debug_log('Webhook error: ' . $response->get_error_message());
            COD5_Utils::debug_log('Webhook error code: ' . $response->get_error_code());
            COD5_Utils::debug_log('Webhook error data: ' . print_r($response->get_error_data(), true));
        } else {
            $code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );
            COD5_Utils::debug_log("Webhook response code: {$code}");
            COD5_Utils::debug_log("Webhook response body: {$body}");
            
            if ( $code !== 200 ) {
                COD5_Utils::debug_log("Webhook unexpected status: {$code}");
                COD5_Utils::debug_log("Webhook response headers: " . print_r(wp_remote_retrieve_headers($response), true));
            }
        }
    } catch ( Exception $e ) {
        COD5_Utils::debug_log('Erro ao registrar log cod5: ' . $e->getMessage());
    }
}

/**
 * Hook para validação em wp_insert_post_data (admin, editor clássico, etc).
 * Este é o hook principal para validação ANTES de salvar.
 */
function cod5_wp_insert_post_data( $data, $postarr ) {
    $user = wp_get_current_user();
    $user_id = $user->ID;
    $user_login = $user->user_login;
    $post_id = isset( $postarr['ID'] ) ? absint( $postarr['ID'] ) : 0;
    $post_type = isset( $data['post_type'] ) ? $data['post_type'] : 'post';
    $post_date = isset( $data['post_date'] ) ? $data['post_date'] : current_time( 'mysql' );
    $post_status = isset( $data['post_status'] ) ? $data['post_status'] : 'draft';
    $acao = ( $post_id > 0 ) ? 'atualizacao' : 'cadastro';
    
    $resultado = cod5_validar_data_retroativa( $user_id, $user_login, $post_id, $post_type, $post_date, $post_status, $acao );
    if ( is_wp_error( $resultado ) ) {
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            // Em contexto REST, retorna o erro para bloquear o salvamento
            $resultado->add_data(array('status' => 400));
            return $resultado;
        } else {
            // Em contexto clássico, impede o salvamento e exibe a mensagem
            wp_die(
                $resultado->get_error_message(),
                __( 'Data retroativa bloqueada', 'cod5-plugin' ),
                array( 'back_link' => true )
            );
        }
    }
    return $data;
}
add_filter( 'wp_insert_post_data', 'cod5_wp_insert_post_data', 10, 2 );

/**
 * Hook para validação antes de inserir post via REST API.
 * Este é o hook principal para validação REST ANTES de salvar.
 */
function cod5_rest_pre_insert_post( $prepared_post, $request ) {
    $user = wp_get_current_user();
    $user_id = $user->ID;
    $user_login = $user->user_login;
    $post_id = isset( $prepared_post->ID ) ? absint( $prepared_post->ID ) : 0;
    $post_type = isset( $prepared_post->post_type ) ? $prepared_post->post_type : 'post';
    $post_date = isset( $prepared_post->post_date ) ? $prepared_post->post_date : current_time( 'mysql' );
    $post_status = isset( $prepared_post->post_status ) ? $prepared_post->post_status : 'draft';
    $acao = ( $post_id > 0 ) ? 'atualizacao' : 'cadastro';

    $resultado = cod5_validar_data_retroativa( $user_id, $user_login, $post_id, $post_type, $post_date, $post_status, $acao );
    if ( is_wp_error( $resultado ) ) {
        $resultado->add_data(array('status' => 400));
        return $resultado;
    }
    return $prepared_post;
}
add_filter( 'rest_pre_insert_post', 'cod5_rest_pre_insert_post', 10, 2 );

/**
 * Hook para interceptar erros REST e garantir mensagem personalizada
 */
function cod5_rest_pre_serve_request( $served, $result, $request, $server ) {
    if ( is_wp_error( $result ) && $result->get_error_code() === 'cod5_data_retroativa_bloqueada' ) {
        // Garante que nossa mensagem personalizada seja usada
        $error_data = $result->get_error_data();
        $status = isset( $error_data['status'] ) ? $error_data['status'] : 400;
        
        // Define headers corretos
        status_header( $status );
        nocache_headers();
        
        // Retorna JSON com nossa mensagem
        echo wp_json_encode( array(
            'code' => 'cod5_data_retroativa_bloqueada',
            'message' => $result->get_error_message(),
            'data' => array(
                'status' => $status
            )
        ) );
        
        return true; // Indica que já servimos a resposta
    }
    
    return $served;
}
add_filter( 'rest_pre_serve_request', 'cod5_rest_pre_serve_request', 10, 4 );

/**
 * Hook para interceptar erros de salvamento no Gutenberg
 */
function cod5_rest_post_dispatch( $response, $handler, $request ) {
    // Se a resposta já é um erro, verifica se é nosso erro
    if ( is_wp_error( $response ) && $response->get_error_code() === 'cod5_data_retroativa_bloqueada' ) {
        COD5_Utils::debug_log('Erro de data retroativa interceptado no REST dispatch');
        
        // Garante que a mensagem personalizada seja mantida
        $error_data = $response->get_error_data();
        if ( ! isset( $error_data['status'] ) ) {
            $response->add_data( array( 'status' => 400 ) );
        }
        
        return $response;
    }
    
    return $response;
}
add_filter( 'rest_post_dispatch', 'cod5_rest_post_dispatch', 10, 3 );

/**
 * Hook para adicionar dados extras ao erro REST
 */
function cod5_rest_prepare_error( $error, $request ) {
    if ( $error->get_error_code() === 'cod5_data_retroativa_bloqueada' ) {
        COD5_Utils::debug_log('Preparando erro de data retroativa para REST');
        
        // Adiciona dados extras para melhor tratamento no frontend
        $error->add_data( array(
            'status' => 400,
            'cod5_error' => true,
            'custom_message' => $error->get_error_message()
        ) );
    }
    
    return $error;
}
add_filter( 'rest_prepare_error', 'cod5_rest_prepare_error', 10, 2 );

/**
 * Hook para interceptar erros antes que o WordPress os processe
 */
function cod5_rest_pre_dispatch_error( $result, $server, $request ) {
    // Se já há um erro, verifica se é nosso erro
    if ( is_wp_error( $result ) && $result->get_error_code() === 'cod5_data_retroativa_bloqueada' ) {
        COD5_Utils::debug_log('Interceptando erro de data retroativa antes do dispatch');
        
        // Garante que nossa mensagem seja preservada
        $error_data = $result->get_error_data();
        if ( ! isset( $error_data['status'] ) ) {
            $result->add_data( array( 'status' => 400 ) );
        }
        
        // Adiciona flag para identificar nosso erro
        $result->add_data( array( 'cod5_error' => true ) );
        
        return $result;
    }
    
    return $result;
}
add_filter( 'rest_pre_dispatch', 'cod5_rest_pre_dispatch_error', 5, 3 );

/**
 * Hook para customizar resposta de erro REST com prioridade alta
 */
function cod5_rest_pre_serve_request_high_priority( $served, $result, $request, $server ) {
    if ( is_wp_error( $result ) && $result->get_error_code() === 'cod5_data_retroativa_bloqueada' ) {
        COD5_Utils::debug_log('Servindo resposta de erro personalizada com alta prioridade');
        
        // Garante que nossa mensagem personalizada seja usada
        $error_data = $result->get_error_data();
        $status = isset( $error_data['status'] ) ? $error_data['status'] : 400;
        
        // Define headers corretos
        status_header( $status );
        nocache_headers();
        
        // Retorna JSON com nossa mensagem
        echo wp_json_encode( array(
            'code' => 'cod5_data_retroativa_bloqueada',
            'message' => $result->get_error_message(),
            'data' => array(
                'status' => $status,
                'cod5_error' => true
            )
        ) );
        
        return true; // Indica que já servimos a resposta
    }
    
    return $served;
}
add_filter( 'rest_pre_serve_request', 'cod5_rest_pre_serve_request_high_priority', 1, 4 );

/**
 * Hook para interceptar erros de salvamento antes que o WordPress os processe
 */
function cod5_wp_insert_post_data_error_handling( $data, $postarr ) {
    // Se $data é um WP_Error, é nosso erro de validação
    if ( is_wp_error( $data ) && $data->get_error_code() === 'cod5_data_retroativa_bloqueada' ) {
        COD5_Utils::debug_log('Interceptando erro de data retroativa no wp_insert_post_data');
        
        // Garante que nossa mensagem seja preservada
        $error_data = $data->get_error_data();
        if ( ! isset( $error_data['status'] ) ) {
            $data->add_data( array( 'status' => 400 ) );
        }
        
        // Adiciona flag para identificar nosso erro
        $data->add_data( array( 'cod5_error' => true ) );
        
        return $data;
    }
    
    return $data;
}
add_filter( 'wp_insert_post_data', 'cod5_wp_insert_post_data_error_handling', 5, 2 );

/**
 * Hook para customizar mensagens de erro do WordPress
 */
function cod5_custom_error_messages( $message, $error ) {
    if ( is_wp_error( $error ) && $error->get_error_code() === 'cod5_data_retroativa_bloqueada' ) {
        COD5_Utils::debug_log('Customizando mensagem de erro do WordPress');
        return $error->get_error_message();
    }
    
    return $message;
}

/**
 * Hook de fallback para validação após salvar (apenas para casos especiais).
 * Este hook só é usado quando os hooks principais falham.
 */
function cod5_admin_validacao_data_retroativa( $post_id, $post, $update ) {
    // Evita loop infinito e checa se é revisão/autosave
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( wp_is_post_autosave( $post_id ) ) return;
    
    // Só executa se não for uma requisição REST (já validada anteriormente)
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;

    $user = wp_get_current_user();
    $user_id = $user->ID;
    $user_login = $user->user_login;
    $post_type = $post->post_type;
    $post_date = $post->post_date;
    $post_status = $post->post_status;
    $acao = $update ? 'atualizacao' : 'cadastro';

    $resultado = cod5_validar_data_retroativa( $user_id, $user_login, $post_id, $post_type, $post_date, $post_status, $acao );
    if ( is_wp_error( $resultado ) ) {
        // Se chegou aqui, o post foi salvo mas deveria ter sido bloqueado
        // Log de erro para debug
        COD5_Utils::debug_log('Post salvo mas deveria ter sido bloqueado. Post ID: ' . $post_id);
        
        // Não pode mais bloquear, mas pode registrar o erro
        wp_die(
            $resultado->get_error_message(),
            __( 'Data retroativa bloqueada', 'cod5-plugin' ),
            array( 'back_link' => true )
        );
    }
}
add_action( 'save_post', 'cod5_admin_validacao_data_retroativa', 10, 3 );

/**
 * Hook dinâmico para todos os post types públicos e privados com show_ui => true na REST API.
 */
function cod5_registrar_hooks_rest_post_types() {
    $post_types = get_post_types( array( 'show_ui' => true ), 'names' );
    foreach ( $post_types as $post_type ) {
        add_filter( "rest_pre_insert_{$post_type}", 'cod5_rest_pre_insert_post', 10, 2 );
    }
}
add_action( 'init', 'cod5_registrar_hooks_rest_post_types' );

/**
 * Carrega o script admin.js apenas no editor do Gutenberg.
 */
function cod5_enqueue_admin_script( $hook ) {
    // Apenas no editor de post
    if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
        return;
    }
    wp_enqueue_script(
        'cod5-admin-js',
        plugin_dir_url( __FILE__ ) . 'admin.js',
        array( 'wp-data', 'wp-edit-post', 'wp-element', 'wp-plugins' ),
        '1.0.0',
        true
    );
}
add_action( 'admin_enqueue_scripts', 'cod5_enqueue_admin_script' );

/**
 * Passa dados do PHP para o JS do admin (se é admin e mensagem de erro personalizada).
 */
function cod5_localize_admin_script() {
    if ( ! function_exists( 'get_current_screen' ) ) {
        require_once ABSPATH . 'wp-admin/includes/screen.php';
    }
    $screen = get_current_screen();
    if ( ! $screen || ( $screen->base !== 'post' && $screen->base !== 'post-new' ) ) {
        return;
    }
    $user = wp_get_current_user();
    $is_admin = !COD5_Utils::usuario_tem_restricao($user->ID);
    
    // Usa a mesma mensagem personalizada do backend
    $msg = apply_filters( 'custom_past_date_error_message', COD5_Config::MENSAGEM_PADRAO, $user->ID, $user->user_login, 0, '', '' );
    
    wp_localize_script( 'cod5-admin-js', 'cod5PluginData', array(
        'isAdmin' => $is_admin,
        'errorMessage' => $msg,
        'currentTime' => current_time('mysql'),
        'flexibilidadeMinutos' => COD5_Config::FLEXIBILIDADE_MINUTOS,
        'debugMode' => COD5_Config::DEBUG_MODE,
    ) );
}
add_action( 'admin_enqueue_scripts', 'cod5_localize_admin_script', 20 );

/**
 * Mensagem padrão customizada para data retroativa.
 */
add_filter( 'custom_past_date_error_message', function( $msg, $user_id, $user_login, $post_id, $post_type, $post_date ) {
    return COD5_Config::MENSAGEM_PADRAO;
}, 10, 6 );
