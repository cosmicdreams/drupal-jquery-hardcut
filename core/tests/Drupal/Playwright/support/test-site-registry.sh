#!/bin/sh
# Run inside the same container for both install and cleanup. The record
# directory is created by the fixture and also serves as the lock inode.
set -eu

operation=$1
record=$2
shift 2

case "$operation" in
  install)
    # The timeout signals the installer process group inside the container.
    # Keep the lock until the group has been stopped, even if DDEV disconnects.
    exec flock -n -E 75 "../$record" sh -eu -c '
      record=$1
      shift
      test -d "../$record"
      timeout --verbose --kill-after=2s 40s php ./scripts/test-site.php install --json \
        "--fixture-prefix-file=$record/pending" "$@"
      mv "../$record/pending" "../$record/complete"
    ' sh "$record" "$@"
    ;;
  cleanup|recover)
    lock_wait=-n
    if [ "$operation" = cleanup ]; then
      # Allow the 40-second installer deadline and its 2-second kill grace.
      lock_wait=-w45
    fi
    directory="../$record"
    db_option=$1
    case "$db_option" in
      --db-url=?*) ;;
      *) exit 1 ;;
    esac

    # Open the directory read-only so a concurrent removal cannot recreate it.
    if flock "$lock_wait" -E 75 9; then
      for state in complete pending; do
        file="$directory/$state"
        if [ -f "$file" ]; then
          prefix=$(cat "$file")
          case "$prefix" in
            test[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]) ;;
            *)
              echo "Refusing to tear down an invalid test site prefix in $file." >&2
              exit 1
              ;;
          esac
          php ./scripts/test-site.php tear-down "$prefix" "$db_option"
          rm "$file"
        fi
      done
      test ! -d "$directory" || rmdir "$directory"
    else
      status=$?
      if [ "$status" -eq 75 ]; then
        if [ "$operation" = recover ]; then
          exit 0
        fi
        echo "Timed out waiting for the Playwright test site record lock: $record" >&2
      fi
      exit "$status"
    fi 9<"$directory"
    ;;
  *) exit 1 ;;
esac
