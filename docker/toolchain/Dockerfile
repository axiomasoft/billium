FROM php:8.4-cli-bookworm

ARG DART_VERSION=3.13.5

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip python3 python3-venv ca-certificates curl \
        libicu-dev libgmp-dev libzip-dev \
    && docker-php-ext-install bcmath intl gmp zip \
    && apt-get clean

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN curl -fsSL -o /tmp/dart.zip \
        "https://storage.googleapis.com/dart-archive/channels/stable/release/${DART_VERSION}/sdk/dartsdk-linux-x64-release.zip" \
    && unzip -q /tmp/dart.zip -d /opt \
    && chmod -R a+rX /opt/dart-sdk

COPY requirements.txt /tmp/requirements.txt
RUN python3 -m venv /opt/tooling \
    && /opt/tooling/bin/pip install --no-cache-dir -r /tmp/requirements.txt

RUN mkdir -p /tmp/home /tmp/composer /tmp/pub-cache \
    && chmod 1777 /tmp/home /tmp/composer /tmp/pub-cache

ENV PATH=/opt/tooling/bin:/opt/dart-sdk/bin:$PATH

WORKDIR /workspace
