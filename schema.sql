CREATE DATABASE IF NOT EXISTS mail_proxy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mail_proxy;

-- 1. Таблица referents
CREATE TABLE IF NOT EXISTS referents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    local_inbox VARCHAR(255) UNIQUE NOT NULL,
    local_outbox VARCHAR(255) UNIQUE NOT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Таблица clients
CREATE TABLE IF NOT EXISTS clients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    referent_id INT UNSIGNED NOT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (referent_id) REFERENCES referents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Таблица external_accounts
CREATE TABLE IF NOT EXISTS external_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    referent_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    username VARCHAR(255) DEFAULT NULL,
    auth_type ENUM('plain','oauth2') DEFAULT 'plain',
    provider VARCHAR(50) DEFAULT NULL,
    password_enc TEXT DEFAULT NULL,
    imap_host VARCHAR(255) NOT NULL,
    imap_port INT UNSIGNED DEFAULT 993,
    imap_encryption ENUM('none','ssl','tls') DEFAULT 'ssl',
    smtp_host VARCHAR(255) NOT NULL,
    smtp_port INT UNSIGNED DEFAULT 587,
    smtp_encryption ENUM('none','ssl','tls') DEFAULT 'tls',
    client_id VARCHAR(255) DEFAULT NULL,
    client_secret_enc TEXT DEFAULT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (referent_id) REFERENCES referents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Таблица oauth_tokens
CREATE TABLE IF NOT EXISTS oauth_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    access_token_enc TEXT NOT NULL,
    refresh_token_enc TEXT DEFAULT NULL,
    scope TEXT DEFAULT NULL,
    token_type VARCHAR(50) DEFAULT 'Bearer',
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES external_accounts(id) ON DELETE CASCADE,
    UNIQUE KEY uq_account_id (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Таблица oauth_providers
CREATE TABLE IF NOT EXISTS oauth_providers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    auth_endpoint VARCHAR(255) NOT NULL,
    token_endpoint VARCHAR(255) NOT NULL,
    scopes TEXT NOT NULL,
    extra_params_json TEXT DEFAULT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Добавление начальных записей в oauth_providers
INSERT INTO oauth_providers (code, name, auth_endpoint, token_endpoint, scopes, extra_params_json, active)
VALUES
('google', 'Google', 'https://accounts.google.com/o/oauth2/v2/auth', 'https://oauth2.googleapis.com/token', 'https://mail.google.com/', '{"access_type":"offline","prompt":"consent"}', 1),
('yandex', 'Yandex', 'https://oauth.yandex.ru/authorize', 'https://oauth.yandex.ru/token', 'mail:imap_full mail:smtp', '{"force_confirm":"yes"}', 1),
('microsoft', 'Microsoft', 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize', 'https://login.microsoftonline.com/common/oauth2/v2.0/token', 'https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/SMTP.Send offline_access', '{}', 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    auth_endpoint = VALUES(auth_endpoint),
    token_endpoint = VALUES(token_endpoint),
    scopes = VALUES(scopes),
    extra_params_json = VALUES(extra_params_json),
    active = VALUES(active),
    updated_at = CURRENT_TIMESTAMP;