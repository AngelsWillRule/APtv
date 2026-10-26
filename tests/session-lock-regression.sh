#!/usr/bin/env bash
set -euo pipefail

# Run a slow authenticated endpoint and, with the same session cookie, probe a
# cheap endpoint concurrently.  The slow endpoint is deliberately generic:
# use a local/test endpoint or a known cache path, not a provider refresh.
: "${COOKIE_FILE:?Set COOKIE_FILE to a curl cookie-jar or Cookie header file}"
: "${SLOW_URL:?Set SLOW_URL to the endpoint that performs the slow operation}"

NAV_URL=${NAV_URL:-http://127.0.0.1/aptv/api/vod?view=nav}
SLOW_METHOD=${SLOW_METHOD:-GET}
SLOW_DATA_FILE=${SLOW_DATA_FILE:-}
SLOW_HEADER=${SLOW_HEADER:-}
SAMPLES=${SAMPLES:-20}
INTERVAL=${INTERVAL:-0.2}
THRESHOLD_MS=${THRESHOLD_MS:-200}
MIN_SLOW_MS=${MIN_SLOW_MS:-0}
ASSERT_EXPECTED=${ASSERT_EXPECTED:-pass}

case "$ASSERT_EXPECTED" in
    pass|fail) ;;
    *) echo "ASSERT_EXPECTED must be pass or fail" >&2; exit 2 ;;
esac

test_dir=$(mktemp -d /tmp/openap-session-lock.XXXXXX)
trap 'rm -rf "$test_dir"' EXIT

slow_args=(curl -sS -b "$COOKIE_FILE" -o "$test_dir/slow.body" -w '%{http_code} %{time_total}\n' -X "$SLOW_METHOD")
if [[ -n "$SLOW_DATA_FILE" ]]; then
    slow_args+=(--data-binary "@$SLOW_DATA_FILE")
fi
if [[ -n "$SLOW_HEADER" ]]; then
    slow_args+=(-H "$SLOW_HEADER")
fi
slow_args+=("$SLOW_URL")

("${slow_args[@]}" >"$test_dir/slow.result") &
slow_pid=$!

for _ in $(seq 1 "$SAMPLES"); do
    curl -sS -o /dev/null -b "$COOKIE_FILE" -w '%{http_code} %{time_total}\n' "$NAV_URL" || true
    sleep "$INTERVAL"
done >"$test_dir/nav.results"

wait "$slow_pid"

awk '{print $1, $2 * 1000}' "$test_dir/nav.results" | tee "$test_dir/nav.ms"
max_ms=$(awk 'BEGIN{max=0} $2>max{max=$2} END{printf "%.3f", max}' "$test_dir/nav.ms")
slow_result=$(tr '\n' ' ' <"$test_dir/slow.result")
slow_ms=$(awk '{printf "%.3f", $2 * 1000}' "$test_dir/slow.result")

echo "slow=$slow_result"
echo "slow_ms=$slow_ms min_slow_ms=$MIN_SLOW_MS"
echo "nav_max_ms=$max_ms threshold_ms=$THRESHOLD_MS expected=$ASSERT_EXPECTED"

if awk -v elapsed="$slow_ms" -v minimum="$MIN_SLOW_MS" 'BEGIN { exit !(elapsed >= minimum) }'; then
    :
else
    echo "slow endpoint completed below MIN_SLOW_MS" >&2
    exit 1
fi

assertion_passed=0
if awk -v max="$max_ms" -v threshold="$THRESHOLD_MS" 'BEGIN { exit !(max <= threshold) }'; then
    assertion_passed=1
fi

if [[ "$ASSERT_EXPECTED" == pass ]]; then
    (( assertion_passed == 1 ))
else
    (( assertion_passed == 0 ))
fi
