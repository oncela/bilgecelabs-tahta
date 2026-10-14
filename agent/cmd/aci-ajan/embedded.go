//go:build windows

package main

import (
	"bytes"
	"encoding/binary"
	"encoding/json"
	"errors"
	"io"
	"os"

	"golang.org/x/sys/windows"
)

// Panelden indirilen kurulum dosyasının sonuna sunucu adresi ve kayıt
// anahtarı eklenir: [exe][json][uzunluk uint64 LE]["ACICFG01"].
// Böylece her okul için ayrı derleme gerekmez.
const trailerMagic = "ACICFG01"

type embeddedConfig struct {
	Server string `json:"server"`
	Key    string `json:"key"`
}

func readEmbedded() (*embeddedConfig, error) {
	exe, err := os.Executable()
	if err != nil {
		return nil, err
	}
	f, err := os.Open(exe)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	st, err := f.Stat()
	if err != nil {
		return nil, err
	}
	if st.Size() < 16 {
		return nil, errors.New("gömülü ayar yok")
	}
	tail := make([]byte, 16)
	if _, err := f.ReadAt(tail, st.Size()-16); err != nil {
		return nil, err
	}
	if !bytes.Equal(tail[8:], []byte(trailerMagic)) {
		return nil, errors.New("gömülü ayar yok")
	}
	n := int64(binary.LittleEndian.Uint64(tail[:8]))
	if n <= 0 || n > 4096 || n > st.Size()-16 {
		return nil, errors.New("gömülü ayar bozuk")
	}
	buf := make([]byte, n)
	if _, err := io.ReadFull(io.NewSectionReader(f, st.Size()-16-n, n), buf); err != nil {
		return nil, err
	}
	var c embeddedConfig
	if err := json.Unmarshal(buf, &c); err != nil || c.Server == "" || c.Key == "" {
		return nil, errors.New("gömülü ayar bozuk")
	}
	return &c, nil
}

// installFromEmbedded çift tıklamayla kurulum: gerekirse yönetici izni ister.
func installFromEmbedded(c *embeddedConfig) {
	if !windows.GetCurrentProcessToken().IsElevated() {
		exe, _ := os.Executable()
		err := windows.ShellExecute(0, windows.StringToUTF16Ptr("runas"),
			windows.StringToUTF16Ptr(exe), windows.StringToUTF16Ptr("install-embedded"),
			nil, windows.SW_HIDE)
		if err != nil {
			msgBox("Kurulum için yönetici izni gerekiyor.", true)
		}
		return
	}
	if err := install(c.Server, c.Key); err != nil {
		msgBox("Kurulum başarısız:\n"+err.Error(), true)
		return
	}
	msgBox("Acı Okulları tahta ajanı kuruldu.\nTahta birkaç saniye içinde panelde görünecek.", false)
}

func msgBox(text string, isErr bool) {
	style := uint32(windows.MB_OK | windows.MB_ICONINFORMATION)
	if isErr {
		style = windows.MB_OK | windows.MB_ICONERROR
	}
	windows.MessageBox(0, windows.StringToUTF16Ptr(text),
		windows.StringToUTF16Ptr("Acı Okulları Kurulum"), style)
}
