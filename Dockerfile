# Moodle 4.5 LTS on PHP 8.3.
#
# This replaced ellakcy/moodle, which was frozen on PHP 7.4 and Moodle 4.0 and so
# could not take the PHP2Shell fixes (CVE-2026-109618, CVE-2026-109619). Both
# versions are pinned: PHP 8.3.36 is the first 8.3 release with those fixes, and
# the Moodle tarball is checked against its published checksum.
#
# The Moodle code lives in the image, not in a volume. The old image copied its
# code into a volume on every start, which both shadowed upgrades and silently
# reverted the changes made to mod_book. The two plugins are bind-mounted from
# ./plugins by docker-compose instead, so edits there are live without a rebuild.
FROM php:8.3.36-apache

ARG MOODLE_VERSION=4.5.15
ARG MOODLE_SHA256=f0e09f636c2900806bf208dffdc462f3b6b0ad24163559e9aa9b4f339ddc2096

# Nothing here reads PDFs: every page goes through the docling service. Ghostscript
# only draws a page as a picture, for a scanned contents page whose watermark is
# real text: Docling drops every OCR reading under it, but reads a picture of the
# same page cleanly.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libpq-dev libicu-dev libzip-dev libxml2-dev \
      libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
      ghostscript \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pgsql intl zip gd soap exif opcache \
 && rm -rf /var/lib/apt/lists/*

# Moodle 4.5 refuses PHP 8 with fewer than 5000 input variables.
RUN printf '%s\n' \
      'max_input_vars = 5000' \
      'opcache.memory_consumption = 128' \
      'opcache.max_accelerated_files = 10000' \
    > /usr/local/etc/php/conf.d/moodle.ini

RUN curl -fsSL -o /tmp/moodle.tgz \
      "https://download.moodle.org/download.php/direct/stable405/moodle-${MOODLE_VERSION}.tgz" \
 && echo "${MOODLE_SHA256}  /tmp/moodle.tgz" | sha256sum -c - \
 && rm -rf /var/www/html \
 && tar xzf /tmp/moodle.tgz -C /var/www \
 && mv /var/www/moodle /var/www/html \
 && rm /tmp/moodle.tgz

# Every value comes from the environment set in docker-compose.yaml.
COPY config.php /var/www/html/config.php

RUN mkdir -p /var/moodledata \
 && chown -R www-data:www-data /var/www/html /var/moodledata
