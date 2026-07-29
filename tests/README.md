# Testes

Não há suíte automatizada no baseline.

Os arquivos PHP com nomes de teste presentes na raiz são scripts legados acessíveis por HTTP e não constituem testes reproduzíveis. Eles serão tratados em uma tarefa de segurança separada, depois que o baseline estiver publicado.

Prioridades para a futura suíte:

1. testes de caracterização das respostas existentes;
2. autenticação, autorização e CSRF;
3. entrada, saída e reordenação das duas filas;
4. concorrência na atribuição de posições;
5. rotação e expiração de sessão;
6. limpeza do ciclo e geração de relatórios;
7. sanitização de logs e erros públicos.

## Testes disponíveis

### Política estática de logging

```powershell
node tests/security/logging_policy.test.js
```

Verifica que os endpoints não enviam POST bruto, tokens, nomes, coordenadas, identificadores ou erros de banco para o logger.

### Integração do logger

```powershell
php tests/security/log_event_test.php
```

Grava um evento em arquivo temporário pela implementação real de `log_event` e confirma que dados sensíveis são descartados enquanto métricas operacionais permitidas permanecem.
