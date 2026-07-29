# Contribuindo

## Fluxo de trabalho

1. Crie uma branch a partir de `main`.
2. Faça uma alteração pequena e de escopo único.
3. Não misture correção, refatoração, redesign e infraestrutura.
4. Adicione ou ajuste testes quando houver infraestrutura disponível.
5. Execute as validações compatíveis com a mudança.
6. Abra um pull request em modo draft até a validação terminar.

Use branches no formato:

```text
agent/descricao-curta
```

## Segurança

Antes de adicionar arquivos ao Git:

```powershell
git status --short
git diff --check
```

Nunca versione secrets, logs, relatórios, dumps ou dados pessoais. Utilize `config.example.php` e `.env.example` somente com nomes de variáveis e valores vazios.

## Commits

Use mensagens curtas e objetivas, por exemplo:

```text
prepara estrutura inicial do projeto
remove dados sensíveis dos logs
corrige concorrência da fila principal
adiciona proteção csrf ao painel
```

## Pull requests

Descreva:

- problema resolvido;
- arquivos afetados;
- risco de regressão;
- validações executadas;
- pontos ainda não validados.
