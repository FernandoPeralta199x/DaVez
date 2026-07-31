# Deploy na Hostinger (compartilhado Business ou superior)

Guia "turnkey" para colocar o DaVez no ar em hospedagem compartilhada com PHP-FPM.
Complementa `docs/DEPLOYMENT.md` (processo genérico) e `docs/DATABASE_OPERATIONS.md`
(banco). Modelos prontos em `deploy/`.

> Regra de ouro: **segredos e o banco nunca vão para o Git**. `config.php`, `.env`,
> `davez-env.php` preenchido, logs e relatórios ficam fora do repositório.

## Pré-requisitos

- Plano com **PHP 8.1+** e extensões `json`, `mbstring`, `mysqli`, `openssl`.
- **MySQL/MariaDB** (InnoDB, `utf8mb4`).
- **HTTPS válido** (a identidade pública v2 exige; sem HTTPS, o check-in é recusado).
- Acesso ao **phpMyAdmin** ou **SSH** para aplicar o schema como administrador do banco.

## 1. Enviar os arquivos

Suba o conteúdo do projeto para o webroot (a Hostinger normalmente usa `public_html`).
**Não** envie: `.git/`, `tests/`, `.private/`, `.runtime/`, `node_modules/`, nem
qualquer `.rar`/`.zip` de backup. Use `scripts/build-release.ps1` para gerar um
pacote já sem esses itens, ou envie apenas os diretórios de runtime
(`DaVez/`, `database/`, `docs/`, `icons/`, `img/`, `js/`, `src/`) mais os `.php`
públicos, `index.html`, `manifest.json`, `service-worker.js`, `.htaccess`.

## 2. Ambiente fora do webroot (`davez-env.php`)

1. Copie `deploy/davez-env.example.php` para **fora** do `public_html`, por exemplo
   `/home/SEU_USUARIO/davez-env.php`.
2. Preencha os valores reais. Gere os segredos assim (via SSH ou local):

   ```bash
   php -r "echo bin2hex(random_bytes(24)).PHP_EOL;"   # APP_RATE_LIMIT_SECRET e PUBLIC_TICKET_HMAC_KEY
   php -r "echo password_hash('SUA_SENHA_FORTE', PASSWORD_DEFAULT).PHP_EOL;"  # ADMIN_PASSWORD_HASH
   ```

3. Crie o storage privado fora do webroot:

   ```bash
   mkdir -p /home/SEU_USUARIO/davez-private/rate-limit
   chmod 700 /home/SEU_USUARIO/davez-private
   ```

`APP_RATE_LIMIT_DIR` e `APP_LOG_PATH` devem apontar para dentro de `davez-private`.
O app **recusa** qualquer caminho dentro do `DOCUMENT_ROOT`.

## 3. Carregar o ambiente antes de tudo (`.user.ini`)

Copie `deploy/user.ini.example` para o webroot como **`.user.ini`** e ajuste o
caminho:

```ini
auto_prepend_file = "/home/SEU_USUARIO/davez-env.php"
display_errors = Off
log_errors = On
```

Isso é obrigatório: o rate limiter e o HMAC do ticket rodam **antes** do
`config.php`, então as variáveis precisam existir já no início da requisição.
Depois de criar/alterar o `.user.ini`, aguarde alguns minutos ou reinicie o
PHP pelo painel (o PHP-FPM faz cache do `.user.ini`).

## 4. Banco de dados

O usuário do banco usado em runtime (`DB_USER`) **não deve ter DDL**. Aplique o
schema com um usuário administrador (o dono do banco na Hostinger).

**Instalação nova (recomendado):** importe o schema completo, que já inclui todas
as tabelas (inclusive `delivery_events` do ranking):

- **phpMyAdmin:** selecione o banco → aba *Importar* → envie `database/schema.sql`.
- **SSH:**

  ```bash
  mysql -u SEU_ADMIN_DO_BANCO -p SEU_BANCO < database/schema.sql
  ```

**Banco já existente (upgrade incremental):** aplique as migrations em ordem, uma
única vez, com um usuário administrador. Rode o preflight de
`docs/DATABASE_OPERATIONS.md` antes das que usam `ALTER TABLE` (005 e 008):

```bash
for f in $(ls database/migrations/*.sql | sort); do
  echo "Aplicando $f"
  mysql -u SEU_ADMIN_DO_BANCO -p SEU_BANCO < "$f" || { echo "FALHOU em $f"; break; }
done
```

As migrations vão de `001` a `009`. A `009` cria `delivery_events` (log durável
de entregas que alimenta o ranking). Depois disso, **revogue o DDL** do
`DB_USER` de runtime, se ainda tiver.

## 5. Configurar o geofence (localização da loja)

Acesse `https://SEU_DOMINIO/admin.php`, entre com o usuário admin e vá em
**Configurações**. Defina **latitude, longitude e raio** da loja.

> Numa instalação nova o geofence vem `0,0`, o que **bloqueia todo check-in**
> (estado seguro "sem localização"). O sistema só aceita check-in depois que a
> base real for definida.

## 6. HTTPS e proxy (Cloudflare)

- HTTPS é obrigatório. Ative o SSL do plano antes de operar.
- Se o TLS terminar num proxy/CDN (Cloudflare), o PHP vê a conexão interna como
  HTTP e a identidade pública responderia 426. Preencha então, no `davez-env.php`,
  `APP_TRUSTED_PROXIES` com as **faixas reais** do proxy (IPs/CIDR) — nunca
  `0.0.0.0/0`. Veja `docs/SECURITY_IMPLEMENTATION.md`.

## 7. Polling

O polling público já está otimizado: consulta só a fila, a cada 10 s, e pausa
quando o app fica em segundo plano. Nenhuma alteração é necessária. Para dezenas
de motoboys no mesmo IP da loja, o `DaVez/listar.php` tem rate limit de 600/min.

## 8. Backup e restore (antes de liberar o "Limpar")

O "Limpar lista e salvar relatório" apaga os check-ins/fila do ciclo (preservando
o relatório e o histórico de entregas). É irreversível. Antes de usar em produção,
valide um ciclo de **backup e restore** conforme `docs/BACKUP_RESTORE.md`.

## 9. Smoke tests após o deploy

- A interface pública (`index.html`) carrega sem erro sob HTTPS.
- `admin.php` pede login; sessão inválida não acessa dados.
- Um check-in legítimo cria uma única posição na fila.
- "Saiu para entrega" registra a entrega e ela aparece no **Ranking**.
- CSRF inválido retorna `403`; método errado é recusado.

## Checklist de corte

- [ ] Arquivos enviados sem `tests/`, `.git/`, `.runtime/`, backups.
- [ ] `davez-env.php` preenchido, fora do `public_html`, com segredos únicos.
- [ ] `APP_RATE_LIMIT_SECRET` e `PUBLIC_TICKET_HMAC_KEY` com ≥ 32 bytes aleatórios.
- [ ] `.user.ini` no webroot apontando para o `davez-env.php`.
- [ ] `davez-private/` criado fora do webroot, com permissão restrita.
- [ ] Schema aplicado (schema.sql ou migrations 001..009) por usuário admin do banco.
- [ ] `DB_USER` de runtime **sem** DDL.
- [ ] Geofence da loja definido em Configurações (não `0,0`).
- [ ] HTTPS ativo; `APP_TRUSTED_PROXIES` definido se houver Cloudflare/proxy.
- [ ] Backup e restore testados antes de liberar o "Limpar".
- [ ] Smoke tests acima passando.
