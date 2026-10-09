"""Portable subprocess output, argument and timeout regression fixture."""
import json
import signal
import sys
import time

mode = sys.argv[1]
if mode in ("timeout", "ignore-term"):
    if mode == "ignore-term" and sys.platform != "win32":
        signal.signal(signal.SIGTERM, signal.SIG_IGN)
    time.sleep(30)
elif mode == "oversized":
    print("x" * 20000)
elif mode == "nonzero":
    sys.exit(3)
else:
    sys.stderr.write("discarded diagnostic\n" * 10000)
    print(json.dumps({"argument": sys.argv[2]}))
