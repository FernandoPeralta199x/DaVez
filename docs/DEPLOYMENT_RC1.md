# Deploy controlado — DaVez Tech UX RC1

## Não aplicar diretamente sobre produção

O pacote altera o início padrão do ciclo operacional de `06:00` para `01:30`. Uma troca no meio de um ciclo pode separar check-ins, códigos, sessões e fila entre datas operacionais diferentes.

## Pré-requisitos

- backup completo dos arquivos;
- dump do banco;
- restauração validada em staging;
- PHP com `mysqli`, `mbstring`, `json`, `openssl` e `session`;
- Percona/MySQL compatível com o schema atual;
- HTTPS ativo;
- `davez-env.php` fora do webroot e com permissão `0600`.

## Variáveis obrigatórias/recomendadas

```text
APP_TIMEZONE=America/Sao_Paulo
APP_OPERATIONAL_CYCLE_TIME=01:30
```

Não use offsets fixos como regra de negócio. O aplicativo deriva o offset vigente do timezone IANA e configura a sessão MySQL.

## Janela de corte recomendada

1. Fechar a chamada.
2. Garantir que não haja entregador em operação.
3. Fazer backup final.
4. Aplicar o pacote em staging e executar smoke tests.
5. Programar o corte logo após o encerramento do último ciclo antigo.
6. Limpar/purgar cache da CDN/Varnish, mantendo Varnish desativado para endpoints dinâmicos.
7. Confirmar que `service-worker.js` é entregue sem cache prolongado.
8. Abrir a chamada apenas após validar o novo ciclo.

## Smoke tests obrigatórios

- login administrativo;
- abertura/fechamento da chamada;
- emissão de código;
- check-in dentro do geofence;
- entrada na fila;
- despacho e registro em `delivery_events`;
- recuperação pelo mesmo código;
- revogação da sessão anterior;
- ranking por período fixo e personalizado;
- lista de relatórios, paginação e filtros;
- geração e abertura do PDF;
- logout;
- atualização da PWA em aparelho que já possuía a versão anterior.

## Rollback

1. Fechar a chamada.
2. Restaurar os arquivos anteriores.
3. Restaurar as variáveis de ciclo anteriores.
4. Purgar cache e Service Worker, se necessário.
5. Restaurar o banco apenas se houver alteração de dados incompatível durante a janela.
6. Reexecutar smoke tests.

Este RC não adiciona migration, portanto o rollback de código é possível sem downgrade de schema. Entretanto, dados gerados após a mudança de ciclo devem ser avaliados antes de retornar ao horário anterior.
