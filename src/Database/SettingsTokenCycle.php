<?php

declare(strict_types=1);

namespace DaVez\Database;

use DaVez\Domain\LegacyIdentity;
use DaVez\Domain\OperationalContext;
use DaVez\Domain\TokenCycle;
use RuntimeException;
use mysqli;

final class SettingsTokenCycle
{
    /** @var mysqli */
    private $connection;

    public function __construct(mysqli $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Mantém o contrato legado de rotação persistida no primeiro acesso.
     *
     * @return array<string, mixed>
     */
    public function loadAndRotate(OperationalContext $context): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $settings = $this->loadSettings();
            $information = TokenCycle::evaluate($settings, $context);

            if (!$information['needs_rotate']) {
                return $this->decorate($settings, $information, $context);
            }

            $observedToken = (string) ($settings['token'] ?? '');
            $observedTokenDate = $settings['token_data'] ?? null;
            $newToken = LegacyIdentity::tokenCode(6);
            $newBaseDate = $context->date();
            $statement = $this->connection->prepare(
                "UPDATE settings
                 SET token=?, token_data=?
                 WHERE id=1
                   AND token=?
                   AND token_data <=> ?"
            );

            if (!$statement) {
                throw new RuntimeException(
                    'Atualização de token indisponível.'
                );
            }

            $statement->bind_param(
                'ssss',
                $newToken,
                $newBaseDate,
                $observedToken,
                $observedTokenDate
            );

            if (!$statement->execute()) {
                $statement->close();
                throw new RuntimeException(
                    'Atualização de token indisponível.'
                );
            }
            $updated = $statement->affected_rows === 1;
            $statement->close();

            if ($updated) {
                $settings['token'] = $newToken;
                $settings['token_data'] = $newBaseDate;
                $information = TokenCycle::evaluate($settings, $context);

                return $this->decorate(
                    $settings,
                    $information,
                    $context
                );
            }

            // Outro request venceu o compare-and-set. A próxima iteração
            // recarrega o valor persistido em vez de sobrescrevê-lo.
        }

        throw new RuntimeException('Atualização de token indisponível.');
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSettings(): array
    {
        $result = $this->connection->query(
            "SELECT token, token_data, chamada_aberta, chamada_inicio,
                    chamada_fim, lat_base, lng_base, raio
             FROM settings
             WHERE id=1
             LIMIT 1"
        );

        if (!$result) {
            throw new RuntimeException('Configurações indisponíveis.');
        }

        $settings = $result->fetch_assoc();

        if (!$settings) {
            throw new RuntimeException('Configurações indisponíveis.');
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $information
     * @return array<string, mixed>
     */
    private function decorate(
        array $settings,
        array $information,
        OperationalContext $context
    ): array {
        $settings['token_cycle_start'] = $information['cycle_start']
            ->format('Y-m-d H:i:s');
        $settings['token_cycle_end'] = $information['cycle_end']
            ->format('Y-m-d H:i:s');
        $settings['operational_date'] = $context->date();

        return $settings;
    }
}
