# Deploy e rollback — DaVez 1.2.0 RC2

## Escopo

Este RC não altera o schema. Ele adiciona dois endpoints PDF, uma folha de
estilos, uma classe de consulta e atualiza o Service Worker.

## Antes do deploy

1. backup e restore testados;
2. staging com cópia sanitizada do banco;
3. extensões `mysqli`, `mbstring`, `openssl`, `session` e `json`;
4. validar `APP_OPERATIONAL_CYCLE_TIME=01:30`;
5. executar `scripts/validate.ps1` ou `scripts/validate-local.sh`;
6. testar os três PDFs com dados representativos;
7. confirmar que Nginx não aplica cache a `service-worker.js`.

## Smoke test

- login administrativo;
- emissão de código diário;
- check-in e recuperação;
- entrada, reordenação e despacho;
- ranking por preset e intervalo;
- PDF do ranking;
- relatórios com 15 itens por página;
- PDF do índice e relatório individual;
- atualização da PWA após reload.

## Rollback

1. manter o ZIP e arquivos da versão anterior;
2. substituir somente os arquivos do release;
3. restaurar o Service Worker anterior se o frontend for revertido;
4. purgar cache de CDN/Nginx apenas para assets do app;
5. executar smoke test;
6. não restaurar banco, pois este RC não possui migration.
