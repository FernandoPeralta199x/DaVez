<?php

declare(strict_types=1);

namespace DaVez\Config;

/**
 * Feature flags do DaVez.
 *
 * Toda flag nasce DESLIGADA. A ativação acontece exclusivamente por variável de
 * ambiente `FEATURE_<NOME_EM_MAIUSCULO>` com valor 1/true/on/yes. Flags
 * desconhecidas retornam sempre false (negar por padrão). Isso permite adicionar
 * caminhos de código multi-tenant "no escuro", sem alterar o comportamento de
 * produção até o corte deliberado.
 */
final class FeatureFlags
{
    /** @var list<string> Flags reconhecidas — fonte única da verdade. */
    private const KNOWN = [
        'multi_tenant',
        'admin_users_db',
    ];

    /** @var list<string> */
    private const TRUTHY = ['1', 'true', 'on', 'yes'];

    public static function enabled(string $flag): bool
    {
        $normalized = strtolower(trim($flag));

        if ($normalized === '' || !in_array($normalized, self::KNOWN, true)) {
            return false;
        }

        $value = getenv('FEATURE_' . strtoupper($normalized));

        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), self::TRUTHY, true);
    }

    /**
     * Estado atual de todas as flags conhecidas.
     *
     * @return array<string, bool>
     */
    public static function all(): array
    {
        $state = [];

        foreach (self::KNOWN as $flag) {
            $state[$flag] = self::enabled($flag);
        }

        return $state;
    }

    /** @return list<string> */
    public static function known(): array
    {
        return self::KNOWN;
    }
}
