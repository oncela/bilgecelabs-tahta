//go:build windows

package main

import (
	"encoding/json"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"sync"
)

type Config struct {
	Server    string `json:"server"`
	EnrollKey string `json:"enroll_key"`
	DeviceID  int64  `json:"device_id"`
	Token     string `json:"token"`
	DevType   string `json:"dev_type"` // board / classroom / office / desk / other
	Room      string `json:"room"`     // konum/sınıf (ör. 7-B, Danışma)
	DevName   string `json:"dev_name"` // panelde görünecek ad (isteğe bağlı)
}

var (
	cfgMu        sync.Mutex
	logToConsole bool
)

func dataDir() string {
	pd := os.Getenv("ProgramData")
	if pd == "" {
		pd = `C:\ProgramData`
	}
	return filepath.Join(pd, "BilgeceLabs", "Tahta")
}

func configPath() string { return filepath.Join(dataDir(), "config.json") }

func loadConfig() (*Config, error) {
	b, err := os.ReadFile(configPath())
	if err != nil {
		return nil, err
	}
	var c Config
	if err := json.Unmarshal(b, &c); err != nil {
		return nil, fmt.Errorf("config bozuk: %w", err)
	}
	return &c, nil
}

func (c *Config) save() error {
	cfgMu.Lock()
	defer cfgMu.Unlock()
	b, err := json.MarshalIndent(c, "", "  ")
	if err != nil {
		return err
	}
	tmp := configPath() + ".tmp"
	if err := os.WriteFile(tmp, b, 0600); err != nil {
		return err
	}
	return os.Rename(tmp, configPath())
}

func setupLog() {
	if logToConsole {
		return
	}
	p := filepath.Join(dataDir(), "ajan.log")
	if st, err := os.Stat(p); err == nil && st.Size() > 5<<20 {
		os.Rename(p, p+".1")
	}
	f, err := os.OpenFile(p, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0600)
	if err == nil {
		log.SetOutput(f)
	}
}
