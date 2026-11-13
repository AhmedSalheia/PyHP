# syntax=docker/dockerfile:1
#
# A base image for PHP apps that use PyHP: the official PHP image plus Python,
# uv and Composer. PyHP itself stays a normal Composer dependency of your app:
#
#   FROM ghcr.io/pyhp-php/pyhp:php8.5-cli
#   WORKDIR /app
#   COPY composer.json composer.lock pyhp.lock* ./
#   RUN composer install --no-dev --no-interaction && vendor/bin/pyhp setup
#   COPY . .
#
# Built for linux/amd64 and linux/arm64 by .github/workflows/docker.yml.

ARG PHP_VERSION=8.5
ARG VARIANT=cli

FROM ghcr.io/astral-sh/uv:0.9.5 AS uv
FROM composer:2 AS composer

FROM php:${PHP_VERSION}-${VARIANT}-trixie

LABEL org.opencontainers.image.title="PyHP" \
      org.opencontainers.image.description="PHP, Python, uv and Composer, ready for apps that use Python libraries through PyHP" \
      org.opencontainers.image.source="https://github.com/pyhp-php/pyhp" \
      org.opencontainers.image.url="https://github.com/pyhp-php/pyhp" \
      org.opencontainers.image.licenses="MIT"

# Python (Debian 13 ships 3.13) with venv and pip, so `pyhp setup` works with or
# without uv; git and unzip for Composer.
RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 python3-venv python3-pip git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=uv /uv /uvx /usr/local/bin/
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# uv: copy files into venvs instead of hard-linking across image layers.
# Composer: images build as root, which is expected here.
ENV UV_LINK_MODE=copy \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
