<?php
/**
 * davez-env.example.php — modelo do carregador de ambiente para hospedagem
 * compartilhada (Hostinger Business ou superior).
 *
 * COMO USAR
 *   1. Copie este arquivo para FORA do public_html, por exemplo:
 *        /home/SEU_USUARIO/davez-env.php
 *   2. Preencha os valores reais. NUNCA versione o arquivo preenchido.
 *   3. Aponte o .user.ini (auto_prepend_file) para ele — veja
 *      deploy/user.ini.example.
 *
 * POR QUE auto_prepend E NÃO config.php?
 *   As variáveis precisam existir ANTES do primeiro require dos endpoints:
 *   o rate limiter e o HMAC do ticket rodam em checkin.php ANTES do include
 *   de config.php. Carregar via auto_prepend_file garante isso.
 */

// ===== Banco de dados (MySQL da Hostinger) =====
// O usuário do app NÃO deve ter privilégio de DDL em runtime.
putenv('DB_HOST=localhost');
putenv('DB_NAME=<nome_do_banco>');
putenv('DB_USER=<usuario_do_banco_sem_ddl>');
putenv('DB_PASSWORD=<senha_do_banco>');

// ===== Administrador dono (acesso total, inclusive aos logs) =====
putenv('ADMIN_USER=<usuario_admin>');
// Gere o hash com:  php -r "echo password_hash('SUA_SENHA_FORTE', PASSWORD_DEFAULT);"
putenv('ADMIN_PASSWORD_HASH=<cole_o_hash_gerado_por_password_hash>');
putenv('ADMIN_SESSION_IDLE_SECONDS=1800');
putenv('ADMIN_SESSION_ABSOLUTE_SECONDS=28800');
putenv('APP_SESSION_NAME=davez_session');

// ===== Relógio operacional =====
putenv('APP_TIMEZONE=America/Sao_Paulo');
putenv('APP_OPERATIONAL_CYCLE_TIME=01:30');

// ===== Operadores/clientes (operam o painel, SEM acesso aos logs) =====
// JSON com usuário + hash (nunca senha em texto). Vazio = apenas o dono.
// Exemplo: [{"user":"DiromaPizzaria","hash":"<hash_gerado_por_password_hash>"}]
putenv('ADMIN_OPERATORS=');

// ===== Storage privado (obrigatoriamente fora do webroot) =====
// O app recusa qualquer caminho dentro do DOCUMENT_ROOT.
putenv('APP_RATE_LIMIT_DIR=/home/SEU_USUARIO/davez-private/rate-limit');
// Gere com:  php -r "echo bin2hex(random_bytes(24));"
putenv('APP_RATE_LIMIT_SECRET=<32_ou_mais_bytes_aleatorios>');
putenv('APP_LOG_PATH=/home/SEU_USUARIO/davez-private/app-events.log');

// ===== Identidade pública / tickets =====
putenv('PUBLIC_REQUEST_CONTEXT_SECONDS=43200');
// Gere com:  php -r "echo bin2hex(random_bytes(24));"  (mínimo 32 bytes)
putenv('PUBLIC_TICKET_HMAC_KEY=<32_ou_mais_bytes_aleatorios>');

// ===== Proxy reverso / Cloudflare (só se o TLS terminar num proxy) =====
// Sem esta variável, nenhum cabeçalho X-Forwarded-Proto é considerado.
// Use as faixas REAIS do proxy; NUNCA 0.0.0.0/0.
// putenv('APP_TRUSTED_PROXIES=<ip_ou_cidr_do_proxy>');
