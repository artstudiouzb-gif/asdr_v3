# Makefile for ArtStudio CMS (asdr)

.PHONY: help test lint analyse check smoke

help:
	@echo "ArtStudio CMS Development Commands:"
	@echo "  make test      Run PHP unit test suite"
	@echo "  make lint      Run PHP syntax check across app/ directory"
	@echo "  make analyse   Run PHPStan static analysis"
	@echo "  make smoke     Run smoke test suite (scripts/smoke.php)"
	@echo "  make check     Run lint + analyse + test"

test:
	php tests/run.php

lint:
	@composer lint

analyse:
	@composer analyse

smoke:
	php scripts/smoke.php

check:
	@composer check
