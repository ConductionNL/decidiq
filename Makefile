# Makefile for nextcloud-decidesk development

# Create a relative symlink in the parent directory so Nextcloud can find the
# app by its ID (decidesk) even though the repo is cloned as nextcloud-decidesk.
# Nextcloud requires the directory name to match the <id> in appinfo/info.xml.
dev-link:
	@if [ -L ../decidesk ]; then \
		echo "Symlink ../decidesk already exists."; \
	else \
		ln -s nextcloud-decidesk ../decidesk && \
		echo "Created symlink: apps-extra/decidesk -> nextcloud-decidesk"; \
	fi

dev-unlink:
	@if [ -L ../decidesk ]; then \
		rm ../decidesk && echo "Removed symlink ../decidesk"; \
	else \
		echo "No symlink found at ../decidesk."; \
	fi

.PHONY: dev-link dev-unlink
