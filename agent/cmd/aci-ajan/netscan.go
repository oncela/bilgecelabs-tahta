//go:build windows

package main

import (
	"context"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"net"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"
)

// netScan tahtanın bağlı olduğu yerel ağları tarar ve cevap veren
// cihazları (IP, MAC, ad) JSON olarak döner. Her IP'ye küçük bir UDP
// paketi gönderilir; bu, Windows'un ARP tablosunu doldurur.
func netScan() (string, error) {
	subnets := localSubnets()
	if len(subnets) == 0 {
		return "", fmt.Errorf("taranacak yerel ağ bulunamadı")
	}
	sem := make(chan struct{}, 128)
	var wg sync.WaitGroup
	for _, n := range subnets {
		for _, ip := range hostsIn(n) {
			wg.Add(1)
			sem <- struct{}{}
			go func(ip string) {
				defer wg.Done()
				defer func() { <-sem }()
				if c, err := net.DialTimeout("udp", ip+":9", time.Second); err == nil {
					c.Write([]byte{0})
					c.Close()
				}
			}(ip)
		}
	}
	wg.Wait()
	time.Sleep(3 * time.Second) // ARP cevaplarının gelmesini bekle

	out, err := runCmd(30*time.Second, "arp.exe", "-a")
	if err != nil {
		return out, err
	}
	type dev struct {
		IP   string `json:"ip"`
		MAC  string `json:"mac"`
		Name string `json:"name,omitempty"`
	}
	re := regexp.MustCompile(`(?i)(\d+\.\d+\.\d+\.\d+)\s+([0-9a-f]{2}(?:-[0-9a-f]{2}){5})`)
	seen := map[string]bool{}
	var devs []dev
	for _, m := range re.FindAllStringSubmatch(out, -1) {
		ip, mac := m[1], strings.ToLower(strings.ReplaceAll(m[2], "-", ":"))
		if seen[ip] || mac == "ff:ff:ff:ff:ff:ff" || strings.HasPrefix(mac, "01:00:5e") || !inAny(ip, subnets) {
			continue
		}
		seen[ip] = true
		devs = append(devs, dev{IP: ip, MAC: mac})
	}
	// Tahtanın kendisi ARP tablosunda görünmez, ekle.
	for _, s := range selfAddrs(subnets) {
		if !seen[s.IP] {
			devs = append(devs, dev{IP: s.IP, MAC: s.MAC})
		}
	}
	// Ters DNS ile isim bul (kısa zaman aşımıyla, paralel).
	var mu sync.Mutex
	for i := range devs {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
			defer cancel()
			if names, err := net.DefaultResolver.LookupAddr(ctx, devs[i].IP); err == nil && len(names) > 0 {
				mu.Lock()
				devs[i].Name = strings.TrimSuffix(names[0], ".")
				mu.Unlock()
			}
		}(i)
	}
	wg.Wait()
	sort.Slice(devs, func(i, j int) bool { return ipLess(devs[i].IP, devs[j].IP) })

	var subs []string
	for _, n := range subnets {
		subs = append(subs, n.String())
	}
	b, err := json.Marshal(map[string]any{"subnets": subs, "devices": devs})
	return string(b), err
}

// localSubnets: özel IPv4 ağları, en fazla /22 (1024 adres).
func localSubnets() []*net.IPNet {
	var res []*net.IPNet
	ifs, _ := net.Interfaces()
	for _, i := range ifs {
		if i.Flags&net.FlagUp == 0 || i.Flags&net.FlagLoopback != 0 {
			continue
		}
		addrs, _ := i.Addrs()
		for _, a := range addrs {
			n, ok := a.(*net.IPNet)
			if !ok || n.IP.To4() == nil || !n.IP.IsPrivate() {
				continue
			}
			ones, _ := n.Mask.Size()
			if ones < 22 {
				ones = 24 // çok büyük ağlarda sadece kendi /24'ünü tara
			}
			_, sub, _ := net.ParseCIDR(fmt.Sprintf("%s/%d", n.IP, ones))
			res = append(res, sub)
		}
	}
	return res
}

func hostsIn(n *net.IPNet) []string {
	ones, bits := n.Mask.Size()
	base := binary.BigEndian.Uint32(n.IP.To4())
	size := uint32(1) << uint(bits-ones)
	var ips []string
	for k := uint32(1); k+1 < size; k++ {
		b := make(net.IP, 4)
		binary.BigEndian.PutUint32(b, base+k)
		ips = append(ips, b.String())
	}
	return ips
}

func inAny(ip string, subs []*net.IPNet) bool {
	p := net.ParseIP(ip)
	for _, s := range subs {
		if s.Contains(p) {
			return true
		}
	}
	return false
}

type selfAddr struct{ IP, MAC string }

func selfAddrs(subs []*net.IPNet) []selfAddr {
	var res []selfAddr
	ifs, _ := net.Interfaces()
	for _, i := range ifs {
		addrs, _ := i.Addrs()
		for _, a := range addrs {
			if n, ok := a.(*net.IPNet); ok && n.IP.To4() != nil && inAny(n.IP.String(), subs) {
				res = append(res, selfAddr{n.IP.String(), i.HardwareAddr.String()})
			}
		}
	}
	return res
}

func ipLess(a, b string) bool {
	x, y := net.ParseIP(a).To4(), net.ParseIP(b).To4()
	if x == nil || y == nil {
		return a < b
	}
	return binary.BigEndian.Uint32(x) < binary.BigEndian.Uint32(y)
}
