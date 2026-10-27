-- ============================================================================
-- GEONEX SCADA - Passos 2 e 3: Gestão de O.S., RBAC, Telemetria e Postes (Pinos 8 a 12)
-- Banco de Dados: MySQL 8.0+
-- ============================================================================

CREATE DATABASE IF NOT EXISTS mina_iot_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE mina_iot_db;

-- ----------------------------------------------------------------------------
-- 1. Tabela de Usuários (Controle de Acesso baseado em Perfis - RBAC)
--    Roles:
--      - ADMIN:    Acesso total, gerencia sistema, abre e edita O.S.
--      - OPERADOR: Controla iluminação/PWM e abre novas O.S.
--      - TECNICO:  Visualiza chamados, assume O.S., altera status e preenche laudo
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    matricula VARCHAR(30) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    role ENUM('ADMIN', 'OPERADOR', 'TECNICO') NOT NULL DEFAULT 'OPERADOR',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. Tabela de Torres / Postes de Iluminação (Portas Digitais 8, 9, 10, 11 e 12)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS torres (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(20) NOT NULL UNIQUE COMMENT 'Ex: TR-01 a TR-05',
    setor VARCHAR(80) NOT NULL,
    pino_pwm INT NOT NULL COMMENT 'Portas digitais sequenciais Arduino Uno: 8, 9, 10, 11 e 12',
    modo_iluminacao ENUM('DESLIGADA', 'ECONOMIA', 'NORMAL', 'CERRACAO') NOT NULL DEFAULT 'NORMAL',
    nivel_brilho_atual TINYINT UNSIGNED NOT NULL DEFAULT 170 COMMENT 'Valor PWM 0..255',
    status_operacional ENUM('OPERANDO', 'EM_MANUTENCAO') NOT NULL DEFAULT 'OPERANDO',
    conectado TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Online, 0 = Offline',
    consumo_watts DECIMAL(7,2) NOT NULL DEFAULT 266.67 COMMENT 'Consumo instantâneo calculado (0..400W)',
    bateria DECIMAL(5,2) NOT NULL DEFAULT 92.00 COMMENT 'Carga do nobreak/UPS (%)',
    ldr_atual INT NOT NULL DEFAULT 320 COMMENT 'Última leitura do sensor LDR (0..1023)',
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. Tabela de Telemetria e Consumo dos Postes (Passo 3 - Histórico SCADA)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS telemetria_postes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    torre_id INT NOT NULL,
    pino_digital INT NOT NULL COMMENT 'Porta digital do Arduino (8..12)',
    nivel_pwm TINYINT UNSIGNED NOT NULL COMMENT 'Intensidade de brilho (0..255)',
    modo_iluminacao ENUM('DESLIGADA', 'ECONOMIA', 'NORMAL', 'CERRACAO') NOT NULL,
    status_operacional ENUM('OPERANDO', 'EM_MANUTENCAO') NOT NULL DEFAULT 'OPERANDO',
    conectado TINYINT(1) NOT NULL DEFAULT 1,
    consumo_watts DECIMAL(7,2) NOT NULL DEFAULT 0.00,
    bateria_pct DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    ldr_valor INT NULL DEFAULT NULL,
    origem_evento VARCHAR(40) NOT NULL DEFAULT 'DASHBOARD_WEB' COMMENT 'DASHBOARD_WEB, CASCATA, ARDUINO_IOT, CONTINGENCIA',
    registrado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_telemetria_torre
        FOREIGN KEY (torre_id) REFERENCES torres(id)
        ON UPDATE CASCADE ON DELETE CASCADE,

    INDEX idx_telemetria_torre_data (torre_id, registrado_em),
    INDEX idx_telemetria_pino (pino_digital)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. Tabela de Ordens de Serviço (O.S.)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ordens_servico (
    id INT AUTO_INCREMENT PRIMARY KEY,
    torre_id INT NOT NULL,
    usuario_criador_id INT NOT NULL,
    tecnico_responsavel_id INT NULL DEFAULT NULL,
    prioridade ENUM('BAIXA', 'MEDIA', 'ALTA', 'CRITICA') NOT NULL DEFAULT 'MEDIA',
    status ENUM('ABERTA', 'EM_ANDAMENTO', 'CONCLUIDA', 'CANCELADA') NOT NULL DEFAULT 'ABERTA',
    descricao_problema TEXT NOT NULL,
    laudo_solucao TEXT NULL DEFAULT NULL,
    data_abertura DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_inicio_atendimento DATETIME NULL DEFAULT NULL,
    data_conclusao DATETIME NULL DEFAULT NULL,

    CONSTRAINT fk_os_torre
        FOREIGN KEY (torre_id) REFERENCES torres(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,

    CONSTRAINT fk_os_criador
        FOREIGN KEY (usuario_criador_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,

    CONSTRAINT fk_os_tecnico
        FOREIGN KEY (tecnico_responsavel_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE ON DELETE SET NULL,

    INDEX idx_os_status (status),
    INDEX idx_os_prioridade (prioridade),
    INDEX idx_os_torre (torre_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CARGA DE DADOS DE TESTE (SEED)
-- ============================================================================

-- Senha padrão de teste para todos (hash bcrypt de 'geonex123'):
-- $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
INSERT INTO usuarios (id, nome, email, matricula, senha_hash, role) VALUES
(1, 'Eng. Carlos Mendes', 'carlos.admin@geonex.com.br', 'ADM-001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'ADMIN'),
(2, 'Marcos Souza (CCO)', 'marcos.cco@geonex.com.br',   'OPR-104', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'OPERADOR'),
(3, 'Roberto Lima',       'roberto.tec@geonex.com.br',  'TEC-208', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'TECNICO'),
(4, 'Fernanda Alves',     'fernanda.tec@geonex.com.br', 'TEC-215', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'TECNICO')
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

-- 5 Postes de Iluminação nas 5 portas PWM sequenciais do Arduino Mega: 2, 3, 4, 5 e 6
INSERT INTO torres (id, codigo, setor, pino_pwm, modo_iluminacao, nivel_brilho_atual, status_operacional, conectado, consumo_watts, bateria, ldr_atual) VALUES
(1, 'TR-01', 'Setor Norte - Lavra A',  2,  'NORMAL',    170, 'OPERANDO',      1, 266.67, 94.00, 310),
(2, 'TR-02', 'Setor Norte - Lavra A',  3,  'NORMAL',    170, 'OPERANDO',      1, 266.67, 89.50, 310),
(3, 'TR-03', 'Setor Leste - Rampa B',  4,  'ECONOMIA',   70, 'EM_MANUTENCAO', 1,   0.00, 78.00, 310),
(4, 'TR-04', 'Setor Oeste - Britagem', 5,  'DESLIGADA',   0, 'OPERANDO',      1,   0.00, 97.00, 310),
(5, 'TR-05', 'Setor Leste - Rampa B',  6,  'NORMAL',    170, 'OPERANDO',      1, 266.67, 91.00, 310)
ON DUPLICATE KEY UPDATE
    pino_pwm = VALUES(pino_pwm),
    conectado = VALUES(conectado),
    consumo_watts = VALUES(consumo_watts),
    bateria = VALUES(bateria);

-- Ordens de Serviço de Teste
INSERT INTO ordens_servico (
    id, torre_id, usuario_criador_id, tecnico_responsavel_id,
    prioridade, status, descricao_problema, laudo_solucao,
    data_abertura, data_inicio_atendimento, data_conclusao
) VALUES
(
    101, 3, 2, 3,
    'ALTA', 'EM_ANDAMENTO',
    'Luminária oscilando em modo economia (Pino D10 - PWM 70). Suspeita de aquecimento no driver MOSFET do painel secundário.',
    'Em inspeção no local. Tensão de entrada estável em 24V, testando substituição do módulo PWM.',
    DATE_SUB(NOW(), INTERVAL 3 HOUR),
    DATE_SUB(NOW(), INTERVAL 1 HOUR),
    NULL
),
(
    102, 5, 2, NULL,
    'MEDIA', 'ABERTA',
    'Acúmulo severo de poeira de minério na lente dos refletores após detonação na Rampa B (Pino D12).',
    NULL,
    DATE_SUB(NOW(), INTERVAL 45 MINUTE),
    NULL,
    NULL
),
(
    103, 1, 1, 4,
    'CRITICA', 'CONCLUIDA',
    'Queda abrupta de telemetria e falha de acionamento em modo Cerração (Pino D8 - PWM 255).',
    'Conector RS-485 oxidado na caixa hermética da TR-01. Efetuada limpeza com limpa-contato, crimpagem de novo terminal e teste de carga em 100% PWM com sucesso.',
    DATE_SUB(NOW(), INTERVAL 1 DAY),
    DATE_SUB(NOW(), INTERVAL 22 HOUR),
    DATE_SUB(NOW(), INTERVAL 20 HOUR)
)
ON DUPLICATE KEY UPDATE status = VALUES(status);
