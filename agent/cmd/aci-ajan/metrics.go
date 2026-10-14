//go:build windows

package main

import (
	"unsafe"

	"golang.org/x/sys/windows"
)

// Tahtanın anlık kaynak durumu (her bağlantıda panele gider).
type metrics struct {
	CPU      int    `json:"cpu"`       // yüzde (0-100)
	MemUsed  int    `json:"mem_used"`  // yüzde
	MemTotMB int    `json:"mem_mb"`    // toplam RAM (MB)
	DiskUsed int    `json:"disk_used"` // C: yüzde
	DiskFree int    `json:"disk_free"` // C: boş (GB)
	DiskTot  int    `json:"disk_gb"`   // C: toplam (GB)
	DiskName string `json:"disk_name"` // "C:"
}

var (
	procGlobalMemoryStatusEx = kernel32.NewProc("GlobalMemoryStatusEx")
	procGetDiskFreeSpaceExW  = kernel32.NewProc("GetDiskFreeSpaceExW")
	procGetSystemTimes       = kernel32.NewProc("GetSystemTimes")
)

type memoryStatusEx struct {
	Length               uint32
	MemoryLoad           uint32
	TotalPhys            uint64
	AvailPhys            uint64
	TotalPageFile        uint64
	AvailPageFile        uint64
	TotalVirtual         uint64
	AvailVirtual         uint64
	AvailExtendedVirtual uint64
}

// collectMetrics anlık RAM ve disk; CPU iki ölçüm arası fark gerektirdiği için
// agent tarafından saklanan önceki değerle hesaplanır.
func collectMetrics(prevIdle, prevTotal *uint64) metrics {
	var m metrics

	var ms memoryStatusEx
	ms.Length = uint32(unsafe.Sizeof(ms))
	if r, _, _ := procGlobalMemoryStatusEx.Call(uintptr(unsafe.Pointer(&ms))); r != 0 {
		m.MemUsed = int(ms.MemoryLoad)
		m.MemTotMB = int(ms.TotalPhys / (1024 * 1024))
	}

	var freeBytes, totalBytes, totalFree uint64
	cDrive, _ := windows.UTF16PtrFromString(`C:\`)
	if r, _, _ := procGetDiskFreeSpaceExW.Call(
		uintptr(unsafe.Pointer(cDrive)),
		uintptr(unsafe.Pointer(&freeBytes)),
		uintptr(unsafe.Pointer(&totalBytes)),
		uintptr(unsafe.Pointer(&totalFree)),
	); r != 0 && totalBytes > 0 {
		const gb = 1024 * 1024 * 1024
		m.DiskName = "C:"
		m.DiskTot = int(totalBytes / gb)
		m.DiskFree = int(totalFree / gb)
		used := totalBytes - totalFree
		m.DiskUsed = int(used * 100 / totalBytes)
	}

	// CPU: GetSystemTimes idle/kernel/user; kullanım = 1 - (idle farkı / toplam fark)
	var idle, kernel, user windows.Filetime
	if r, _, _ := procGetSystemTimes.Call(
		uintptr(unsafe.Pointer(&idle)),
		uintptr(unsafe.Pointer(&kernel)),
		uintptr(unsafe.Pointer(&user)),
	); r != 0 {
		idleT := ftToUint64(idle)
		totalT := ftToUint64(kernel) + ftToUint64(user) // kernel idle'ı içerir
		if *prevTotal != 0 {
			dIdle := idleT - *prevIdle
			dTotal := totalT - *prevTotal
			if dTotal > 0 {
				m.CPU = int(100 - (dIdle*100)/dTotal)
				if m.CPU < 0 {
					m.CPU = 0
				}
				if m.CPU > 100 {
					m.CPU = 100
				}
			}
		}
		*prevIdle, *prevTotal = idleT, totalT
	}
	return m
}

func ftToUint64(ft windows.Filetime) uint64 {
	return uint64(ft.HighDateTime)<<32 | uint64(ft.LowDateTime)
}
