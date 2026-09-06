#!/usr/bin/env python3
"""
Compose and upload the server .env for a Custospark Academy environment via
SFTP, WITHOUT ever printing credential values.

Usage:
  python scripts/push_env.py staging
  python scripts/push_env.py production

It reads:
  - Backend/.env.example  -> base config (served as-is, defaults preserved)
  - Backend/.env          -> the commented DB blocks marked #Production / #Staging
                             plus the PESAPAL_* payment-gateway block
  - Backend/.env          -> SSH_DEPLOY_* connection creds (same as ssh_run.py)

Output is masked: only file path, byte count, and a checkbox. Secrets never
reach stdout.
"""
import socket
import sys

import paramiko

APP_DIRS = {
    'staging': '/home/u214605677/domains/academy-staging-api.custospark.com',
    'production': '/home/u214605677/domains/academy-api.custospark.com',
}
APP_URL = {
    'staging': 'https://academy-staging-api.custospark.com',
    'production': 'https://academy-api.custospark.com',
}
FRONTEND_URL = {
    'staging': 'https://academy-staging.custospark.com',
    'production': 'https://academy.custospark.com',
}


def load_env(path):
    out = {}
    with open(path, 'r', encoding='utf-8') as fh:
        for raw in fh:
            line = raw.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            key, _, value = line.partition('=')
            out[key.strip()] = value.strip()
    return out


def parse_db_block(env_text, marker):
    """Return {DB_*: value} from the commented block under `marker`."""
    block = {}
    in_block = False
    for raw in env_text.split('\n'):
        line = raw.strip()
        if line.startswith('#') and not line.startswith('# DB_'):
            in_block = line == f'#{marker}'
            continue
        if in_block and line.startswith('# DB_') and '=' in line:
            key, _, value = line[1:].strip().partition('=')
            block[key.strip()] = value.strip()
        elif line and not line.startswith('#') and block:
            break
    return block


def q(value):
    """Quote an env value for dotenv so '=' , spaces, or quotes can never
    corrupt the file even if a wrapped secret contains them."""
    escaped = str(value).replace('\\', '\\\\').replace('"', '\\"')
    return f'"{escaped}"'


def build_content(env_name):
    """Compose a complete, self-contained .env for the target environment.
    Every value is quoted (safe with '=', spaces, quotes), never merged from
    .env.example so a quirky example line can never break the server file."""
    env_path = 'C:/Dev/CustosparkAcademy/Backend/.env'
    with open(env_path, 'r', encoding='utf-8') as fh:
        env_text = fh.read()
    secrets = load_env(env_path)

    marker = 'Production' if env_name == 'production' else 'Staging'
    db = parse_db_block(env_text, marker)
    missing = [k for k in ('DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD') if k not in db]
    if missing:
        raise SystemExit(f"ERROR: DB block #{marker} missing {missing} - check Backend/.env")

    lines = [
        'APP_NAME="Custospark Academy"',
        f'APP_ENV={q(env_name)}',
        'APP_KEY=',
        'APP_DEBUG="false"',
        f'APP_URL={q(APP_URL[env_name])}',
        f'FRONTEND_URL={q(FRONTEND_URL[env_name])}',
        f'CERTIFICATE_VERIFY_URL={q(APP_URL[env_name])}',
        'APP_LOCALE="en"',
        'APP_FALLBACK_LOCALE="en"',
        'APP_FAKER_LOCALE="en_US"',
        'APP_MAINTENANCE_DRIVER="file"',
        'BCRYPT_ROUNDS="12"',
        'LOG_CHANNEL="stack"',
        'LOG_LEVEL="warning"',
        'DB_CONNECTION="mysql"',
        'DB_HOST="127.0.0.1"',
        'DB_PORT="3306"',
        f'DB_DATABASE={q(db["DB_DATABASE"])}',
        f'DB_USERNAME={q(db["DB_USERNAME"])}',
        f'DB_PASSWORD={q(db["DB_PASSWORD"])}',
        'SESSION_DRIVER="database"',
        'SESSION_LIFETIME="120"',
        'SESSION_ENCRYPT="false"',
        'SESSION_PATH="/"',
        'SESSION_DOMAIN="null"',
        'BROADCAST_CONNECTION="log"',
        'FILESYSTEM_DISK="local"',
        'QUEUE_CONNECTION="database"',
        'CACHE_STORE="database"',
        'MAIL_MAILER="smtp"',
        'MAIL_FROM_ADDRESS="academy@custospark.com"',
        'MAIL_FROM_NAME="Custospark Academy"',
        '',
    ]
    # The real mailbox config already lives in Backend/.env - the same address
    # is correct across all environments, so carry the whole MAIL_* block
    # through (masked in transit, never printed). Applies over the defaults.
    for k in ('MAIL_MAILER', 'MAIL_SCHEME', 'MAIL_HOST', 'MAIL_PORT',
              'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_ENCRYPTION',
              'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME'):
        if k in secrets and secrets[k]:
            value = secrets[k]
            if k == 'MAIL_MAILER' and value.lower() in ('log', 'array', 'smtp'):
                pass
            line = f'{k}={q(value)}'
            replaced = False
            for idx, existing in enumerate(lines):
                if existing.startswith(k + '='):
                    lines[idx] = line
                    replaced = True
                    break
            if not replaced:
                lines.append(line)
    for k in ('PESAPAL_ENVIRONMENT', 'PESAPAL_ENABLED', 'PESAPAL_BYPASS',
              'PESAPAL_SANDBOX_CONSUMER_KEY', 'PESAPAL_SANDBOX_CONSUMER_SECRET',
              'PESAPAL_PRODUCTION_CONSUMER_KEY', 'PESAPAL_PRODUCTION_CONSUMER_SECRET',
              'PESAPAL_IPN_ID'):
        if k in secrets and secrets[k]:
            lines.append(f'{k}={q(secrets[k])}')
    # Payment gateway is LIVE on both environments (sandbox is local dev only).
    # Callback + IPN URLs must be public per-env endpoints - the config-file
    # localhost defaults would send payers (and PesaPal) nowhere.
    forced = {
        'PESAPAL_ENABLED': 'true',
        'PESAPAL_BYPASS': 'false',
        'PESAPAL_ENVIRONMENT': 'production',
        'PESAPAL_CALLBACK_URL': f"{APP_URL[env_name]}/api/v1/payments/pesapal/callback",
        'PESAPAL_IPN_URL': f"{APP_URL[env_name]}/api/v1/payments/pesapal/ipn",
        'PESAPAL_TOKEN_CACHE_TTL': '600',
    }
    for k, v in forced.items():
        line = f'{k}={q(v)}'
        replaced = False
        for idx, existing in enumerate(lines):
            if existing.startswith(k + '='):
                lines[idx] = line
                replaced = True
                break
        if not replaced:
            lines.append(line)
    return '\n'.join(lines) + '\n'


def main():
    argv = sys.argv[1:]
    render_to = None
    if argv and argv[0] == '--render':
        render_to = argv[1] if len(argv) > 1 else 'staging.env'
        argv = argv[2:]
    env_name = argv[0] if argv else 'staging'
    if env_name not in APP_DIRS:
        raise SystemExit('Usage: python scripts/push_env.py [--render <path>] [staging|production]')

    content = build_content(env_name)
    if render_to:
        with open(render_to, 'w', encoding='utf-8', newline='\n') as fh:
            fh.write(content)
        print(f'OK  {env_name}: rendered to {render_to} ({len(content.encode("utf-8"))} bytes, creds masked)')
        return

    creds = load_env('C:/Dev/CustosparkAcademy/Backend/.env')

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(
        hostname=creds['SSH_DEPLOY_HOST'],
        port=int(creds['SSH_DEPLOY_PORT']),
        username=creds['SSH_DEPLOY_USER'],
        password=creds['SSH_DEPLOY_PASSWORD'],
        timeout=30,
    )
    sftp = client.open_sftp()
    target = f"{APP_DIRS[env_name]}/.env.{env_name}"
    with sftp.file(target, 'w') as fh:
        fh.write(content.encode('utf-8'))
    _stdin, stdout, _stderr = client.exec_command(
        f'mv -f {APP_DIRS[env_name]}/.env.{env_name} {APP_DIRS[env_name]}/.env && '
        f'chmod 600 {APP_DIRS[env_name]}/.env')
    stdout.channel.recv_exit_status()
    count = content.count('=')
    print(f'OK  {env_name}: uploaded .env.{env_name} -> renamed to .env '
          f'({len(content.encode("utf-8"))} bytes, {count} keys, creds masked)')
    sftp.close()
    client.close()


if __name__ == '__main__':
    main()