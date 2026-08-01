<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Security/PublicIdentity.php';

use DaVez\Domain\OperationalContext;
use DaVez\Domain\OperationalCycle;

function public_identity_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function public_identity_assert(
    bool $condition,
    string $message
): void {
    if (!$condition) {
        public_identity_fail($message);
    }
}

function public_identity_expect_exception(
    callable $callback,
    string $expectedClass,
    string $message
): void {
    try {
        $callback();
    } catch (Throwable $exception) {
        public_identity_assert(
            $exception instanceof $expectedClass,
            $message . ' Exceção recebida: ' . get_class($exception)
        );
        return;
    }

    public_identity_fail($message);
}

$previousHmacKey = getenv('PUBLIC_TICKET_HMAC_KEY');
$previousServer = $_SERVER;
$previousCookies = $_COOKIE;
$syntheticHmacKey = str_repeat('chave-sintetica-', 3);
putenv('PUBLIC_TICKET_HMAC_KEY=' . $syntheticHmacKey);

for ($attempt = 0; $attempt < 128; $attempt++) {
    $ticketCode = davez_public_ticket_code();
    public_identity_assert(
        preg_match('/\A[0-9]{4}-[a-z]{4}\z/', $ticketCode) === 1,
        'O ticket não segue o formato 4 dígitos + 4 letras minúsculas.'
    );
}

public_identity_assert(
    davez_normalize_public_ticket_code(' 1166 - AAbb ') === '1166aabb',
    'A normalização não tratou separadores e caixa.'
);
public_identity_expect_exception(
    static function (): void {
        davez_normalize_public_ticket_code('aabb-1166');
    },
    InvalidArgumentException::class,
    'Um código fora do formato (letras antes dos dígitos) foi aceito.'
);

$ticketHash = davez_public_ticket_hash(
    '1166-aabb'
);
public_identity_assert(
    strlen($ticketHash) === 32,
    'O HMAC do ticket não possui 32 bytes binários.'
);
public_identity_assert(
    hash_equals(
        $ticketHash,
        davez_public_ticket_hash(' 1166 AABB ')
    ),
    'Representações equivalentes do ticket produziram hashes diferentes.'
);

putenv('PUBLIC_TICKET_HMAC_KEY=curta');
public_identity_expect_exception(
    static function (): void {
        davez_public_ticket_hash('1166-aabb');
    },
    RuntimeException::class,
    'Uma chave HMAC com menos de 32 bytes foi aceita.'
);
putenv('PUBLIC_TICKET_HMAC_KEY=' . $syntheticHmacKey);

$sessionToken = davez_public_session_token();
public_identity_assert(
    preg_match('/\A[A-Za-z0-9_-]{43}\z/', $sessionToken) === 1,
    'O token de sessão não representa 32 bytes em Base64 URL-safe.'
);
public_identity_assert(
    strlen(davez_decode_public_session_token($sessionToken)) === 32,
    'O token de sessão não decodificou para 32 bytes.'
);
$sessionHash = davez_public_session_hash($sessionToken);
public_identity_assert(
    strlen($sessionHash) === 32
    && hash_equals($sessionHash, davez_public_session_hash($sessionToken)),
    'O hash binário da sessão não é SHA-256 estável.'
);
public_identity_expect_exception(
    static function (): void {
        davez_public_session_hash('token-invalido');
    },
    InvalidArgumentException::class,
    'Um token de sessão malformado foi aceito.'
);

$saoPauloCycle = new OperationalCycle('America/Sao_Paulo', 6);
$saoPauloReference = new DateTimeImmutable(
    '2026-07-29 12:00:00',
    new DateTimeZone('America/Sao_Paulo')
);
$saoPauloContext = new OperationalContext(
    $saoPauloCycle,
    $saoPauloReference
);
public_identity_assert(
    davez_public_session_expires_at($saoPauloContext)
        == $saoPauloContext->end(),
    'A sessão ultrapassou o fim do ciclo operacional.'
);
public_identity_assert(
    davez_public_ticket_expires_at($saoPauloContext)
        ->getTimestamp()
        - $saoPauloReference->getTimestamp()
        === DAVEZ_PUBLIC_TICKET_TTL_SECONDS,
    'O ticket não expirou em dez minutos.'
);

$nearEndReference = new DateTimeImmutable(
    '2026-07-30 05:55:00',
    new DateTimeZone('America/Sao_Paulo')
);
$nearEndContext = new OperationalContext(
    $saoPauloCycle,
    $nearEndReference
);
public_identity_expect_exception(
    static function () use ($nearEndContext): void {
        davez_public_ticket_expires_at($nearEndContext);
    },
    RuntimeException::class,
    'Um ticket com menos de dez minutos restantes foi emitido.'
);

$dstCycle = new OperationalCycle('America/New_York', 0);
$dstReference = new DateTimeImmutable(
    '2026-11-01 00:30:00',
    new DateTimeZone('America/New_York')
);
$dstContext = new OperationalContext($dstCycle, $dstReference);
$dstExpiry = davez_public_session_expires_at(
    $dstContext,
    $dstContext->start()
);
public_identity_assert(
    $dstExpiry->getTimestamp() - $dstContext->start()->getTimestamp()
        === DAVEZ_PUBLIC_SESSION_MAX_SECONDS
    && $dstExpiry < $dstContext->end(),
    'A sessão não respeitou o teto exato de 24 horas em ciclo com DST.'
);
public_identity_expect_exception(
    static function () use ($saoPauloContext): void {
        davez_public_session_expires_at(
            $saoPauloContext,
            $saoPauloContext->start()->modify('-1 second')
        );
    },
    InvalidArgumentException::class,
    'Uma sessão emitida fora do ciclo foi aceita.'
);

public_identity_assert(
    davez_public_display_name('  Maria   da Silva  ') === 'Maria S.',
    'O nome público composto não foi abreviado.'
);
public_identity_assert(
    davez_public_display_name('Ágata') === 'Á.',
    'O nome público UTF-8 simples não foi abreviado.'
);
public_identity_expect_exception(
    static function (): void {
        davez_public_display_name("Nome\0Oculto");
    },
    InvalidArgumentException::class,
    'Um nome com caractere de controle foi aceito.'
);

$_SERVER = [
    'HTTPS' => 'on',
    'SERVER_PORT' => '443',
    'HTTP_HOST' => 'app.example.test',
    'REMOTE_ADDR' => '203.0.113.10',
];
public_identity_assert(
    davez_public_identity_cookie_name()
        === DAVEZ_PUBLIC_IDENTITY_COOKIE_HTTPS,
    'HTTPS não selecionou o cookie com prefixo __Host-.'
);
$httpsCookieOptions = davez_public_identity_cookie_options(time() + 600);
public_identity_assert(
    $httpsCookieOptions['secure'] === true
    && $httpsCookieOptions['httponly'] === true
    && $httpsCookieOptions['samesite'] === 'Strict'
    && $httpsCookieOptions['path'] === '/'
    && !array_key_exists('domain', $httpsCookieOptions),
    'O cookie HTTPS não possui as proteções obrigatórias.'
);

$_SERVER = [
    'SERVER_PORT' => '80',
    'HTTP_HOST' => 'localhost:8080',
    'REMOTE_ADDR' => '127.0.0.1',
];
public_identity_assert(
    davez_public_identity_cookie_name()
        === DAVEZ_PUBLIC_IDENTITY_COOKIE_LOCAL,
    'HTTP loopback não selecionou o cookie exclusivo de desenvolvimento.'
);
$localCookieOptions = davez_public_identity_cookie_options(time() + 600);
public_identity_assert(
    $localCookieOptions['secure'] === false
    && $localCookieOptions['httponly'] === true
    && $localCookieOptions['samesite'] === 'Strict',
    'O cookie local não preservou HttpOnly e SameSite=Strict.'
);

$_COOKIE[DAVEZ_PUBLIC_IDENTITY_COOKIE_LOCAL] = $sessionToken;
public_identity_assert(
    davez_public_identity_cookie_token() === $sessionToken,
    'Um token válido não foi lido do cookie local.'
);
$_COOKIE[DAVEZ_PUBLIC_IDENTITY_COOKIE_LOCAL] = 'invalido';
public_identity_assert(
    davez_public_identity_cookie_token() === null,
    'Um token malformado foi aceito pelo leitor de cookie.'
);

$_SERVER = [
    'SERVER_PORT' => '80',
    'HTTP_HOST' => 'app.example.test',
    'REMOTE_ADDR' => '203.0.113.10',
];
public_identity_expect_exception(
    static function (): void {
        davez_public_identity_cookie_name();
    },
    RuntimeException::class,
    'HTTP não local foi aceito para identidade pública.'
);

$_SERVER = [
    'SERVER_PORT' => '80',
    'HTTP_HOST' => 'localhost',
    'REMOTE_ADDR' => '203.0.113.10',
];
public_identity_expect_exception(
    static function (): void {
        davez_public_identity_cookie_name();
    },
    RuntimeException::class,
    'Um Host localhost remoto burlou a restrição de loopback.'
);

$_SERVER = $previousServer;
$_COOKIE = $previousCookies;

if ($previousHmacKey === false) {
    putenv('PUBLIC_TICKET_HMAC_KEY');
} else {
    putenv('PUBLIC_TICKET_HMAC_KEY=' . $previousHmacKey);
}

fwrite(STDOUT, 'public_identity_test: OK' . PHP_EOL);
