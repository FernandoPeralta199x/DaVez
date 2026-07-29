# Operação e observabilidade

## Sinais mínimos

- disponibilidade e latência das rotas principais;
- taxa de respostas `4xx` e `5xx`;
- falhas e bloqueios de autenticação;
- rejeições de CSRF;
- rate limits acionados;
- deadlocks, timeouts e falhas de transação;
- posições duplicadas ou lacunas anormais;
- crescimento dos logs e uso de disco;
- sucesso e duração de backups.

## Logs

- usar eventos estruturados e allowlist de campos;
- nunca registrar senha, token, cookie, localização precisa ou POST bruto;
- gerar `request_id` aleatório para correlação;
- rotacionar por tamanho/tempo;
- restringir leitura e escrita;
- excluir conforme a política de retenção.

## Alertas iniciais

- erro `5xx` sustentado;
- banco indisponível;
- espaço em disco crítico;
- falhas repetidas de login;
- lock ou deadlock acima do limiar operacional;
- backup ausente ou restauração de teste vencida.

## Resposta a incidente

1. Conter acesso ou mutações afetadas.
2. Preservar evidências sem expor dados.
3. Rotacionar credenciais potencialmente comprometidas.
4. Registrar linha do tempo e impacto.
5. Corrigir em ambiente isolado.
6. Validar rollback e recuperação.
7. Comunicar responsáveis e obrigações legais aplicáveis.
