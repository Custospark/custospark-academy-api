#!/usr/bin/env python3
"""Run a command on the Custospark Academy server via SSH.

Reads credentials from Backend/.env (SSH_DEPLOY_*) - never prints them.
Usage:
    python scripts/ssh_run.py "<command>"
"""
import os
import sys

import paramiko


def load_env():
    env_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".env")
    values = {}
    with open(env_path, encoding="utf-8", errors="ignore") as fh:
        for line in fh:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, _, val = line.partition("=")
            key = key.strip()
            val = val.strip()
            if key.startswith("SSH_DEPLOY_"):
                values[key] = val
    return values


def main():
    if len(sys.argv) < 2:
        print("usage: ssh_run.py '<command>'")
        sys.exit(2)

    env = load_env()
    host = env.get("SSH_DEPLOY_HOST", "")
    port = int(env.get("SSH_DEPLOY_PORT", "22"))
    user = env.get("SSH_DEPLOY_USER", "")
    password = env.get("SSH_DEPLOY_PASSWORD", "")

    if not host or not user or not password:
        print("SSH_DEPLOY_* env vars incomplete in Backend/.env")
        sys.exit(1)

    command = sys.argv[1]

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        client.connect(host, port=port, username=user, password=password, timeout=20)
        stdin, stdout, stderr = client.exec_command(command, timeout=120)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        out = out.encode(sys.stdout.encoding or "utf-8", errors="replace").decode(sys.stdout.encoding or "utf-8", errors="replace")
        if out:
            print(out)
        if err:
            print("[stderr]")
            print(err)
        sys.exit(stdout.channel.recv_exit_status())
    except Exception as exc:  # noqa: BLE001
        print(f"[ssh error] {exc}")
        sys.exit(1)
    finally:
        client.close()


if __name__ == "__main__":
    main()