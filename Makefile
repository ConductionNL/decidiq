# Makefile for nextcloud-decidiq development

# Create a relative symlink in the parent directory so Nextcloud can find the
# app by its ID (decidiq) even though the repo is cloned as nextcloud-decidiq.
# Nextcloud requires the directory name to match the <id> in appinfo/info.xml.
dev-link:
	@if [ -L ../decidiq ]; then \
		echo "Symlink ../decidiq already exists."; \
	else \
		ln -s nextcloud-decidiq ../decidiq && \
		echo "Created symlink: apps-extra/decidiq -> nextcloud-decidiq"; \
	fi

dev-unlink:
	@if [ -L ../decidiq ]; then \
		rm ../decidiq && echo "Removed symlink ../decidiq"; \
	else \
		echo "No symlink found at ../decidiq."; \
	fi

.PHONY: dev-link dev-unlink
