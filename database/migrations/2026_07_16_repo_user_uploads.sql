-- Загрузка файлов пользователями портала /repo с премодерацией: статус записи
-- (pending — ждёт одобрения администратора, approved — виден на портале)
-- и автор — пользователь портала.
ALTER TABLE repo_files
    ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'approved' AFTER download_count,
    ADD COLUMN uploaded_by_repo_user INT UNSIGNED NULL AFTER uploaded_by,
    ADD KEY idx_repo_files_status (status),
    ADD CONSTRAINT fk_repo_files_repo_user FOREIGN KEY (uploaded_by_repo_user) REFERENCES repo_users (id) ON DELETE SET NULL;

-- Организация пользователя портала (к логину/имени/email).
ALTER TABLE repo_users
    ADD COLUMN organization VARCHAR(190) NOT NULL DEFAULT '' AFTER full_name;
