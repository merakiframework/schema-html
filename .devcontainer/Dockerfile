# PHP 8.5 and Composer: everything the tests and the examples need.
#
# The base images are arguments only so a mirror can stand in for Docker Hub; the defaults are
# the official images.
ARG PHP_IMAGE=php:8.5-cli
ARG COMPOSER_IMAGE=composer:2

FROM ${COMPOSER_IMAGE} AS composer

FROM ${PHP_IMAGE}

# The PHP image already has every extension composer.lock asks for. Composer needs git and unzip
# to fetch packages.
RUN apt-get update \
	&& apt-get install -y --no-install-recommends git unzip \
	&& rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

# Work as a user rather than root, so files written to the workspace are yours. On Linux, Dev
# Containers changes this user's UID to match your own when the container starts.
ARG USERNAME=dev
ARG USER_UID=1000
RUN useradd --create-home --shell /bin/bash --uid "${USER_UID}" "${USERNAME}"
USER ${USERNAME}
