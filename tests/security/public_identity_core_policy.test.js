const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");

const root = path.resolve(__dirname, "..", "..");
const read = (relativePath) =>
  fs.readFileSync(path.join(root, relativePath), "utf8");

const identity = read("src/Security/PublicIdentity.php");
const store = read("src/Database/PublicIdentityStore.php");
const securityBootstrap = read("src/Security/Bootstrap.php");
const databaseBootstrap = read("src/Database/bootstrap.php");
const environmentExample = read(".env.example");
const configExample = read("config.example.php");
const ticketMigration = read(
  "database/migrations/006_create_admission_tickets.sql"
);
const sessionMigration = read(
  "database/migrations/007_create_public_sessions.sql"
);

assert.match(identity, /DAVEZ_PUBLIC_TICKET_LENGTH\s*=\s*8/);
assert.match(identity, /DAVEZ_PUBLIC_TICKET_TTL_SECONDS\s*=\s*600/);
assert.match(identity, /PUBLIC_TICKET_HMAC_KEY/);
assert.match(identity, /hash_hmac\(\s*['"]sha256['"]/);
assert.match(identity, /hash\(\s*['"]sha256['"]/);
assert.match(identity, /random_bytes\(DAVEZ_PUBLIC_SESSION_BYTES\)/);
assert.match(identity, /DAVEZ_PUBLIC_SESSION_BYTES\s*=\s*32/);
assert.match(identity, /DAVEZ_PUBLIC_SESSION_MAX_SECONDS\s*=\s*86400/);
assert.match(identity, /__Host-davez_public/);
assert.match(identity, /davez_public_dev/);
assert.match(identity, /httponly['"]?\s*=>\s*true/);
assert.match(identity, /samesite['"]?\s*=>\s*['"]Strict['"]/);
assert.match(identity, /A identidade pública exige HTTPS/);

assert.match(store, /INSERT INTO admission_tickets/);
assert.match(store, /FROM admission_tickets/);
assert.match(store, /FOR UPDATE/);
assert.match(store, /public function findTicketStatus\(/);
assert.match(
  store,
  /SELECT purpose, expires_at, consumed_at, revoked_at[\s\S]*?WHERE ticket_hash = \?/
);
assert.match(store, /'active'\|'consumed'\|'expired'\|'revoked'/);
assert.match(
  store,
  /SET consumed_at = \?, checkin_id = \?/
);
assert.match(
  store,
  /purpose = \\'recovery\\' AND checkin_id = \?/
);
assert.match(store, /INSERT INTO public_sessions/);
assert.match(store, /rotated_from_id/);
assert.match(store, /revocation_reason = \?/);
assert.match(store, /active_slot = NULL/);
assert.match(store, /active_slot = 1/);
assert.match(store, /sessions\.token_hash = \?/);
assert.match(store, /expires_at > \?/);
assert.match(store, /REVOCATION_REASONS/);
assert.match(store, /\$this->connection->prepare\(\$sql\)/);
assert.doesNotMatch(store, /->query\s*\(/);
assert.doesNotMatch(store, /public_access_tickets/);
assert.doesNotMatch(store, /\brotated_from\b(?!\s*=)/);
assert.doesNotMatch(store, /toUtcSql/);
assert.doesNotMatch(store, /SELECT\s+\*/i);

assert.match(ticketMigration, /CREATE TABLE IF NOT EXISTS admission_tickets/);
assert.match(ticketMigration, /INTERVAL 10 MINUTE/);
assert.match(
  ticketMigration,
  /consumed_at IS NOT NULL AND checkin_id IS NOT NULL/
);
assert.match(sessionMigration, /rotated_from_id/);
assert.match(sessionMigration, /revocation_reason/);
assert.match(
  sessionMigration,
  /UNIQUE KEY uniq_public_session_active_device/
);

assert.match(
  securityBootstrap,
  /require_once __DIR__ \. '\/PublicIdentity\.php';/
);
assert.match(
  databaseBootstrap,
  /require_once __DIR__ \. '\/PublicIdentityStore\.php';/
);
assert.match(
  databaseBootstrap,
  /function davez_public_identity_store\(mysqli \$connection\)/
);
assert.match(environmentExample, /^PUBLIC_TICKET_HMAC_KEY=$/m);
assert.match(
  configExample,
  /strlen\(\$publicTicketHmacKey\) < 32/
);

process.stdout.write("public_identity_core_policy: OK\n");
