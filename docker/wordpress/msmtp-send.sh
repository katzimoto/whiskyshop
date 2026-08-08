#!/bin/sh
# Ternate msmtp config from environment, then send. Used as PHP sendmail_path.
# Prod: SMTP_USER/SMTP_PASSWORD set -> authenticated TLS relay (Brevo etc).
# Dev : SMTP_USER empty -> plain local relay (mailpit).

CONF="$(mktemp)"
trap 'rm -f "$CONF"' EXIT

{
	echo "host ${SMTP_HOST:-mailpit}"
	echo "port ${SMTP_PORT:-1025}"
	echo "from ${SMTP_FROM:-noreply@omef.test}"

	if [ -n "${SMTP_USER:-}" ]; then
		echo "auth on"
		echo "tls on"
		echo "tls_starttls on"
		echo "tls_certcheck on"
		echo "tls_trust_file /etc/ssl/certs/ca-certificates.crt"
		echo "user ${SMTP_USER}"
		echo "password ${SMTP_PASSWORD}"
	else
		echo "auth off"
		echo "tls off"
	fi
} > "$CONF"

chmod 600 "$CONF"
exec /usr/bin/msmtp -C "$CONF" "$@"