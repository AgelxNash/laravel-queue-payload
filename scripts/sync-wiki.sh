#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOCS_DIR="${ROOT_DIR}/docs"
TARGET_DIR="${1:-${ROOT_DIR}/.wiki}"

if [[ ! -d "${DOCS_DIR}" ]]; then
  echo "[wiki-sync] docs directory not found: ${DOCS_DIR}" >&2
  exit 1
fi

rm -rf "${TARGET_DIR}"
mkdir -p "${TARGET_DIR}"

# Копируем все markdown-документы из docs/
cp "${DOCS_DIR}"/*.md "${TARGET_DIR}/"

# Home страница GitHub Wiki
cat > "${TARGET_DIR}/Home.md" <<'EOF'
# Laravel Queue Payload Wiki

Добро пожаловать в wiki документацию пакета.

## Разделы

- [Индекс документации](README)
- [Архитектура](architecture)
- [Конфигурация](configuration)
- [Использование: RPC](usage-rpc)
- [Использование: Events](usage-events)
- [Безопасность](security)
- [Observability](observability)
- [Тестирование](testing)
- [Troubleshooting](troubleshooting)
- [Миграция](migration)
EOF

# Sidebar для GitHub Wiki
cat > "${TARGET_DIR}/_Sidebar.md" <<'EOF'
## Навигация

- [Home](Home)
- [Индекс](README)
- [Архитектура](architecture)
- [Конфигурация](configuration)
- [RPC](usage-rpc)
- [Events](usage-events)
- [Безопасность](security)
- [Observability](observability)
- [Тестирование](testing)
- [Troubleshooting](troubleshooting)
- [Миграция](migration)
EOF

# Нормализуем ссылки из docs/* для wiki-контекста
for f in "${TARGET_DIR}"/*.md; do
  sed -i \
    -e 's#](../README.md)#](Home)#g' \
    -e 's#](../README.md#](Home#g' \
    "$f"
done

echo "[wiki-sync] Wiki files generated in: ${TARGET_DIR}"
ls -1 "${TARGET_DIR}" | sed 's/^/ - /'
