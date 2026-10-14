# Acı Tahta: derleme ve paketleme komutları
GO      ?= $(HOME)/.local/go/bin/go
VERSION := $(shell cat VERSION)

.PHONY: agent lint migrate release clean

agent: ## Windows ajanını derle
	cd agent && GOOS=windows GOARCH=amd64 $(GO) build -trimpath \
		-ldflags "-s -w" -o build/aci-ajan.exe ./cmd/aci-ajan

lint: ## Go ve PHP sözdizimi kontrolü
	cd agent && GOOS=windows $(GO) vet ./...
	find panel -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

migrate: ## Veritabanı güncellemelerini uygula
	php panel/tools/migrate.php

release: lint agent ## Ajanı panele kopyala (indirme sayfası bunu kullanır)
	mkdir -p panel/storage
	cp agent/build/aci-ajan.exe panel/storage/aci-ajan.exe
	sha256sum panel/storage/aci-ajan.exe

clean:
	rm -rf agent/build
