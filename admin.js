(function(wp) {
    const { subscribe, select } = wp.data;
    const { createNotice } = wp.data.dispatch('core/notices');

    let lastPostDate = null;
    let lastStatus = null;
    let hasNotification = false;

    /**
     * Log de debug se o modo debug estiver ativo
     */
    function debugLog(message) {
        if (window.cod5PluginData && window.cod5PluginData.debugMode) {
            console.log('COD5 DEBUG:', message);
        }
    }

    /**
     * Verifica se o usuário tem restrição (baseado nas configurações do PHP)
     */
    function isAdmin() {
        return !!(window.cod5PluginData && window.cod5PluginData.isAdmin);
    }

    /**
     * Obtém a mensagem de erro personalizada
     */
    function getErrorMessage(postDate) {
        if (window.cod5PluginData && window.cod5PluginData.errorMessage) {
            return window.cod5PluginData.errorMessage;
        }
        return 'Você não tem permissão para publicar ou atualizar com data/hora retroativa.';
    }

    /**
     * Verifica se a data é retroativa considerando a flexibilidade
     */
    function dataEhRetroativa(postDate) {
        if (!window.cod5PluginData || !window.cod5PluginData.currentTime) {
            debugLog('Dados do plugin não disponíveis');
            return false;
        }

        const postDateObj = new Date(postDate);
        const currentDateObj = new Date(window.cod5PluginData.currentTime);
        
        // Remove os segundos para comparação
        postDateObj.setSeconds(0, 0);
        currentDateObj.setSeconds(0, 0);
        
        // Calcula a diferença em minutos
        const diffMinutes = (currentDateObj.getTime() - postDateObj.getTime()) / (1000 * 60);
        
        // Obtém a flexibilidade das configurações do PHP
        const flexibilidadeMinutos = window.cod5PluginData.flexibilidadeMinutos || 60;
        
        debugLog(`Data do post: ${postDateObj.toISOString()}`);
        debugLog(`Data atual: ${currentDateObj.toISOString()}`);
        debugLog(`Diferença em minutos: ${diffMinutes}`);
        debugLog(`Flexibilidade configurada: ${flexibilidadeMinutos} minutos`);
        
        // Retorna true se a diferença for maior que a flexibilidade permitida
        const isRetroactive = diffMinutes > flexibilidadeMinutos;
        debugLog(`Data é retroativa: ${isRetroactive}`);
        
        return isRetroactive;
    }

    /**
     * Cria a notificação de erro
     */
    function createNotification() {
        if (hasNotification) return;
        
        const { getCurrentPost } = wp.data.select('core/editor');
        const { getEditedPostAttribute } = wp.data.select('core/editor');
        const { isSavingPost } = wp.data.select('core/editor');
        const { isAutosavingPost } = wp.data.select('core/editor');
        
        if (isSavingPost() || isAutosavingPost()) return;
        
        const post = getCurrentPost();
        if (!post) return;
        
        const postDate = getEditedPostAttribute('date');
        if (!postDate) return;
        
        debugLog(`Verificando notificação para data: ${postDate}`);
        
        // Verifica se a data é retroativa e se o usuário tem restrição
        if (dataEhRetroativa(postDate) && !isAdmin()) {
            const errorMessage = getErrorMessage(postDate);
            debugLog(`Criando notificação: ${errorMessage}`);
            
            createNotice(
                'error',
                errorMessage,
                {
                    id: 'cod5-past-date-warning',
                    isDismissible: true,
                    actions: [
                        {
                            label: 'OK',
                            onClick: () => {
                                hasNotification = false;
                            }
                        }
                    ]
                }
            );
            hasNotification = true;
        } else if (hasNotification) {
            debugLog('Removendo notificação - data válida ou usuário admin');
            const { removeNotice } = wp.data.dispatch('core/notices');
            removeNotice('cod5-past-date-warning');
            hasNotification = false;
        }
    }

    /**
     * Inscreve-se nas mudanças do estado do post
     */
    subscribe(() => {
        try {
            const post = select('core/editor').getCurrentPost();
            if (!post) return;
            
            const postDate = post.date;
            const status = post.status;
            
            // Só verifica se mudou a data ou o status
            if (postDate === lastPostDate && status === lastStatus) return;
            
            lastPostDate = postDate;
            lastStatus = status;
            
            debugLog(`Mudança detectada - Data: ${postDate}, Status: ${status}`);
            
            if (isAdmin()) {
                debugLog('Usuário é admin - pulando validação');
                return;
            }
            
            // Só verifica se o status for publish, future ou pending
            if (['publish', 'future', 'pending'].includes(status)) {
                debugLog(`Status válido para validação: ${status}`);
                createNotification();
            } else {
                debugLog(`Status não requer validação: ${status}`);
            }
        } catch (error) {
            debugLog(`Erro no subscribe: ${error.message}`);
        }
    });
    
    // Log inicial
    debugLog('Plugin COD5 carregado');
    debugLog(`Flexibilidade configurada: ${window.cod5PluginData?.flexibilidadeMinutos || 'não definida'} minutos`);
    debugLog(`Usuário é admin: ${isAdmin()}`);
})(window.wp); 