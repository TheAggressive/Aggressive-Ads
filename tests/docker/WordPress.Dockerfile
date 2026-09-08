ARG WP_CLI_IMAGE=wordpress:cli-2.12.0-php8.4@sha256:13d152baa3c9111882d05e8ef4c32b4c84019b1bf7bf66b042c6b45e7aaba81d
ARG WORDPRESS_IMAGE=wordpress:7.1-php8.4-apache@sha256:dd1d6ff323bae668ebbfb0fce91042e1af7ee8d1568d4308f0f07ce3a4fe5140

FROM ${WP_CLI_IMAGE} AS wp_cli
FROM ${WORDPRESS_IMAGE}

# pcov, built from source pinned by tag and verified by digest.
#
# **Not `pecl install`.** That resolves through pecl.php.net at build time,
# which is a second point of failure independent of everything else CI needs.
# On 2026-09-08 it answered "No releases available for package
# pecl.php.net/pcov" for long enough to defeat three retries, and every lane
# that needs this image went red with nothing wrong in the code — the worst
# shape of CI failure, because it looks like the change under review.
#
# GitHub is not a new dependency: no lane runs at all without it, so sourcing
# the extension there removes a failure mode rather than moving one.
#
# The digest is checked because the archive is generated rather than uploaded,
# so it is the half of this that can change without the tag changing. A
# mismatch fails the build loudly and on purpose: an unverified extension in
# the image that measures coverage is not a trade worth making. Bump both
# arguments together, and get the new digest with `sha256sum` over the URL
# below rather than from anything that quotes it.
ARG PCOV_VERSION=1.0.12
ARG PCOV_SHA256=fdd07cad8e2ff42f0c9f095d84aeef11dab0fde7a008805f61883cbcb1b3f12b

# `curl` and `ca-certificates` are installed but deliberately not purged with
# the build tools: either may already be part of the base image, and removing
# something the image shipped with is a larger change than the few megabytes
# it would save on a throwaway test image.
RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends ${PHPIZE_DEPS} ca-certificates curl; \
	pcov_url="https://github.com/krakjoe/pcov/archive/refs/tags/v${PCOV_VERSION}.tar.gz"; \
	curl --fail --silent --show-error --location --retry 3 --output /tmp/pcov.tar.gz "${pcov_url}"; \
	if ! echo "${PCOV_SHA256}  /tmp/pcov.tar.gz" | sha256sum --check --status; then \
		echo "WordPress.Dockerfile: checksum mismatch for ${pcov_url}" >&2; \
		echo "  expected ${PCOV_SHA256}" >&2; \
		echo "  actual   $(sha256sum /tmp/pcov.tar.gz | cut -d" " -f1)" >&2; \
		exit 1; \
	fi; \
	mkdir -p /tmp/pcov; \
	tar -xzf /tmp/pcov.tar.gz -C /tmp/pcov --strip-components=1; \
	cd /tmp/pcov; \
	phpize; \
	./configure --enable-pcov; \
	make -j"$(nproc)"; \
	make install; \
	docker-php-ext-enable pcov; \
	cd /; \
	rm -rf /tmp/pcov /tmp/pcov.tar.gz; \
	apt-get purge -y --auto-remove ${PHPIZE_DEPS}; \
	rm -rf /var/lib/apt/lists/*

# The build is worthless if the extension is not actually loadable, and a
# missing one would otherwise surface much later as coverage silently reading
# zero rather than as a failed build.
RUN php -r 'exit( extension_loaded( "pcov" ) ? 0 : 1 );'

COPY --from=wp_cli /usr/local/bin/wp /usr/local/bin/wp
