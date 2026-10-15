// BilgeceLabs değişikliği: Bu paket orijinalde WebView2Loader.dll'i exe içine
// gömüp gerektiğinde bellekten yüklüyordu (antivirüslerin şüpheli bulduğu
// desen). Burada yalnız exe'nin yanındaki, Microsoft imzalı WebView2Loader.dll
// tam yoluyla yüklenir; gömülü kopya ve bellekten yükleme kaldırıldı.
package webviewloader

import (
	"fmt"
	"os"
	"path/filepath"
	"unsafe"

	"golang.org/x/sys/windows"
)

func loaderPath() string {
	exe, err := os.Executable()
	if err != nil {
		return "WebView2Loader.dll"
	}
	return filepath.Join(filepath.Dir(exe), "WebView2Loader.dll")
}

var (
	nativeModule                                       = windows.NewLazyDLL(loaderPath())
	nativeCreate                                       = nativeModule.NewProc("CreateCoreWebView2EnvironmentWithOptions")
	nativeCompareBrowserVersions                       = nativeModule.NewProc("CompareBrowserVersions")
	nativeGetAvailableCoreWebView2BrowserVersionString = nativeModule.NewProc("GetAvailableCoreWebView2BrowserVersionString")
)

// CompareBrowserVersions will compare the 2 given versions and return:
//
//	-1 = v1 < v2
//	 0 = v1 == v2
//	 1 = v1 > v2
func CompareBrowserVersions(v1 string, v2 string) (int, error) {
	_v1, err := windows.UTF16PtrFromString(v1)
	if err != nil {
		return 0, err
	}
	_v2, err := windows.UTF16PtrFromString(v2)
	if err != nil {
		return 0, err
	}
	if err := nativeCompareBrowserVersions.Find(); err != nil {
		return 0, err
	}
	var result int32
	hr, _, _ := nativeCompareBrowserVersions.Call(uintptr(unsafe.Pointer(_v1)), uintptr(unsafe.Pointer(_v2)), uintptr(unsafe.Pointer(&result)))
	if hr != 0 {
		return 0, fmt.Errorf("CompareBrowserVersions returned HRESULT 0x%X", hr)
	}
	return int(result), nil
}

// GetAvailableCoreWebView2BrowserVersionString returns the version of the
// installed WebView2 runtime, or "" when no runtime is installed.
func GetAvailableCoreWebView2BrowserVersionString(browserExecutableFolder string) (string, error) {
	if err := nativeGetAvailableCoreWebView2BrowserVersionString.Find(); err != nil {
		return "", err
	}
	var folder *uint16
	if browserExecutableFolder != "" {
		var err error
		if folder, err = windows.UTF16PtrFromString(browserExecutableFolder); err != nil {
			return "", err
		}
	}
	var result *uint16
	hr, _, _ := nativeGetAvailableCoreWebView2BrowserVersionString.Call(uintptr(unsafe.Pointer(folder)), uintptr(unsafe.Pointer(&result)))
	defer windows.CoTaskMemFree(unsafe.Pointer(result))
	if hr != 0 {
		if hr&0xFFFF == uintptr(windows.ERROR_FILE_NOT_FOUND) {
			return "", nil
		}
		return "", fmt.Errorf("GetAvailableCoreWebView2BrowserVersionString returned HRESULT 0x%X", hr)
	}
	return windows.UTF16PtrToString(result), nil
}

// CreateCoreWebView2EnvironmentWithOptions calls the routine in WebView2Loader.dll.
func CreateCoreWebView2EnvironmentWithOptions(browserExecutableFolder, userDataFolder *uint16, environmentOptions uintptr, environmentCompletedHandle uintptr) (uintptr, error) {
	if err := nativeCreate.Find(); err != nil {
		return 0, fmt.Errorf("WebView2Loader.dll yüklenemedi: %w", err)
	}
	res, _, _ := nativeCreate.Call(
		uintptr(unsafe.Pointer(browserExecutableFolder)),
		uintptr(unsafe.Pointer(userDataFolder)),
		environmentOptions,
		environmentCompletedHandle,
	)
	return res, nil
}
