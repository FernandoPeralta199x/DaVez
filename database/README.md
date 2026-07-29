# Banco de dados

O pacote recebido continha somente um arquivo `_banco.sql` vazio. Portanto, não existe neste baseline um schema confiável para criação ou restauração do banco.

Antes de adicionar `schema.sql` ou migrations:

1. obtenha o schema de uma fonte autorizada;
2. remova dados, usuários, hosts e credenciais;
3. revise chaves estrangeiras, constraints, índices e campos obrigatórios;
4. verifique isolamento dos dados e trilha de auditoria;
5. documente rollback;
6. valide em banco descartável.

Não exporte dados reais para este repositório.
