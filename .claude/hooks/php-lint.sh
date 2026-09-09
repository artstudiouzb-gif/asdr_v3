#!/bin/bash
# Проверка синтаксиса PHP сразу после правки файла.
#
# Ошибка в скобке иначе всплывает через несколько шагов — на прогоне тестов
# или, хуже, в CI. Здесь она видна в тот же момент и стоит один вызов php -l.
set -uo pipefail

payload=$(cat)
file=$(printf '%s' "$payload" | php -r '
    $in = json_decode(stream_get_contents(STDIN), true);
    $path = $in["tool_input"]["file_path"] ?? "";
    echo is_string($path) ? $path : "";
' 2>/dev/null || true)

case "${file}" in
    *.php) ;;
    *) exit 0 ;;
esac
[ -f "${file}" ] || exit 0

if ! output=$(php -l "${file}" 2>&1); then
    # Код 2 возвращает вывод агенту как замечание, не прерывая работу.
    printf 'Синтаксическая ошибка PHP:\n%s\n' "${output}" >&2
    exit 2
fi
exit 0
