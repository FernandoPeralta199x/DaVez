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

### Política do cache da PWA

```powershell
node tests/security/service_worker_cache_policy.test.js
```

Confirma que apenas assets estáticos conhecidos entram no cache. Navegações usam rede com fallback offline, enquanto PHP, APIs, painel, logs, relatórios, origens externas e métodos não GET permanecem fora do Service Worker.
