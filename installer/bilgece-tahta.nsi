; BilgeceLabs Tahta kurulum sihirbazı (NSIS)
; Derleme: makensis -DAGENT=<yol> -DVERSION=<sürüm> bilgece-tahta.nsi
; Kayıt anahtarı kurulum dosyasının adından okunur: BilgeceTahtaKurulum_<anahtar>.exe

Unicode true
!include "MUI2.nsh"
!include "LogicLib.nsh"
!include "nsDialogs.nsh"
!include "FileFunc.nsh"

!ifndef VERSION
  !define VERSION "0.0.0"
!endif
!ifndef AGENT
  !error "AGENT tanımlı değil (ajan exe yolu)"
!endif

Name "BilgeceLabs Tahta"
BrandingText "BilgeceLabs"
OutFile "bilgece-tahta-kurulum.exe"
InstallDir "$PROGRAMFILES64\BilgeceLabs\Tahta"
RequestExecutionLevel admin
ShowInstDetails nevershow
VIProductVersion "${VERSION}.0"
VIAddVersionKey "ProductName" "BilgeceLabs Tahta"
VIAddVersionKey "CompanyName" "BilgeceLabs"
VIAddVersionKey "FileDescription" "BilgeceLabs Tahta Kurulum"
VIAddVersionKey "LegalCopyright" "© 2026 BilgeceLabs"
VIAddVersionKey "FileVersion" "${VERSION}.0"
VIAddVersionKey "ProductVersion" "${VERSION}.0"

!define MUI_ICON "bilgece.ico"
!define MUI_UNICON "bilgece.ico"
; Üst şeritte BilgeceLabs logosu
!define MUI_HEADERIMAGE
!define MUI_HEADERIMAGE_RIGHT
!define MUI_HEADERIMAGE_BITMAP "baslik.bmp"
!define MUI_HEADERIMAGE_UNBITMAP "baslik.bmp"
!define MUI_INSTFILESPAGE_COLORS "1F63D6 FFFFFF"
!define MUI_INSTFILESPAGE_PROGRESSBAR "smooth"
; Tek ekran: soru sorulmaz, ilerleme biter bitmez pencere kendiliğinden kapanır
!define MUI_PAGE_HEADER_TEXT "BilgeceLabs Tahta kuruluyor"
!define MUI_PAGE_HEADER_SUBTEXT "Lütfen bekleyin, birkaç saniye sürer…"
!define MUI_INSTFILESPAGE_FINISHHEADER_TEXT "Kurulum tamamlandı"
!define MUI_INSTFILESPAGE_FINISHHEADER_SUBTEXT "Cihaz panele bağlanıyor."
AutoCloseWindow true

; Cihaz türü ve konum sorulmaz (panelden atanır). Toplu kurulum için isteğe bağlı
; komut satırı: kurulum.exe /S /TUR=classroom /KONUM=7-B
Var DevType
Var Room

!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_LANGUAGE "Turkish"

Function .onInit
  ${GetParameters} $R0
  ${GetOptions} $R0 "/TUR=" $DevType
  ${GetOptions} $R0 "/KONUM=" $Room
  ; Açılış: BilgeceLabs logosu yumuşak geçişle (sessiz kurulumda gösterilmez)
  ${IfNot} ${Silent}
    InitPluginsDir
    File "/oname=$PLUGINSDIR\splash.bmp" "splash.bmp"
    advsplash::show 1200 600 500 -1 "$PLUGINSDIR\splash"
    Pop $0
  ${EndIf}
FunctionEnd

Section "Install"
  SetOutPath "$INSTDIR"
  ; Önceki kurulum çalışıyorsa durdur ve çalışan programı kenara al:
  ; çalışan exe kilitlidir, üzerine yazılamaz (ajan .eski dosyasını açılışta siler).
  nsExec::Exec 'sc.exe stop BilgeceTahta'
  Pop $0
  Sleep 3000
  Delete "$INSTDIR\bilgece-tahta.exe.eski"
  Rename "$INSTDIR\bilgece-tahta.exe" "$INSTDIR\bilgece-tahta.exe.eski"
  File "/oname=bilgece-tahta.exe" "${AGENT}"

  ; Kayıt anahtarı: kurulum exe adından (BilgeceTahtaKurulum_<anahtar>.exe)
  ${GetFileName} "$EXEPATH" $0        ; dosya adı
  StrCpy $1 ""
  StrLen $2 $0
  ; "_" sonrası 32 karakteri al
  StrCpy $3 0
  loop:
    IntCmp $3 $2 done2 0 done2
    StrCpy $4 $0 1 $3
    StrCmp $4 "_" 0 +3
      IntOp $5 $3 + 1
      StrCpy $1 $0 32 $5
    IntOp $3 $3 + 1
    Goto loop
  done2:

  DetailPrint "Ajan kuruluyor…"
  SetDetailsPrint textonly
  StrCpy $6 ""
  ${If} $1 != ""
    StrCpy $6 "$6 --key $1"
  ${EndIf}
  ${If} $DevType != ""
    StrCpy $6 "$6 --type $DevType"
  ${EndIf}
  ${If} $Room != ""
    StrCpy $6 '$6 --room "$Room"'
  ${EndIf}
  nsExec::Exec '"$INSTDIR\bilgece-tahta.exe" install$6'
  Pop $0

  ; Programlar ve Özellikler kaydı
  WriteUninstaller "$INSTDIR\kaldir.exe"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "DisplayName" "BilgeceLabs Tahta"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "DisplayVersion" "${VERSION}"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "Publisher" "BilgeceLabs"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "DisplayIcon" "$INSTDIR\bilgece-tahta.exe"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "UninstallString" '"$INSTDIR\kaldir.exe"'
  WriteRegDWORD HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "NoModify" 1
  WriteRegDWORD HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta" "NoRepair" 1

  ; Kurulum bitti: "tamamlandı" ekranını 5 sn göster (fade in/out dahil)
  DetailPrint "Cihaz panele bağlanıyor…"
  ${IfNot} ${Silent}
    File "/oname=$PLUGINSDIR\splash-bitti.bmp" "splash-bitti.bmp"
    advsplash::show 5000 600 600 -1 "$PLUGINSDIR\splash-bitti"
    Pop $0
  ${EndIf}
SectionEnd

Section "Uninstall"
  nsExec::ExecToLog '"$INSTDIR\bilgece-tahta.exe" uninstall'
  Pop $0
  DeleteRegKey HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BilgeceTahta"
  Delete "$INSTDIR\kaldir.exe"
  Delete "$INSTDIR\bilgece-tahta.exe"
  RMDir "$INSTDIR"
SectionEnd
