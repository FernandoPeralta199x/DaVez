# DaVez

Aplicação web/PWA para check-in, organização de filas e acompanhamento operacional de motoboys.

## Estado atual

Este repositório representa o baseline legado recebido para organização e correção incremental.

> **Não utilizar em produção neste estado.** A aplicação ainda possui pendências de autenticação, autorização, proteção de dados, concorrência, logs e gestão de configuração.

O primeiro commit preserva os caminhos e o comportamento existentes. Mudanças funcionais e de segurança devem ser feitas em branches e pull requests separados.

## Stack

- PHP procedural com MySQLi
- Banco compatível com MySQL
- HTML, CSS e JavaScript sem framework
- Progressive Web App com Service Worker

## Estrutura atual

```text
.
├── .github/               # Templates de colaboração
├── DaVez/                 # Endpoints da fila DaVez
├── database/              # Documentação e futuras migrations
├── docs/                  # Arquitetura e roadmap
├── icons/                 # Ícones da PWA
├── img/                   # Imagens da interface
├── logs/                  # Dados de runtime; conteúdo ignorado pelo Git
├── reports/               # Relatórios gerados; conteúdo ignorado pelo Git
├── tests/                 # Estratégia e futura suíte automatizada
├── admin.php              # Painel administrativo legado
├── index.html             # Interface pública
├── config.example.php     # Template seguro de configuração
├── manifest.json          # Manifesto da PWA
└── service-worker.js      # Service Worker
```

Os arquivos executáveis permanecem na raiz porque a aplicação utiliza caminhos relativos e ainda não possui testes que permitam movê-los com segurança.

## Configuração local

1. Copie `config.example.php` para `config.php`.
2. Configure as variáveis descritas em `.env.example` no servidor ou processo PHP.
3. Crie o banco a partir de um schema revisado.
4. Aponte um servidor PHP para esta pasta somente em ambiente local isolado.

Exemplo no PowerShell:

```powershell
Copy-Item config.example.php config.php
```

`config.php`, `.env`, logs e relatórios são deliberadamente ignorados pelo Git.

## Banco de dados

O material recebido não contém um schema confiável. Consulte [database/README.md](database/README.md) antes de tentar executar a aplicação.

## Desenvolvimento

Leia:

- [Arquitetura atual](docs/ARCHITECTURE.md)
- [Roadmap de correções](docs/ROADMAP.md)
- [Política de segurança](SECURITY.md)
- [Guia de contribuição](CONTRIBUTING.md)

## Licença

Nenhuma licença foi definida. O código permanece sob os direitos do proprietário do repositório.
