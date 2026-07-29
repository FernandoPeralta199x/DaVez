# Testes

Os testes automatizados de segurança ficam em `tests/security`.

## Política do artefato de produção

```bash
node tests/security/production_artifact_policy.test.js
```

O teste impede o retorno dos endpoints públicos de diagnóstico removidos no lote
F-015.

Prioridades para a futura suíte:

1. testes de caracterização das respostas existentes;
2. autenticação, autorização e CSRF;
3. entrada, saída e reordenação das duas filas;
4. concorrência na atribuição de posições;
5. rotação e expiração de sessão;
6. limpeza do ciclo e geração de relatórios;
7. sanitização de logs e erros públicos.
