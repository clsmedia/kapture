# Kapture — local dev container.
# Runtime needs zero dependencies: the custom autoloader, no Composer/vendor.
# .env and ./logs are provided at runtime via docker-compose bind mounts.

FROM php:8.4-cli-alpine

WORKDIR /app

# Non-root runtime user (alpine: uid 1000). logs/ must stay writable by this user.
RUN adduser -D -u 1000 app \
    && mkdir -p /app/logs \
    && chown -R app:app /app

USER app

COPY --chown=app:app . /app

EXPOSE 8000

# php -S walks up parent directories to public/index.php for unknown URIs,
# so /capture/*, /kapture/*, /api/v1/* and /admin all hit the front controller.
CMD ["php", "-S", "0.0.0.0:8000", "-t", "/app/public"]
