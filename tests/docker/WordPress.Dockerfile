ARG WP_CLI_IMAGE=wordpress:cli-2.12.0-php8.4@sha256:13d152baa3c9111882d05e8ef4c32b4c84019b1bf7bf66b042c6b45e7aaba81d
ARG WORDPRESS_IMAGE=wordpress:7.1-php8.4-apache@sha256:dd1d6ff323bae668ebbfb0fce91042e1af7ee8d1568d4308f0f07ce3a4fe5140

FROM ${WP_CLI_IMAGE} AS wp_cli
FROM ${WORDPRESS_IMAGE}

ARG PCOV_VERSION=1.0.12

RUN apt-get update \
	&& apt-get install -y --no-install-recommends ${PHPIZE_DEPS} \
	&& pecl install "pcov-${PCOV_VERSION}" \
	&& docker-php-ext-enable pcov \
	&& apt-get purge -y --auto-remove ${PHPIZE_DEPS} \
	&& rm -rf /var/lib/apt/lists/*

COPY --from=wp_cli /usr/local/bin/wp /usr/local/bin/wp
