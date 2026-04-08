# VibeFw Framework Makefile
# Quick commands for common development tasks

.PHONY: help install test lint fix analyse validate security serve migrate fresh clean

# Default target
help:
	@echo "VibeFw Framework - Development Commands"
	@echo ""
	@echo "Setup:"
	@echo "  make install      Install dependencies"
	@echo "  make setup        Full setup (install + migrate)"
	@echo ""
	@echo "Development:"
	@echo "  make serve        Start development server"
	@echo "  make migrate      Run database migrations"
	@echo "  make fresh        Fresh migrate (drop all + re-run)"
	@echo ""
	@echo "Testing:"
	@echo "  make test         Run all tests"
	@echo "  make test-unit    Run unit tests only"
	@echo "  make test-arch    Run architecture tests"
	@echo "  make coverage     Run tests with coverage"
	@echo "  make mutation     Run mutation testing"
	@echo ""
	@echo "Code Quality:"
	@echo "  make lint         Check code style"
	@echo "  make fix          Fix code style issues"
	@echo "  make analyse      Run static analysis"
	@echo "  make security     Run security scan"
	@echo "  make validate     Run ALL validation checks"
	@echo ""
	@echo "Utilities:"
	@echo "  make clean        Clear caches"
	@echo "  make routes       List all routes"

# Setup
install:
	composer install

setup: install
	cp -n .env.example .env || true
	php fw migrate

# Development
serve:
	php fw serve

migrate:
	php fw migrate

fresh:
	php fw migrate:fresh

# Testing
test:
	php fw test

test-unit:
	php fw test --testsuite=unit

test-arch:
	php fw test --testsuite=architecture

coverage:
	php fw test --coverage

mutation:
	vendor/bin/infection --threads=4

# Code Quality
lint:
	vendor/bin/php-cs-fixer fix --dry-run --diff

fix:
	vendor/bin/php-cs-fixer fix

analyse:
	vendor/bin/phpstan analyse

security:
	php fw validate:security

validate:
	php fw validate:all

# CI (runs everything)
ci: lint analyse security test

# Utilities
clean:
	php fw cache:clear

routes:
	php fw routes:list

# Git hooks
hooks:
	cp .hooks/pre-commit .git/hooks/pre-commit
	chmod +x .git/hooks/pre-commit
	@echo "Pre-commit hook installed!"
