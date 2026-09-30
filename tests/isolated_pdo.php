<?php

declare(strict_types=1);

/**
 * Соединение тестов: каждый сценарий идёт внутри своей транзакции, и раннер
 * откатывает её после сценария.
 *
 * Прежде тесты чистили за собой вручную (DELETE в конце), и то, что не
 * вычистили, доживало до следующего прогона: сценарий, зелёный на чистой базе
 * CI, падал локально на строках, оставленных соседом неделю назад. Порядок
 * файлов (строкой: `111_*` раньше `33_*`) делал такие связи ещё и неочевидными.
 *
 * Транзакция теста **скрыта от кода**: сайт сам открывает транзакции
 * (сохранение меню, блоков, страниц), а PDO не умеет вложенные. Поэтому
 * транзакция кода внутри теста становится точкой сохранения — commit
 * отпускает её, rollBack откатывает к ней, а inTransaction() отвечает так,
 * как ответил бы без теста.
 *
 * DDL (CREATE TABLE, ALTER, TRUNCATE) MySQL фиксирует неявно — транзакция
 * теста на этом кончается, и дальнейшие записи сценария остаются в базе, как
 * было до изоляции. `inTransaction()` драйвера это видит, поэтому следующая
 * транзакция кода открывается уже настоящей, а откат после сценария
 * пропускается.
 */
final class IsolatedPdo extends PDO
{
    /** Раннер включает на время прогона: соединение, открытое внутри теста, сразу получает транзакцию. */
    public static bool $armed = false;

    private bool $outer = false;

    /** @var list<'sp'|'real'> */
    private array $levels = [];

    public function __construct(string $dsn, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options);
        if (self::$armed) {
            $this->beginOuter();
        }
    }

    public function beginOuter(): void
    {
        $this->levels = [];
        if (!parent::inTransaction()) {
            parent::beginTransaction();
        }
        $this->outer = true;
    }

    public function endOuter(): void
    {
        if (parent::inTransaction()) {
            parent::rollBack();
        }
        $this->outer = false;
        $this->levels = [];
    }

    public function beginTransaction(): bool
    {
        if ($this->outer && parent::inTransaction()) {
            $this->exec('SAVEPOINT test_sp_' . count($this->levels));
            $this->levels[] = 'sp';

            return true;
        }
        $this->levels[] = 'real';

        return parent::beginTransaction();
    }

    public function commit(): bool
    {
        // Точку сохранения не отпускаем: RELEASE — отдельный оператор, и MySQL
        // после него отвечает lastInsertId() = 0, а код читает id записи как раз
        // после commit(). Неотпущенная точка ничему не мешает — её снимет откат
        // сценария, а одноимённая следующая просто заменит её.
        if (array_pop($this->levels) === 'sp') {
            return true;
        }

        return parent::commit();
    }

    public function rollBack(): bool
    {
        if (array_pop($this->levels) === 'sp') {
            $this->quietly('ROLLBACK TO SAVEPOINT test_sp_' . count($this->levels));

            return true;
        }

        return parent::rollBack();
    }

    public function inTransaction(): bool
    {
        if ($this->levels !== []) {
            return true;
        }

        return $this->outer ? false : parent::inTransaction();
    }

    /**
     * Точка сохранения пропадает, если посреди транзакции кода случился DDL
     * (неявная фиксация). Это не ошибка кода под тестом — молчим только о ней.
     */
    private function quietly(string $sql): void
    {
        try {
            $this->exec($sql);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) !== 1305) {
                throw $e;
            }
        }
    }
}
