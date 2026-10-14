# Acı Tahta: derleme ve paketleme komutları
GO      ?= $(HOME)/.local/go/bin/go
VERSION := $(shell cat VERSION)

.PHONY: agent lint migrate release clean theme theme-deploy

agent: ## Windows ajanını derle
	cd agent && GOOS=windows GOARCH=amd64 $(GO) build -trimpath \
		-ldflags "-s -w -X main.version=$(VERSION)" -o build/aci-ajan.exe ./cmd/aci-ajan

lint: ## Go ve PHP sözdizimi kontrolü
	cd agent && GOOS=windows $(GO) vet ./...
	find panel -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

migrate: ## Veritabanı güncellemelerini uygula
	php panel/tools/migrate.php

release: lint agent ## Ajanı panele kopyala (indirme sayfası bunu kullanır)
	mkdir -p panel/storage
	cp agent/build/aci-ajan.exe panel/storage/aci-ajan.exe
	echo "$(VERSION)" > panel/storage/aci-ajan.version
	sha256sum panel/storage/aci-ajan.exe

clean:
	rm -rf agent/build

theme: ## Tema dosyalarını tek kaynaktan (theme/src) üret
	cat theme/src/tokens.css theme/src/panel.css > panel/public/assets/aci.css
	mkdir -p theme/meshcentral/public/styles
	cat theme/src/tokens.css theme/src/meshcentral.css > theme/meshcentral/public/styles/theme.css

theme-deploy: theme ## MeshCentral temasını sunucuya kopyala
	sudo mkdir -p /opt/meshcentral/meshcentral-data/theme-pack/aci
	sudo cp -r theme/meshcentral/public /opt/meshcentral/meshcentral-data/theme-pack/aci/
	sudo chown -R meshcentral:meshcentral /opt/meshcentral/meshcentral-data/theme-pack
