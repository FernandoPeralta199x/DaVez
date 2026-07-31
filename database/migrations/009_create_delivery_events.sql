-- Migration 009: registra cada despacho ("Saiu para entrega") como um log
-- durável de desempenho que alimenta o ranking de motoboys.
--
-- Diferente de fila_da_vez (uma linha por motoboy por dia, sobrescrita a cada
-- reentrada e removida na limpeza do ciclo), esta tabela é append-only e
-- sobrevive ao fechamento do ciclo. Por isso NÃO possui chave estrangeira para
-- checkins, que são apagados na limpeza: o histórico é preservado por nome e
-- data operacional. Não guarda dado pessoal além do nome já exibido na fila.

CREATE TABLE IF NOT EXISTS delivery_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operational_date DATE NOT NULL,
    checkin_id BIGINT UNSIGNED NULL,
    nome VARCHAR(120) NOT NULL,
    dispatched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    queue_wait_seconds INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_delivery_date_name (operational_date, nome),
    KEY idx_delivery_date_time (operational_date, dispatched_at),
    KEY idx_delivery_checkin (checkin_id, operational_date),
    CONSTRAINT chk_delivery_wait CHECK (
        queue_wait_seconds IS NULL OR queue_wait_seconds >= 0
    )
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
