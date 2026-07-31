#!/usr/bin/env sh

set -eu

scenario="$(dirname "$0")/pty-scenario.php"
workdir=$(mktemp -d)
trap 'rm -rf "$workdir"' EXIT HUP INT TERM

# BSD reports PENDIN (0x20000000) and FLUSHO (0x00800000) in lflag. Both are
# kernel bookkeeping about input still to be read, not settings anyone chose, so
# they say nothing about whether the terminal was restored. GNU stty -g has no
# labelled fields and needs no such treatment.
settings() {
    raw=$(stty -g)
    lflag=$(printf '%s' "$raw" | tr ':' '\n' | sed -n 's/^lflag=//p')

    if [ -z "$lflag" ]; then
        printf '%s' "$raw"
        return
    fi

    printf '%s' "$raw" | sed "s/lflag=$lflag/lflag=$(printf '%x' "$((0x$lflag & ~0x20800000))")/"
}

before=$(settings)

assert_restored() {
    after=$(settings)
    if [ "$before" != "$after" ]; then
        printf 'terminal settings were not restored\n  before: %s\n   after: %s\n' "$before" "$after" >&2
        exit 1
    fi
}

echo "PTY: normal exit" >&2
php "$scenario" normal
assert_restored

echo "PTY: exceptional exit" >&2
if php "$scenario" exception 2>"$workdir/exception.log"; then
    echo "exception scenario unexpectedly succeeded" >&2
    exit 1
fi
assert_restored

echo "PTY: inherited non-blocking input" >&2
php "$scenario" blocking
assert_restored

echo "PTY: redirected output rejection" >&2
if php "$scenario" normal >"$workdir/redirected-output"; then
    echo "redirected stdout unexpectedly accepted as an interactive terminal" >&2
    exit 1
fi
assert_restored

echo "PTY: non-TTY input rejection" >&2
if php "$scenario" normal </dev/null; then
    echo "non-TTY stdin unexpectedly accepted as an interactive terminal" >&2
    exit 1
fi
assert_restored

await_file() {
    file=$1
    pid=$2
    attempts=0
    while test ! -f "$file"; do
        kill -0 "$pid"
        attempts=$((attempts + 1))
        if test "$attempts" -ge 250; then
            kill -KILL "$pid" 2>/dev/null || true
            echo "timed out waiting for PTY scenario readiness" >&2
            exit 1
        fi
        sleep 0.02
    done
}

await_exit() {
    pid=$1
    label=$2
    attempts=0
    while kill -0 "$pid" 2>/dev/null; do
        attempts=$((attempts + 1))
        if test "$attempts" -ge 250; then
            kill -KILL "$pid" 2>/dev/null || true
            wait "$pid" 2>/dev/null || true
            echo "timed out waiting for $label scenario to exit" >&2
            exit 1
        fi
        sleep 0.02
    done
}

run_and_signal() {
    signal=$1
    echo "PTY: SIG$signal exit" >&2
    ready="$workdir/ready-$signal"
    ELMPHANT_PTY_READY="$ready" php "$scenario" wait </dev/tty &
    pid=$!

    await_file "$ready" "$pid"

    kill "-$signal" "$pid"
    await_exit "$pid" "SIG$signal"
    wait "$pid"
    assert_restored
}

run_and_signal INT
run_and_signal TERM

echo "PTY: suspend and resume" >&2
ready="$workdir/ready-suspend"
ELMPHANT_PTY_READY="$ready" php "$scenario" wait </dev/tty &
pid=$!
await_file "$ready" "$pid"

kill -TSTP "$pid"
state=
attempts=0
while test "$state" != "T"; do
    kill -0 "$pid"
    # BSD appends flag characters to the state, so 'T' arrives as 'T+'.
    state=$(ps -o state= -p "$pid" | tr -d ' ' | cut -c1)
    attempts=$((attempts + 1))
    if test "$attempts" -ge 250; then
        kill -KILL "$pid" 2>/dev/null || true
        echo "timed out waiting for suspended process" >&2
        exit 1
    fi
    sleep 0.02
done
assert_restored

kill -CONT "$pid"
sleep 0.05
kill -INT "$pid"
await_exit "$pid" "suspend/resume"
wait "$pid"
assert_restored

echo "PTY: same-size resize" >&2
ready="$workdir/ready-resize"
result="$workdir/resize-result"
ELMPHANT_PTY_READY="$ready" ELMPHANT_PTY_RESULT="$result" php "$scenario" resize </dev/tty &
pid=$!
await_file "$ready" "$pid"

kill -WINCH "$pid"
await_exit "$pid" "same-size resize"
wait "$pid"
test "$(cat "$result")" = "same-size-resize-rendered"
assert_restored
