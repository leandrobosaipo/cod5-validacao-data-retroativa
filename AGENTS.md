# Instrucoes para contribuidores do plugin COD5

Este repositório contém o plugin **COD5 Validação de Data Retroativa** para WordPress.
Siga estas diretrizes ao editar arquivos:

## Estilo de Código
- PHP no padrão WordPress, indentado com 4 espaços.
- Comentários sempre em português.
- Evite usar funções não compatíveis com PHP 7.2.

## Testes Rápidos
Antes de commitar, execute:
```bash
php -l cod5-validacao-data-retroativa.php
node --check admin.js
```
A validação PHP pode não estar disponível no ambiente. Se falhar por comando não encontrado,
registre essa informação no resumo de testes.

## Ajustes no Plugin
- Todas as configurações estão na classe `COD5_Config`.
- Utilize `COD5_Utils::debug_log` para mensagens de depuração.
- A função `cod5_validar_data_retroativa` é o ponto central de validação. Já inclui uma
verificação para pular validação em atualizações onde a data não mudou.

## Documentação
Atualize `README.md` sempre que adicionar novas funcionalidades ou comportamentos.
