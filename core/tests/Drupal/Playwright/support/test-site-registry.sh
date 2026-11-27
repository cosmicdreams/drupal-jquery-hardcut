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
  cleanup)
    flock -n -E 75 "../$record" sh -eu -c '
      directory=$1
      db_option=$2
      case "$db_option" in
        --db-url=?*) ;;
        *) exit 1 ;;
      esac
      test -d "$directory" || exit 0
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
      rmdir "$directory"
    ' sh "../$record" "$@" || {
      # A live installer or another sweep still owns this record.
      status=$?
      test "$status" -eq 75 || exit "$status"
    }
    ;;
  *) exit 1 ;;
esac
