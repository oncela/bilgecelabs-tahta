//go:build windows

package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"strings"
	"sync"
	"time"
)

type command struct {
	ID   int64             `json:"id"`
	Type string            `json:"type"`
	Args map[string]string `json:"args"`
}

type cmdResult struct {
	ID     int64  `json:"id"`
	OK     bool   `json:"ok"`
	Output string `json:"output"`
}

type pollRequest struct {
	Hostname  string          `json:"hostname"`
	User      string          `json:"user"`
	UptimeS   int64           `json:"uptime_s"`
	Version   string          `json:"version"`
	OS        string          `json:"os"`
	Defender  json.RawMessage `json:"defender,omitempty"`
	Inventory json.RawMessage `json:"inventory,omitempty"`
	Security  json.RawMessage `json:"security,omitempty"`
	Mesh      string          `json:"mesh_state,omitempty"`
	MeshNode  string          `json:"mesh_node,omitempty"`
	Metrics   *metrics        `json:"metrics,omitempty"`
	Results   []cmdResult     `json:"results"`
}

type pollResponse struct {
	Commands []command   `json:"commands"`
	Interval int         `json:"interval"`
	Mesh     *meshInfo   `json:"mesh"`
	Update   *updateInfo `json:"update"`
}

var errUnauthorized = errors.New("yetkisiz")

type agent struct {
	cfg    *Config
	http   *http.Client
	resMu  sync.Mutex
	result []cmdResult

	defMu      sync.Mutex
	defender   json.RawMessage
	defenderAt time.Time
	invMu      sync.Mutex
	inventory  json.RawMessage
	invAt      time.Time
	secMu      sync.Mutex
	security   json.RawMessage
	secAt      time.Time
	osName     string
	mesh       meshKeeper
	cpuIdle    uint64
	cpuTotal   uint64
}

func runAgent(stop <-chan struct{}) {
	setupLog()
	log.Printf("Acı Ajan %s başladı", version)
	cfg, err := loadConfig()
	if err != nil {
		log.Printf("config okunamadı: %v", err)
		<-stop
		return
	}
	a := &agent{
		cfg:  cfg,
		http: &http.Client{Timeout: 30 * time.Second},
	}
	a.osName = osVersion()
	cleanupOldBinary()

	interval := 10 * time.Second
	for {
		if a.cfg.Token == "" {
			if err := a.enroll(); err != nil {
				log.Printf("kayıt başarısız: %v", err)
				if sleepOrStop(stop, 30*time.Second) {
					return
				}
				continue
			}
		}
		if a.defenderDue() {
			go a.refreshDefender()
		}
		if a.inventoryDue() {
			go a.refreshInventory()
		}
		if a.securityDue() {
			go a.refreshSecurity()
		}
		resp, err := a.poll()
		switch {
		case errors.Is(err, errUnauthorized):
			log.Printf("token geçersiz, yeniden kayıt olunacak")
			a.cfg.Token = ""
			a.cfg.save()
		case err != nil:
			log.Printf("panel bağlantı hatası: %v", err)
		default:
			if resp.Interval >= 3 && resp.Interval <= 300 {
				interval = time.Duration(resp.Interval) * time.Second
			}
			go a.mesh.ensure(a, resp.Mesh)
			go a.maybeUpdate(resp.Update)
			for _, c := range resp.Commands {
				go a.run(c)
			}
		}
		if sleepOrStop(stop, interval) {
			return
		}
	}
}

func sleepOrStop(stop <-chan struct{}, d time.Duration) bool {
	select {
	case <-stop:
		return true
	case <-time.After(d):
		return false
	}
}

func (a *agent) run(c command) {
	log.Printf("komut #%d: %s", c.ID, c.Type)
	out, err := a.execute(c)
	r := cmdResult{ID: c.ID, OK: err == nil, Output: out}
	if err != nil {
		if r.Output != "" {
			r.Output += "\n"
		}
		r.Output += "HATA: " + err.Error()
	}
	if len(r.Output) > 64<<10 {
		r.Output = r.Output[:64<<10] + "\n…(kısaltıldı)"
	}
	a.resMu.Lock()
	a.result = append(a.result, r)
	a.resMu.Unlock()
}

func (a *agent) takeResults() []cmdResult {
	a.resMu.Lock()
	defer a.resMu.Unlock()
	r := a.result
	a.result = nil
	return r
}

func (a *agent) poll() (*pollResponse, error) {
	host, _ := os.Hostname()
	results := a.takeResults()
	req := pollRequest{
		Hostname: host,
		User:     activeUserName(),
		UptimeS:  uptimeSeconds(),
		Version:  version,
		OS:       a.osName,
		Results:  results,
	}
	a.defMu.Lock()
	if a.defender != nil {
		req.Defender = a.defender
		a.defender = nil // sadece değiştiğinde gönder
	}
	a.defMu.Unlock()
	a.invMu.Lock()
	if a.inventory != nil {
		req.Inventory = a.inventory
		a.inventory = nil
	}
	a.invMu.Unlock()
	a.secMu.Lock()
	if a.security != nil {
		req.Security = a.security
		a.security = nil
	}
	a.secMu.Unlock()

	req.Mesh = a.mesh.currentState()
	req.MeshNode = a.mesh.currentNodeID()
	mt := collectMetrics(&a.cpuIdle, &a.cpuTotal)
	req.Metrics = &mt

	var resp pollResponse
	err := a.post("/api/poll.php", req, &resp)
	if err != nil && len(results) > 0 && !errors.Is(err, errUnauthorized) {
		// Gönderilemeyen sonuçları kaybetme.
		a.resMu.Lock()
		a.result = append(results, a.result...)
		a.resMu.Unlock()
	}
	return &resp, err
}

func (a *agent) enroll() error {
	host, _ := os.Hostname()
	req := map[string]string{
		"enroll_key": a.cfg.EnrollKey,
		"hostname":   host,
		"mac":        primaryMAC(),
		"os":         osVersion(),
		"version":    version,
		"dev_type":   a.cfg.DevType,
		"room":       a.cfg.Room,
		"dev_name":   a.cfg.DevName,
	}
	var resp struct {
		DeviceID int64  `json:"device_id"`
		Token    string `json:"token"`
	}
	if err := a.post("/api/enroll.php", req, &resp); err != nil {
		return err
	}
	if resp.Token == "" {
		return errors.New("panel token vermedi")
	}
	a.cfg.DeviceID, a.cfg.Token = resp.DeviceID, resp.Token
	log.Printf("kayıt tamam, cihaz #%d", resp.DeviceID)
	return a.cfg.save()
}

func (a *agent) post(path string, body, out any) error {
	b, err := json.Marshal(body)
	if err != nil {
		return err
	}
	req, err := http.NewRequest("POST", strings.TrimRight(a.cfg.Server, "/")+path, bytes.NewReader(b))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("User-Agent", "BilgeceTahta/"+version)
	if a.cfg.Token != "" {
		req.Header.Set("Authorization", "Bearer "+a.cfg.Token)
	}
	res, err := a.http.Do(req)
	if err != nil {
		return err
	}
	defer res.Body.Close()
	data, _ := io.ReadAll(io.LimitReader(res.Body, 4<<20))
	if res.StatusCode == http.StatusUnauthorized {
		return errUnauthorized
	}
	if res.StatusCode != http.StatusOK {
		return fmt.Errorf("HTTP %d: %s", res.StatusCode, strings.TrimSpace(string(data)))
	}
	return json.Unmarshal(data, out)
}

func (a *agent) defenderDue() bool {
	a.defMu.Lock()
	defer a.defMu.Unlock()
	if time.Since(a.defenderAt) < 15*time.Minute {
		return false
	}
	a.defenderAt = time.Now()
	return true
}

func (a *agent) refreshDefender() {
	out, err := runPowerShell(`$s = Get-MpComputerStatus
# Yalnız hâlâ etkin tehditler (temizlenen/karantinadakiler sayılmaz); geçmiş ayrıca
$t = @(Get-MpThreat -ErrorAction SilentlyContinue | Where-Object { $_.IsActive }).Count
$h = @(Get-MpThreatDetection -ErrorAction SilentlyContinue).Count
[pscustomobject]@{
  enabled      = [bool]$s.AntivirusEnabled
  realtime     = [bool]$s.RealTimeProtectionEnabled
  sig_age_days = [int]$s.AntivirusSignatureAge
  quick_scan_age_days = [int]$s.QuickScanAge
  threats      = [int]$t
  threats_history = [int]$h
} | ConvertTo-Json -Compress`, 2*time.Minute)
	out = strings.TrimSpace(out)
	if err != nil || !json.Valid([]byte(out)) {
		out = fmt.Sprintf(`{"error":%q}`, strings.TrimSpace(out+" "+fmt.Sprint(err)))
	}
	a.defMu.Lock()
	a.defender = json.RawMessage(out)
	a.defMu.Unlock()
}

func primaryMAC() string {
	ifs, _ := net.Interfaces()
	for _, i := range ifs {
		if i.Flags&net.FlagLoopback != 0 || i.Flags&net.FlagUp == 0 || len(i.HardwareAddr) == 0 {
			continue
		}
		addrs, _ := i.Addrs()
		if len(addrs) > 0 {
			return i.HardwareAddr.String()
		}
	}
	return ""
}
