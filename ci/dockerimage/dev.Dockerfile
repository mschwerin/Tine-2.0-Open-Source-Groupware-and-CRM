# description:
#   Image for dev docker setup. Baseimage with xdegug, npm, webpack and composer.
#
# build:
#   $ docker build [...] --build-arg='BASE_IMAGE=base-tag'
#
# ARGS:
#   BASE_IMAGE=base
#   ALPINE_PHP_REPOSITORY_VERSION=v3.12 from which alpine versions repository php should be installed
ARG BASE_IMAGE=base

#  -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -
# using alpine:3.12 as source here could be a problem, if a packet is not updated duto a version conflict
FROM ${BASE_IMAGE} as cache-invalidator
ARG ALPINE_PHP_REPOSITORY_BRANCH=v3.12
ARG ALPINE_PHP_REPOSITORY_REPOSITROY=main
ARG ALPINE_PHP_PACKAGE=php7

RUN apk add --no-cache --simulate nodejs npm git | sha256sum >> /cachehash
RUN apk add --no-cache --simulate --repository http://nl.alpinelinux.org/alpine/${ALPINE_PHP_REPOSITORY_VERSION}/${ALPINE_PHP_REPOSITORY_REPOSITROY} ${ALPINE_PHP_PACKAGE}-pecl-xdebug \
                                  | sha256sum >> /cachehash
RUN if [ ${ALPINE_PHP_PACKAGE} != php8 ]; then \
        apk add --no-cache --simulate --repository http://nl.alpinelinux.org/alpine/${ALPINE_PHP_REPOSITORY_BRANCH}/community \
        composer | sha256sum >> /cachehash; \
    fi

#  -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -    -
FROM ${BASE_IMAGE} as dev
ARG ALPINE_PHP_REPOSITORY_BRANCH=v3.12
ARG ALPINE_PHP_REPOSITORY_REPOSITORY=main
ARG ALPINE_PHP_PACKAGE=php7

COPY --from=cache-invalidator /cachehash /usr/local/lib/container/
RUN apk add --no-cache nodejs npm git

RUN if [ ${ALPINE_PHP_PACKAGE} == php8 ]; then \
        php -r "copy('https://getcomposer.org/installer', '/composer-setup.php');"; \
        php -r "if (hash_file('sha384', '/composer-setup.php') === '756890a4488ce9024fc62c56153228907f1545c228516cbf63f885e036d37e9a59d27d63f46af1d4d07ee0f76181c7d3') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"; \
        php /composer-setup.php; \
        php -r "unlink('/composer-setup.php');"; \
        ln -s /usr/share/composer.phar /usr/bin/composer; \
    else \
        apk add --no-cache --repository http://nl.alpinelinux.org/alpine/${ALPINE_PHP_REPOSITORY_BRANCH}/community composer; \
    fi


RUN apk add --no-cache --repository http://nl.alpinelinux.org/alpine/${ALPINE_PHP_REPOSITORY_BRANCH}/${ALPINE_PHP_REPOSITORY_REPOSITORY} ${ALPINE_PHP_PACKAGE}-pecl-xdebug