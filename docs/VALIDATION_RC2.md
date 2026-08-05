# Validação — DaVez 1.2.0 RC2

## Resultado final

| Verificação | Resultado |
|---|---:|
| Lint PHP | 78/78 |
| Testes PHP | 25/25 |
| Testes Node | 13/13 |
| Sintaxe dos assets JavaScript | 3/3 |
| `manifest.json` e `BUILD_INFO.json` | válidos |
| `docker-compose.local.yml` | YAML válido |
| CSS RC2 | parseado sem erro |
| PDF sintético | 12 páginas, leitura e renderização válidas |
| Varredura de segredos | passou |
| Manifesto SHA-256 do deploy | verificado |

## Fluxos cobertos estaticamente/autonomamente

- correção de escopo em `recover.php`;
- contratos de identidade pública e sessão;
- segurança dos endpoints administrativos e PDFs;
- filtros, paginação e limites de ranking/relatórios;
- acessibilidade e contratos da interface;
- política de cache do Service Worker;
- allowlist do artefato de produção;
- operações de domínio que não dependem de MySQL real.

## Não validado neste ambiente

- MySQL/Percona real;
- `EXPLAIN` com dados representativos;
- concorrência, locks e deadlocks;
- E2E autenticado em navegador;
- Nginx/PHP-FPM;
- backup e restauração;
- deploy e rollback em staging.

O resultado é adequado para um **release candidate de staging**, não para
promoção automática à produção.
