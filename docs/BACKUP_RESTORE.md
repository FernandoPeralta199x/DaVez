# Backup e restauração

## Princípios

- backups devem ser criptografados e armazenados fora do webroot;
- acesso deve seguir menor privilégio;
- nomes, localização e dados operacionais devem respeitar retenção definida;
- um backup não é considerado válido até a restauração ser testada.

## Banco MySQL

Use credenciais fornecidas por mecanismo seguro. Não coloque senha na linha de
comando, em scripts versionados ou no histórico do terminal.

Exemplo conceitual:

```powershell
mysqldump --single-transaction --routines --triggers --databases NOME_DO_BANCO --result-file CAMINHO_PRIVADO
```

O arquivo produzido não pode ser copiado para `X:\Help`.

## Arquivos de runtime

Preservar, conforme a política de retenção:

- configuração externa;
- logs sanitizados necessários para auditoria;
- relatórios autorizados;
- versão implantada e manifesto das migrations.

Nunca incluir logs ou relatórios reais no Git.

## Teste de restauração

1. Criar ambiente descartável e isolado.
2. Restaurar o dump com usuário administrativo temporário.
3. Conferir versão do schema.
4. Executar validações de integridade e contagens esperadas.
5. Iniciar a aplicação com secrets de teste.
6. Executar smoke tests e fluxos críticos.
7. Destruir o ambiente e os dados temporários de forma controlada.
8. Registrar data, duração, responsável e resultado.

## Frequência inicial recomendada

- backup diário do banco;
- retenção proporcional à obrigação operacional e legal;
- teste de restauração trimestral e antes de mudanças críticas;
- backup extraordinário antes de migration ou deploy de alto risco.

Os valores finais dependem do volume, orçamento, hospedagem e requisitos legais.
