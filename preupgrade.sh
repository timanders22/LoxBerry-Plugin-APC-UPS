#!/bin/sh

# To use important variables from command line use the following code:
PDIR=$3       # Third argument is Plugin installation folder
#LBHOMEDIR=$5 # Comes from /etc/environment now.

PLOG=$LBPLOG/$PDIR
PCONFIG=$LBPCONFIG/$PDIR

echo "<INFO> Backing up existing config files"
mkdir /tmp/${PDIR}.SAVE
cp -v -r $PCONFIG/* /tmp/${PDIR}.SAVE/ 2>/dev/null

# Laufenden Dienst anhalten, damit er nicht in die neue Fassung hineinlaeuft.
#
# Frueher stand hier  pkill -f apc_service.py.  Das erschlaegt jeden Prozess,
# in dessen Befehlszeile die Zeichenkette vorkommt - auch einen offenen
# Editor oder ein Sicherungsskript, das gerade den Ordner durchsucht. Der
# Dienst schreibt seine Prozessnummer in eine Datei; danach wird punktgenau
# beendet, mit zehn Sekunden Zeit zum Aufraeumen.
PIDF=/run/shm/apc_ups_ng.pid
[ -f "$PIDF" ] || PIDF="$PLOG/apc_ups_ng.pid"
if [ -f "$PIDF" ]; then
    P=$(cat "$PIDF" 2>/dev/null)
    if [ -n "$P" ] && kill -0 "$P" 2>/dev/null; then
        kill "$P" 2>/dev/null
        i=0
        while [ $i -lt 10 ] && kill -0 "$P" 2>/dev/null; do
            sleep 1
            i=$((i + 1))
        done
        # Nur hart beenden, wenn er noch lebt UND es wirklich unser Dienst ist.
        if kill -0 "$P" 2>/dev/null && grep -qa "apc_service.py" "/proc/$P/cmdline" 2>/dev/null; then
            kill -9 "$P" 2>/dev/null
        fi
    fi
    rm -f "$PIDF"
    echo "<INFO> Laufender Dienst angehalten."
fi

exit 0
