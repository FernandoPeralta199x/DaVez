#!/usr/bin/env bash
set -euo pipefail

MODE="${1:---static}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

required_commands=(php node)
for command_name in "${required_commands[@]}"; do
  command -v "$command_name" >/dev/null 2>&1 || {
    echo "Comando obrigatório ausente: $command_name" >&2
    exit 1
  }
done

required_extensions=(json openssl session)
if [[ "$MODE" == "--production" ]]; then
  required_extensions+=(mysqli mbstring)
elif [[ "$MODE" != "--static" ]]; then
  echo "Uso: scripts/validate-local.sh [--static|--production]" >&2
  exit 2
fi

for extension in "${required_extensions[@]}"; do
  php -r "exit(extension_loaded('$extension') ? 0 : 1);" || {
    echo "Extensão PHP obrigatória ausente: $extension" >&2
    exit 1
  }
done

echo "==> Lint PHP"
while IFS= read -r file; do
  php -l "$file" >/dev/null
done < <(find . -type f -name '*.php' \
  ! -path './artifacts/*' ! -path './logs/*' ! -path './reports/*' | sort)

echo "==> Testes PHP"
while IFS= read -r test_file; do
  php -d display_errors=1 -d error_reporting=E_ALL "$test_file"
done < <(find tests -type f -name '*.php' | sort)

echo "==> Testes Node"
while IFS= read -r test_file; do
  node --check "$test_file" >/dev/null
  node "$test_file"
done < <(find tests -type f -name '*.js' | sort)

node --check service-worker.js >/dev/null
php -r "json_decode(file_get_contents('manifest.json'), true, 512, JSON_THROW_ON_ERROR);"

echo "Validação $MODE concluída com sucesso."
