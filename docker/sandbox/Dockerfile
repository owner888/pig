# The image a Docker account's container is made from (docs/dev/design/storage.md, phase 4):
#
#   docker build -t pig-sandbox docker/sandbox        # from a checkout of pig
#   docker build -t pig-sandbox "$(composer global config home)/vendor/pigagent/pig/docker/sandbox"
#
# pig never pulls or builds it: an account's first conversation would otherwise wait on it. What
# pig's tools need in it: bash, coreutils, findutils, util-linux's `setsid`, `rg` and `fd`. The rest
# is for the people who work in it; add what they use, rebuild, and remove their containers
# (`docker rm -f pig-<id>`) for the next conversation to make them again from the new image.
FROM debian:stable-slim

# No terminal while building: apt's questions take their defaults, without debconf trying three
# frontends first and saying so. ARG rather than ENV, so the accounts' shells do not inherit it.
ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash coreutils findutils util-linux procps \
        ripgrep fd-find \
        ca-certificates curl git openssh-client \
        python3 python3-pip python3-venv \
        php-cli php-mbstring php-xml php-curl php-zip composer \
        nodejs npm \
        build-essential \
    && ln -s "$(command -v fdfind)" /usr/local/bin/fd \
    && rm -rf /var/lib/apt/lists/*

# The account's container runs as the server's own uid, which has no entry here: a home and a
# name come from its directory (`HOME` is set to it) rather than from /etc/passwd.
ENV LANG=C.UTF-8
