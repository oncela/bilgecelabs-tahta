# Acı Tahta: derleme ve paketleme komutları
GO      ?= $(HOME)/.local/go/bin/go
VERSION := $(shell cat VERSION)

.PHONY: agent lint migrate release clean theme theme-deploy check

agent: ## Windows ajanını derle (sürüm bilgisi ve manifest dahil)
	cd agent/cmd/aci-ajan && $(HOME)/go/bin/go-winres make --arch amd64 \
		--product-version $(VERSION).0 --file-version $(VERSION).0
	cd agent && GOOS=windows GOARCH=amd64 $(GO) build -trimpath \
		-ldflags "-H windowsgui -X main.version=$(VERSION)" -o build/bilgece-tahta.exe ./cmd/aci-ajan

lint: ## Go ve PHP sözdizimi kontrolü
	cd agent && GOOS=windows $(GO) vet ./...
	find panel -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

migrate: ## Veritabanı güncellemelerini uygula
	php panel/tools/migrate.php

release: lint agent ## Ajanı panele kopyala (indirme sayfası bunu kullanır)
	mkdir -p panel/storage
	cp agent/build/bilgece-tahta.exe panel/storage/aci-ajan.exe
	echo "$(VERSION)" > panel/storage/aci-ajan.version
	cp theme/brand/favicon.ico installer/bilgece.ico
	python3 installer/make-splash.py
	cd installer && makensis -DAGENT=../agent/build/bilgece-tahta.exe -DVERSION=$(VERSION) bilgece-tahta.nsi >/dev/null
	cp installer/bilgece-tahta-kurulum.exe panel/storage/kurulum.exe
	sha256sum panel/storage/aci-ajan.exe panel/storage/kurulum.exe

clean:
	rm -rf agent/build

theme: ## Tema dosyalarını tek kaynaktan (theme/src) üret
	cat theme/src/tokens.css theme/src/panel.css > panel/public/assets/aci.css
	mkdir -p theme/meshcentral/public/styles
	cat theme/src/tokens.css theme/src/meshcentral.css > theme/meshcentral/public/styles/theme.css
	mkdir -p theme/meshcentral/public/scripts
	cp theme/src/meshcentral.js theme/meshcentral/public/scripts/theme.js

theme-deploy: theme ## MeshCentral temasını sunucuya kopyala
	sudo mkdir -p /opt/meshcentral/meshcentral-data/theme-pack/aci
	sudo cp -r theme/meshcentral/public /opt/meshcentral/meshcentral-data/theme-pack/aci/
	sudo chown -R meshcentral:meshcentral /opt/meshcentral/meshcentral-data/theme-pack

check: lint ## Sözdizimi + uçtan uca duman testi (ACI_TEST_* ortam değişkenleri gerekli)
	/opt/node/bin/node --check panel/public/assets/app.js
	bash panel/tests/smoke.sh
	bash panel/tests/features.sh
