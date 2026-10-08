# Moodle, with the PDF tools the plugins depend on.
#
# The plugins shell out to Poppler to read the textbook, to cut page ranges out
# of it and to read the generated decks back. Installing it here rather than by
# hand into a running container means it survives the container being rebuilt.
# It was once installed by hand, and vanished the moment Compose recreated the
# container, taking lecture generation with it.
FROM ellakcy/moodle:postgresql_apache

# Debian 11's security pool has been archived and no longer serves the packages
# its index advertises, so these are taken from the main release instead.
RUN apt-get update \
 && apt-get install -y --no-install-recommends -t bullseye poppler-utils \
 && rm -rf /var/lib/apt/lists/*
