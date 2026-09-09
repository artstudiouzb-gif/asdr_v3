-- Зашифрованный envelope длиннее исходного секрета.
ALTER TABLE users MODIFY totp_secret TEXT NULL;
ALTER TABLE repo_users MODIFY totp_secret TEXT NULL;
ALTER TABLE webhooks MODIFY secret TEXT NULL;
