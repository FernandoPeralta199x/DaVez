# Deploy e rollback

## Estado

O DaVez ainda não está autorizado para produção. Este documento define o processo
esperado; ele não substitui a conclusão dos itens P0/P1 nem a validação em staging.

## Ambientes

- **Local:** desenvolvimento com dados sintéticos.
- **Staging:** cópia da arquitetura de produção com banco anonimizado.
- **Produção:** somente após aprovação dos critérios de segurança e operação.

Cada ambiente deve usar banco, secrets, storage, logs e domínio próprios.

## Pré-requisitos

1. PHP e extensões compatíveis com a aplicação.
2. MySQL com InnoDB e `utf8mb4`.
3. HTTPS válido.
4. Webroot configurado para não expor `.private`, `database`, `docs`, `logs`,
   `reports`, `scripts`, `tests`, arquivos de configuração ou backups.
5. Variáveis de ambiente preenchidas conforme `.env.example`.
6. Secrets fornecidos por mecanismo externo ao código, repositório e artefato
   de release, como secret manager ou variáveis protegidas do ambiente.
7. Usuário do banco sem privilégios DDL em runtime.
8. Backup concluído e restauração previamente testada.

## Validação antes do deploy

```powershell
.\scripts\validate.ps1
```

Também devem passar:

- migrations em banco descartável;
- testes de autenticação, autorização e CSRF;
- testes de concorrência;
- fluxo E2E de check-in e painel;
- revisão de secrets e do conteúdo do artefato.

## Ordem do deploy

1. Colocar o sistema em janela de manutenção quando houver migration incompatível.
2. Criar backup consistente.
3. Registrar versão atual da aplicação e do schema.
4. Executar migrations com usuário administrativo temporário.
5. Publicar o artefato sem dados ou arquivos privados.
6. Limpar opcode cache, se aplicável.
7. Executar smoke tests.
8. Liberar tráfego.
9. Monitorar erros, latência, autenticação e integridade das filas.

## Smoke tests

- interface pública carrega sem erro;
- manifesto e Service Worker são válidos;
- endpoints dinâmicos não entram no Cache Storage;
- usuário não autenticado não acessa fila completa ou admin;
- GET não altera estado;
- CSRF inválido retorna `403`;
- check-in legítimo cria uma única posição;
- ações administrativas produzem auditoria sanitizada.

## Rollback

1. Interromper novas mutações.
2. Restaurar a versão anterior do artefato.
3. Executar rollback de schema somente quando documentado e seguro.
4. Se o rollback de schema for destrutivo, restaurar o backup em ambiente isolado
   e validar antes de substituir o banco.
5. Invalidar sessões ou tokens afetados.
6. Registrar causa, intervalo, impacto e ações corretivas.

## PWA após deploy

- aumentar a versão do cache quando assets mudarem;
- confirmar remoção de caches antigos;
- observar clientes reais com versões anteriores;
- não usar Service Worker para cachear PHP, admin, APIs, logs ou relatórios.
